from __future__ import annotations

import json
import logging
import os
import shutil

from fastapi import APIRouter, BackgroundTasks, Depends, HTTPException, status
from pydantic import BaseModel

from app.auth import require_auth
from app.config import Settings, get_settings
from app.services.mysql_ops import (
    backup_mysql_data,
    compare_snapshots,
    get_mysql_version_from_env,
    get_snapshot,
    restore_mysql_data,
    set_mysql_version_in_env,
    update_version_json,
    wait_for_mysql_ready,
)
from app.services.panel_ops import (
    compose_stop,
    compose_up,
    run_cmd,
)
from app.services.task_manager import TaskStatus, task_manager

logger = logging.getLogger(__name__)

router = APIRouter(tags=["mysql"])


class PrepareRequest(BaseModel):
    target_version: str


async def _perform_prepare(task_id: str, target_version: str, settings: Settings) -> None:
    """Backup MySQL data and validate before in-place upgrade."""
    project_root = settings.project_root
    user = settings.mysql_user if settings.mysql_password else "root"
    password = settings.mysql_password if settings.mysql_password else settings.mysql_root_password
    host = settings.mysql_host
    port = settings.mysql_port
    staging_dir = os.path.join(project_root, "mysql", "upgrade-staging")

    try:
        # Phase 1: Pre-flight checks
        task_manager.update_task(task_id, 5, "Running pre-flight checks", TaskStatus.IN_PROGRESS)

        result = await run_cmd(
            f"df -BG {project_root} --output=avail | tail -1",
            timeout=10,
        )
        if result.ok:
            avail_str = result.stdout.strip().rstrip("G")
            try:
                avail_gb = int(avail_str)
                if avail_gb < 5:
                    task_manager.update_task(
                        task_id, 5,
                        f"Insufficient disk space: {avail_gb}GB available, need at least 5GB",
                        TaskStatus.FAILED,
                    )
                    return
            except ValueError:
                logger.warning("Could not parse disk space: %s", result.stdout)

        # Phase 2: Take pre-upgrade snapshot
        task_manager.update_task(task_id, 15, "Taking pre-upgrade database snapshot", TaskStatus.IN_PROGRESS)
        before_snapshot = await get_snapshot(host, port, user, password)

        os.makedirs(staging_dir, exist_ok=True)
        snapshot_path = os.path.join(staging_dir, "before-snapshot.json")
        with open(snapshot_path, "w", encoding="utf-8") as f:
            json.dump(before_snapshot, f, indent=2)

        db_count = len(before_snapshot.get("databases", []))
        total_tables = sum(before_snapshot.get("table_counts", {}).values())

        # Phase 3: Backup MySQL data directory
        task_manager.update_task(
            task_id, 30,
            f"Backing up MySQL data ({db_count} databases, {total_tables} tables)...",
            TaskStatus.IN_PROGRESS,
        )
        result = await backup_mysql_data(project_root)
        if not result.ok:
            task_manager.update_task(
                task_id, 30,
                f"Backup failed: {result.stderr}",
                TaskStatus.FAILED,
            )
            return

        # Save current version
        old_version = get_mysql_version_from_env(project_root)
        old_version_path = os.path.join(staging_dir, "old-version.txt")
        with open(old_version_path, "w", encoding="utf-8") as f:
            f.write(old_version)

        # Save target version
        target_version_path = os.path.join(staging_dir, "target-version.txt")
        with open(target_version_path, "w", encoding="utf-8") as f:
            f.write(target_version)

        # Check backup file size
        backup_path = os.path.join(staging_dir, "data-backup.tar.gz")
        backup_size_mb = os.path.getsize(backup_path) / (1024 * 1024)

        task_manager.update_task(
            task_id, 100,
            f"Backup complete ({backup_size_mb:.0f}MB). "
            f"{db_count} databases, {total_tables} tables verified. "
            f"Ready to upgrade from {old_version} to {target_version}.",
            TaskStatus.COMPLETED,
        )

    except Exception as exc:
        logger.exception("MySQL upgrade preparation failed")
        task_manager.update_task(
            task_id, 0, f"Unexpected error: {exc}", TaskStatus.FAILED
        )


async def _perform_apply(task_id: str, settings: Settings) -> None:
    """Apply MySQL upgrade: stop, swap version, restart with existing data."""
    project_root = settings.project_root
    user = settings.mysql_user if settings.mysql_password else "root"
    password = settings.mysql_password if settings.mysql_password else settings.mysql_root_password
    staging_dir = os.path.join(project_root, "mysql", "upgrade-staging")

    try:
        # Read target version
        target_version_path = os.path.join(staging_dir, "target-version.txt")
        if not os.path.exists(target_version_path):
            task_manager.update_task(
                task_id, 0,
                "No prepared upgrade found. Run prepare first.",
                TaskStatus.FAILED,
            )
            return

        with open(target_version_path, "r", encoding="utf-8") as f:
            target_version = f.read().strip()

        old_version = get_mysql_version_from_env(project_root)

        # Phase 1: Stop MySQL
        task_manager.update_task(task_id, 10, "Stopping MySQL gracefully", TaskStatus.IN_PROGRESS)
        result = await compose_stop(["mysql"], project_root)
        if not result.ok:
            task_manager.update_task(
                task_id, 10,
                f"Failed to stop MySQL: {result.stderr}",
                TaskStatus.FAILED,
            )
            return

        # Phase 2: Update version in .env
        task_manager.update_task(task_id, 25, f"Updating MYSQL_VERSION to {target_version}", TaskStatus.IN_PROGRESS)
        set_mysql_version_in_env(project_root, target_version)

        # Phase 3: Start MySQL with new version (existing data auto-upgrades)
        task_manager.update_task(
            task_id, 40,
            f"Starting MySQL {target_version} with existing data (auto-upgrade)...",
            TaskStatus.IN_PROGRESS,
        )
        result = await compose_up(["mysql"], project_root, force_recreate=True)
        if not result.ok:
            # Revert version and try to start with old version
            set_mysql_version_in_env(project_root, old_version)
            await compose_up(["mysql"], project_root, force_recreate=True)
            task_manager.update_task(
                task_id, 40,
                f"Failed to start MySQL {target_version}: {result.stderr}. Reverted to {old_version}.",
                TaskStatus.FAILED,
            )
            return

        # Phase 4: Wait for MySQL to be ready
        task_manager.update_task(task_id, 55, "Waiting for MySQL to be ready after upgrade...", TaskStatus.IN_PROGRESS)
        ready = await wait_for_mysql_ready(
            settings.mysql_host, settings.mysql_port, user, password, timeout_secs=180,
        )
        if not ready:
            task_manager.update_task(
                task_id, 55,
                f"MySQL {target_version} failed to become ready within 180s. "
                "Use rollback to restore previous version.",
                TaskStatus.FAILED,
            )
            return

        # Phase 5: Post-upgrade verification
        task_manager.update_task(task_id, 75, "Verifying database integrity after upgrade", TaskStatus.IN_PROGRESS)

        snapshot_path = os.path.join(staging_dir, "before-snapshot.json")
        verification_note = ""
        if os.path.exists(snapshot_path):
            with open(snapshot_path, "r", encoding="utf-8") as f:
                before_snapshot = json.load(f)
            after_snapshot = await get_snapshot(
                settings.mysql_host, settings.mysql_port, user, password,
            )
            mismatches = compare_snapshots(before_snapshot, after_snapshot)
            if mismatches:
                mismatch_text = "; ".join(mismatches[:5])
                verification_note = f" Warning: {len(mismatches)} discrepancies found: {mismatch_text}"
                logger.warning("Post-upgrade verification: %s", mismatch_text)

        # Phase 6: Update version.json
        task_manager.update_task(task_id, 90, "Updating version.json", TaskStatus.IN_PROGRESS)
        update_version_json(project_root, target_version)

        task_manager.update_task(
            task_id, 100,
            f"MySQL upgraded from {old_version} to {target_version} successfully.{verification_note} "
            "Backup available for rollback if needed.",
            TaskStatus.COMPLETED,
        )

    except Exception as exc:
        logger.exception("MySQL upgrade apply failed")
        task_manager.update_task(
            task_id, 0, f"Unexpected error: {exc}", TaskStatus.FAILED
        )


async def _perform_rollback(task_id: str, settings: Settings) -> None:
    """Rollback MySQL upgrade by restoring backed-up data."""
    project_root = settings.project_root
    user = settings.mysql_user if settings.mysql_password else "root"
    password = settings.mysql_password if settings.mysql_password else settings.mysql_root_password
    staging_dir = os.path.join(project_root, "mysql", "upgrade-staging")
    old_version_path = os.path.join(staging_dir, "old-version.txt")

    try:
        # Read old version
        task_manager.update_task(task_id, 10, "Reading previous version info", TaskStatus.IN_PROGRESS)
        old_version = "unknown"
        if os.path.exists(old_version_path):
            with open(old_version_path, "r", encoding="utf-8") as f:
                old_version = f.read().strip()

        # Stop current MySQL
        task_manager.update_task(task_id, 20, "Stopping MySQL", TaskStatus.IN_PROGRESS)
        await compose_stop(["mysql"], project_root)

        # Restore data from backup
        task_manager.update_task(task_id, 40, "Restoring MySQL data from backup...", TaskStatus.IN_PROGRESS)
        result = await restore_mysql_data(project_root)
        if not result.ok:
            task_manager.update_task(
                task_id, 40,
                f"Restore failed: {result.stderr}",
                TaskStatus.FAILED,
            )
            return

        # Revert version in .env
        task_manager.update_task(task_id, 60, f"Reverting MYSQL_VERSION to {old_version}", TaskStatus.IN_PROGRESS)
        if old_version != "unknown":
            set_mysql_version_in_env(project_root, old_version)
            update_version_json(project_root, old_version)

        # Start MySQL with old version
        task_manager.update_task(task_id, 75, f"Starting MySQL {old_version}", TaskStatus.IN_PROGRESS)
        result = await compose_up(["mysql"], project_root, force_recreate=True)
        if not result.ok:
            task_manager.update_task(
                task_id, 75,
                f"Failed to start MySQL: {result.stderr}",
                TaskStatus.FAILED,
            )
            return

        # Wait for ready
        ready = await wait_for_mysql_ready(
            settings.mysql_host, settings.mysql_port, user, password,
            timeout_secs=120,
        )
        if not ready:
            task_manager.update_task(
                task_id, 90,
                "MySQL started but did not become ready within 120s. Check logs manually.",
                TaskStatus.FAILED,
            )
            return

        task_manager.update_task(
            task_id, 100,
            f"Rollback complete. MySQL restored to version {old_version}.",
            TaskStatus.COMPLETED,
        )

    except Exception as exc:
        logger.exception("MySQL rollback failed")
        task_manager.update_task(
            task_id, 0, f"Unexpected error during rollback: {exc}", TaskStatus.FAILED
        )


@router.post("/upgrade/mysql/prepare", dependencies=[Depends(require_auth)])
async def prepare_mysql_upgrade(
    body: PrepareRequest,
    background_tasks: BackgroundTasks,
    settings: Settings = Depends(get_settings),
) -> dict:
    """Backup MySQL data and validate before in-place upgrade."""
    task_id = task_manager.create_task()
    background_tasks.add_task(_perform_prepare, task_id, body.target_version, settings)
    return {"task_id": task_id}


@router.post("/upgrade/mysql/apply", dependencies=[Depends(require_auth)])
async def apply_mysql_upgrade(
    background_tasks: BackgroundTasks,
    settings: Settings = Depends(get_settings),
) -> dict:
    """Apply in-place MySQL upgrade: stop, swap version, restart."""
    staging_dir = os.path.join(settings.project_root, "mysql", "upgrade-staging")
    target_path = os.path.join(staging_dir, "target-version.txt")
    if not os.path.exists(target_path):
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="No prepared upgrade found. Run /upgrade/mysql/prepare first.",
        )

    task_id = task_manager.create_task()
    background_tasks.add_task(_perform_apply, task_id, settings)
    return {"task_id": task_id}


@router.post("/upgrade/mysql/rollback", dependencies=[Depends(require_auth)])
async def rollback_mysql_upgrade(
    background_tasks: BackgroundTasks,
    settings: Settings = Depends(get_settings),
) -> dict:
    """Rollback MySQL upgrade by restoring backed-up data."""
    staging_dir = os.path.join(settings.project_root, "mysql", "upgrade-staging")
    backup_path = os.path.join(staging_dir, "data-backup.tar.gz")
    if not os.path.exists(backup_path):
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="No backup found. Nothing to rollback to.",
        )

    task_id = task_manager.create_task()
    background_tasks.add_task(_perform_rollback, task_id, settings)
    return {"task_id": task_id}


@router.post("/upgrade/mysql/cleanup", dependencies=[Depends(require_auth)])
async def cleanup_mysql_upgrade(
    settings: Settings = Depends(get_settings),
) -> dict:
    """Remove MySQL upgrade staging files after a successful upgrade."""
    staging_dir = os.path.join(settings.project_root, "mysql", "upgrade-staging")

    removed: list[str] = []

    if os.path.exists(staging_dir):
        shutil.rmtree(staging_dir)
        removed.append("mysql/upgrade-staging")

    return {
        "status": "ok",
        "removed": removed,
    }
