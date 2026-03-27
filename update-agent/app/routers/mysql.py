from __future__ import annotations

import json
import logging
import os
import shutil

import shlex

from fastapi import APIRouter, BackgroundTasks, Depends, HTTPException, status
from pydantic import BaseModel

from app.auth import require_auth
from app.config import Settings, get_settings
from app.services.mysql_ops import (
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

# Container name for the MySQL service
_MYSQL_CONTAINER = "mysql"


class PrepareRequest(BaseModel):
    target_version: str


def _escaped_pw(password: str) -> str:
    return shlex.quote(password)


async def _perform_prepare(task_id: str, target_version: str, settings: Settings) -> None:
    """Standard MySQL upgrade preparation: snapshot + mysqldump inside container."""
    project_root = settings.project_root
    password = settings.mysql_root_password
    host = settings.mysql_host
    port = settings.mysql_port
    user = settings.mysql_user if settings.mysql_password else "root"
    pw = settings.mysql_password if settings.mysql_password else password
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
        task_manager.update_task(task_id, 10, "Taking pre-upgrade database snapshot", TaskStatus.IN_PROGRESS)
        before_snapshot = await get_snapshot(host, port, user, pw)

        os.makedirs(staging_dir, exist_ok=True)
        snapshot_path = os.path.join(staging_dir, "before-snapshot.json")
        with open(snapshot_path, "w", encoding="utf-8") as f:
            json.dump(before_snapshot, f, indent=2)

        db_count = len(before_snapshot.get("databases", []))
        total_tables = sum(before_snapshot.get("table_counts", {}).values())

        # Phase 3: mysqldump INSIDE the MySQL container (no SSL/auth/flag issues)
        task_manager.update_task(
            task_id, 20,
            f"Creating database dump ({db_count} databases, {total_tables} tables)... This may take a while.",
            TaskStatus.IN_PROGRESS,
        )
        dump_path = os.path.join(staging_dir, "full-dump.sql")
        escaped = _escaped_pw(password)

        # Run mysqldump inside the running MySQL container — client version matches server
        result = await run_cmd(
            f"docker exec {_MYSQL_CONTAINER} mysqldump -u root -p{escaped} "
            f"--single-transaction --routines --triggers --events "
            f"--all-databases > {dump_path}",
            timeout=3600,
        )
        if not result.ok:
            task_manager.update_task(
                task_id, 20,
                f"mysqldump failed: {result.stderr}",
                TaskStatus.FAILED,
            )
            return

        # Check dump file size
        dump_size_mb = os.path.getsize(dump_path) / (1024 * 1024)
        if dump_size_mb < 1:
            task_manager.update_task(
                task_id, 20,
                f"Dump file suspiciously small ({dump_size_mb:.1f}MB). Aborting.",
                TaskStatus.FAILED,
            )
            return

        # Phase 4: Save version info
        task_manager.update_task(task_id, 80, "Saving version information", TaskStatus.IN_PROGRESS)

        old_version = get_mysql_version_from_env(project_root)
        with open(os.path.join(staging_dir, "old-version.txt"), "w", encoding="utf-8") as f:
            f.write(old_version)
        with open(os.path.join(staging_dir, "target-version.txt"), "w", encoding="utf-8") as f:
            f.write(target_version)

        task_manager.update_task(
            task_id, 100,
            f"Backup complete ({dump_size_mb:.0f}MB dump). "
            f"{db_count} databases, {total_tables} tables. "
            f"Ready to upgrade from {old_version} to {target_version}.",
            TaskStatus.COMPLETED,
        )

    except Exception as exc:
        logger.exception("MySQL upgrade preparation failed")
        task_manager.update_task(
            task_id, 0, f"Unexpected error: {exc}", TaskStatus.FAILED
        )


async def _perform_apply(task_id: str, settings: Settings) -> None:
    """Standard MySQL upgrade: stop, fresh data dir, start new version, import dump."""
    project_root = settings.project_root
    password = settings.mysql_root_password
    staging_dir = os.path.join(project_root, "mysql", "upgrade-staging")
    mysql_data_dir = os.path.join(project_root, "mysql", "data")
    mysql_data_backup = os.path.join(project_root, "mysql", "data-pre-upgrade")

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

        dump_path = os.path.join(staging_dir, "full-dump.sql")
        if not os.path.exists(dump_path):
            task_manager.update_task(
                task_id, 0,
                "Dump file not found. Run prepare first.",
                TaskStatus.FAILED,
            )
            return

        old_version = get_mysql_version_from_env(project_root)
        escaped = _escaped_pw(password)

        # Phase 1: Stop MySQL
        task_manager.update_task(task_id, 5, "Stopping MySQL", TaskStatus.IN_PROGRESS)
        await compose_stop(["mysql"], project_root)
        await run_cmd("docker rm -f mysql", timeout=30)

        # Phase 2: Move data dir to backup (preserves original for rollback)
        task_manager.update_task(task_id, 10, "Backing up data directory", TaskStatus.IN_PROGRESS)
        if os.path.exists(mysql_data_backup):
            await run_cmd(f"rm -rf {mysql_data_backup}", timeout=300)
        result = await run_cmd(f"mv {mysql_data_dir} {mysql_data_backup}", timeout=300)
        if not result.ok:
            task_manager.update_task(
                task_id, 10,
                f"Failed to backup data directory: {result.stderr}",
                TaskStatus.FAILED,
            )
            # Restore container with old version
            await compose_up(["mysql"], project_root, force_recreate=True)
            return

        # Phase 3: Update .env to new version
        task_manager.update_task(task_id, 20, f"Setting MYSQL_VERSION to {target_version}", TaskStatus.IN_PROGRESS)
        set_mysql_version_in_env(project_root, target_version)

        # Phase 4: Start MySQL with new version (empty data dir → fresh initialization)
        task_manager.update_task(
            task_id, 25,
            f"Starting MySQL {target_version} (fresh initialization)...",
            TaskStatus.IN_PROGRESS,
        )
        result = await compose_up(["mysql"], project_root, force_recreate=True)
        if not result.ok:
            # Revert: restore data dir and old version
            set_mysql_version_in_env(project_root, old_version)
            await run_cmd(f"rm -rf {mysql_data_dir}", timeout=300)
            await run_cmd(f"mv {mysql_data_backup} {mysql_data_dir}", timeout=300)
            await run_cmd("docker rm -f mysql", timeout=30)
            await compose_up(["mysql"], project_root, force_recreate=True)
            task_manager.update_task(
                task_id, 25,
                f"Failed to start MySQL {target_version}: {result.stderr}. Reverted to {old_version}.",
                TaskStatus.FAILED,
            )
            return

        # Phase 5: Wait for fresh MySQL to be ready
        task_manager.update_task(task_id, 35, "Waiting for MySQL to initialize...", TaskStatus.IN_PROGRESS)
        ready = await wait_for_mysql_ready("127.0.0.1", 3306, "root", password, timeout_secs=120)
        if not ready:
            # Also try via container hostname
            ready = await wait_for_mysql_ready(
                settings.mysql_host, settings.mysql_port, "root", password, timeout_secs=60,
            )
        if not ready:
            task_manager.update_task(
                task_id, 35,
                f"MySQL {target_version} failed to start. Use rollback to restore.",
                TaskStatus.FAILED,
            )
            return

        # Phase 6: Import dump into new MySQL (inside container)
        task_manager.update_task(
            task_id, 45,
            "Importing database dump into new MySQL... This may take a while.",
            TaskStatus.IN_PROGRESS,
        )
        # Copy dump file into container, then import
        await run_cmd(
            f"docker cp {dump_path} {_MYSQL_CONTAINER}:/tmp/full-dump.sql",
            timeout=300,
        )
        result = await run_cmd(
            f"docker exec {_MYSQL_CONTAINER} sh -c "
            f"'mysql -u root -p{escaped} < /tmp/full-dump.sql'",
            timeout=3600,
        )
        if not result.ok:
            task_manager.update_task(
                task_id, 45,
                f"Import failed: {result.stderr}. Use rollback to restore.",
                TaskStatus.FAILED,
            )
            return

        # Clean up dump inside container
        await run_cmd(f"docker exec {_MYSQL_CONTAINER} rm -f /tmp/full-dump.sql", timeout=10)

        # Phase 7: Flush privileges
        task_manager.update_task(task_id, 75, "Flushing privileges", TaskStatus.IN_PROGRESS)
        await run_cmd(
            f"docker exec {_MYSQL_CONTAINER} mysql -u root -p{escaped} -e 'FLUSH PRIVILEGES'",
            timeout=30,
        )

        # Phase 8: Post-upgrade verification
        task_manager.update_task(task_id, 80, "Verifying database integrity", TaskStatus.IN_PROGRESS)
        user = settings.mysql_user if settings.mysql_password else "root"
        pw = settings.mysql_password if settings.mysql_password else password

        verification_note = ""
        snapshot_path = os.path.join(staging_dir, "before-snapshot.json")
        if os.path.exists(snapshot_path):
            with open(snapshot_path, "r", encoding="utf-8") as f:
                before_snapshot = json.load(f)
            after_snapshot = await get_snapshot(
                settings.mysql_host, settings.mysql_port, user, pw,
            )
            mismatches = compare_snapshots(before_snapshot, after_snapshot)
            if mismatches:
                mismatch_text = "; ".join(mismatches[:5])
                verification_note = f" Warning: {len(mismatches)} discrepancies: {mismatch_text}"
                logger.warning("Post-upgrade verification: %s", mismatch_text)

        # Phase 9: Update version.json
        task_manager.update_task(task_id, 95, "Updating version.json", TaskStatus.IN_PROGRESS)
        update_version_json(project_root, target_version)

        task_manager.update_task(
            task_id, 100,
            f"MySQL upgraded from {old_version} to {target_version} successfully.{verification_note} "
            "Old data preserved in mysql/data-pre-upgrade for rollback.",
            TaskStatus.COMPLETED,
        )

    except Exception as exc:
        logger.exception("MySQL upgrade apply failed")
        task_manager.update_task(
            task_id, 0, f"Unexpected error: {exc}", TaskStatus.FAILED
        )


async def _perform_rollback(task_id: str, settings: Settings) -> None:
    """Rollback: stop new MySQL, restore old data dir, revert version, start."""
    project_root = settings.project_root
    password = settings.mysql_root_password
    staging_dir = os.path.join(project_root, "mysql", "upgrade-staging")
    mysql_data_dir = os.path.join(project_root, "mysql", "data")
    mysql_data_backup = os.path.join(project_root, "mysql", "data-pre-upgrade")
    old_version_path = os.path.join(staging_dir, "old-version.txt")

    try:
        task_manager.update_task(task_id, 10, "Reading previous version info", TaskStatus.IN_PROGRESS)
        old_version = "unknown"
        if os.path.exists(old_version_path):
            with open(old_version_path, "r", encoding="utf-8") as f:
                old_version = f.read().strip()

        # Stop and remove current MySQL
        task_manager.update_task(task_id, 20, "Stopping MySQL", TaskStatus.IN_PROGRESS)
        await compose_stop(["mysql"], project_root)
        await run_cmd("docker rm -f mysql", timeout=30)

        # Remove new data dir, restore old
        task_manager.update_task(task_id, 35, "Restoring original data directory", TaskStatus.IN_PROGRESS)
        if not os.path.exists(mysql_data_backup):
            task_manager.update_task(
                task_id, 35,
                "No backup data directory found at mysql/data-pre-upgrade.",
                TaskStatus.FAILED,
            )
            return

        if os.path.exists(mysql_data_dir):
            await run_cmd(f"rm -rf {mysql_data_dir}", timeout=300)
        result = await run_cmd(f"mv {mysql_data_backup} {mysql_data_dir}", timeout=300)
        if not result.ok:
            task_manager.update_task(
                task_id, 35,
                f"Failed to restore data directory: {result.stderr}",
                TaskStatus.FAILED,
            )
            return

        # Revert version
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
        user = settings.mysql_user if settings.mysql_password else "root"
        pw = settings.mysql_password if settings.mysql_password else password
        ready = await wait_for_mysql_ready(
            settings.mysql_host, settings.mysql_port, user, pw, timeout_secs=120,
        )
        if not ready:
            task_manager.update_task(
                task_id, 90,
                "MySQL started but not ready within 120s. Check logs.",
                TaskStatus.FAILED,
            )
            return

        task_manager.update_task(
            task_id, 100,
            f"Rollback complete. MySQL restored to {old_version}.",
            TaskStatus.COMPLETED,
        )

    except Exception as exc:
        logger.exception("MySQL rollback failed")
        task_manager.update_task(
            task_id, 0, f"Unexpected error: {exc}", TaskStatus.FAILED
        )


@router.post("/upgrade/mysql/prepare", dependencies=[Depends(require_auth)])
async def prepare_mysql_upgrade(
    body: PrepareRequest,
    background_tasks: BackgroundTasks,
    settings: Settings = Depends(get_settings),
) -> dict:
    """Prepare MySQL upgrade: snapshot + mysqldump inside container."""
    task_id = task_manager.create_task()
    background_tasks.add_task(_perform_prepare, task_id, body.target_version, settings)
    return {"task_id": task_id}


@router.post("/upgrade/mysql/apply", dependencies=[Depends(require_auth)])
async def apply_mysql_upgrade(
    background_tasks: BackgroundTasks,
    settings: Settings = Depends(get_settings),
) -> dict:
    """Apply MySQL upgrade: stop, fresh init, import dump."""
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
    """Rollback MySQL upgrade by restoring original data directory."""
    data_backup = os.path.join(settings.project_root, "mysql", "data-pre-upgrade")
    if not os.path.exists(data_backup):
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="No backup found at mysql/data-pre-upgrade.",
        )

    task_id = task_manager.create_task()
    background_tasks.add_task(_perform_rollback, task_id, settings)
    return {"task_id": task_id}


@router.post("/upgrade/mysql/cleanup", dependencies=[Depends(require_auth)])
async def cleanup_mysql_upgrade(
    settings: Settings = Depends(get_settings),
) -> dict:
    """Remove MySQL upgrade staging and backup files."""
    staging_dir = os.path.join(settings.project_root, "mysql", "upgrade-staging")
    data_backup = os.path.join(settings.project_root, "mysql", "data-pre-upgrade")
    removed: list[str] = []

    if os.path.exists(staging_dir):
        shutil.rmtree(staging_dir)
        removed.append("mysql/upgrade-staging")

    if os.path.exists(data_backup):
        shutil.rmtree(data_backup)
        removed.append("mysql/data-pre-upgrade")

    return {"status": "ok", "removed": removed}
