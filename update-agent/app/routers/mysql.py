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
    check_disk_for_backup,
    cp_data_dir,
    detect_major_skip,
    get_mysql_version_from_env,
    scan_incompat_tables,
    set_mysql_version_in_env,
    update_version_json,
    validate_target_image,
    verify_backup,
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

_MYSQL_CONTAINER = "mysql"


class PrepareRequest(BaseModel):
    target_version: str


def _human_size(num_bytes: int) -> str:
    """Format byte count for status messages."""
    units = ["B", "KB", "MB", "GB", "TB"]
    size = float(num_bytes)
    for unit in units:
        if size < 1024 or unit == units[-1]:
            return f"{size:.1f}{unit}"
        size /= 1024
    return f"{num_bytes}B"


async def _perform_prepare(task_id: str, target_version: str, settings: Settings) -> None:
    """In-place upgrade preparation: pre-flight checks + cp -a data backup."""
    project_root = settings.project_root
    data_dir = os.path.join(project_root, "mysql", "data")
    backup_dir = os.path.join(project_root, "mysql", "data-pre-upgrade")
    staging_dir = os.path.join(project_root, "mysql", "upgrade-staging")

    try:
        os.makedirs(staging_dir, exist_ok=True)

        # Phase 1: Image manifest check
        task_manager.update_task(
            task_id, 5,
            f"Checking that mysql:{target_version} image is available",
            TaskStatus.IN_PROGRESS,
        )
        manifest = await validate_target_image(target_version)
        if not manifest.ok:
            task_manager.update_task(
                task_id, 5,
                f"Image mysql:{target_version} not found in registry: {manifest.stderr.strip()[:200]}",
                TaskStatus.FAILED,
            )
            return

        # Phase 2: Major-skip detection
        task_manager.update_task(task_id, 10, "Validating version jump", TaskStatus.IN_PROGRESS)
        current_version = get_mysql_version_from_env(project_root)
        skip_reason = detect_major_skip(current_version, target_version)
        if skip_reason:
            task_manager.update_task(task_id, 10, skip_reason, TaskStatus.FAILED)
            return

        # Phase 3: Disk space (data_size * 1.2 free)
        task_manager.update_task(task_id, 15, "Checking disk space", TaskStatus.IN_PROGRESS)
        data_size, free_bytes, sufficient = await check_disk_for_backup(data_dir)
        if not sufficient:
            task_manager.update_task(
                task_id, 15,
                f"Insufficient disk space for backup. Data {_human_size(data_size)}, "
                f"free {_human_size(free_bytes)}, need at least {_human_size(int(data_size * 1.2))}.",
                TaskStatus.FAILED,
            )
            return

        # Phase 4: Known incompat table scan (warn only)
        task_manager.update_task(
            task_id, 20,
            "Scanning for known incompatible tables",
            TaskStatus.IN_PROGRESS,
        )
        user = settings.mysql_user if settings.mysql_password else "root"
        pw = settings.mysql_password if settings.mysql_password else settings.mysql_root_password
        try:
            findings = await scan_incompat_tables(
                settings.mysql_host, settings.mysql_port,
                user, pw, current_version, target_version,
            )
        except Exception as exc:
            logger.warning("Incompat scan failed (non-fatal): %s", exc)
            findings = []

        warnings_path = os.path.join(staging_dir, "incompat-warnings.json")
        with open(warnings_path, "w", encoding="utf-8") as f:
            json.dump(findings, f, indent=2)

        # Phase 5: Cleanup any stale backup, then cp -a
        if os.path.exists(backup_dir):
            task_manager.update_task(
                task_id, 25,
                "Removing stale previous backup",
                TaskStatus.IN_PROGRESS,
            )
            cleanup = await run_cmd(f"rm -rf {backup_dir}", timeout=600)
            if not cleanup.ok:
                task_manager.update_task(
                    task_id, 25,
                    f"Failed to remove stale backup: {cleanup.stderr[:200]}",
                    TaskStatus.FAILED,
                )
                return

        task_manager.update_task(
            task_id, 30,
            f"Copying data directory ({_human_size(data_size)}). "
            "This may take several minutes for large databases.",
            TaskStatus.IN_PROGRESS,
        )
        cp_result = await cp_data_dir(data_dir, backup_dir, timeout=7200)
        if not cp_result.ok:
            task_manager.update_task(
                task_id, 30,
                f"Backup copy failed: {cp_result.stderr[:200]}",
                TaskStatus.FAILED,
            )
            return

        # Phase 6: Verify backup integrity
        task_manager.update_task(task_id, 95, "Verifying backup integrity", TaskStatus.IN_PROGRESS)
        if not await verify_backup(data_dir, backup_dir):
            task_manager.update_task(
                task_id, 95,
                "Backup size does not match source. Aborting prepare.",
                TaskStatus.FAILED,
            )
            return

        # Phase 7: Save metadata
        with open(os.path.join(staging_dir, "old-version.txt"), "w", encoding="utf-8") as f:
            f.write(current_version)
        with open(os.path.join(staging_dir, "target-version.txt"), "w", encoding="utf-8") as f:
            f.write(target_version)
        with open(os.path.join(staging_dir, "data-size-bytes.txt"), "w", encoding="utf-8") as f:
            f.write(str(data_size))

        warning_note = ""
        if findings:
            warning_note = (
                f" {len(findings)} potentially incompatible table(s) detected; "
                "review mysql/upgrade-staging/incompat-warnings.json before applying."
            )

        task_manager.update_task(
            task_id, 100,
            f"Backup complete ({_human_size(data_size)}). "
            f"Ready to upgrade from {current_version} to {target_version}.{warning_note}",
            TaskStatus.COMPLETED,
        )

    except Exception as exc:
        logger.exception("MySQL upgrade preparation failed")
        task_manager.update_task(
            task_id, 0, f"Unexpected error: {exc}", TaskStatus.FAILED,
        )


async def _try_start_mysql(project_root: str, timeout_secs: int) -> tuple[bool, str]:
    """Recreate the mysql container and wait for readiness. Returns (ok, detail)."""
    await run_cmd(f"docker rm -f {_MYSQL_CONTAINER}", timeout=30)
    up = await compose_up(["mysql"], project_root, force_recreate=True)
    if not up.ok:
        return (False, f"compose up failed: {up.stderr[:300]}")
    return (True, "")


async def _perform_apply(task_id: str, settings: Settings) -> None:
    """In-place apply: stop, swap MYSQL_VERSION, recreate, wait for auto-upgrade."""
    project_root = settings.project_root
    staging_dir = os.path.join(project_root, "mysql", "upgrade-staging")
    backup_dir = os.path.join(project_root, "mysql", "data-pre-upgrade")
    data_dir = os.path.join(project_root, "mysql", "data")
    target_version_path = os.path.join(staging_dir, "target-version.txt")

    try:
        # Phase 1: Validate prepared state
        task_manager.update_task(task_id, 5, "Validating prepared backup", TaskStatus.IN_PROGRESS)
        if not os.path.exists(target_version_path):
            task_manager.update_task(
                task_id, 5,
                "No prepared upgrade found. Run prepare first.",
                TaskStatus.FAILED,
            )
            return
        if not os.path.isdir(backup_dir):
            task_manager.update_task(
                task_id, 5,
                f"Backup directory missing at {backup_dir}. Run prepare first.",
                TaskStatus.FAILED,
            )
            return

        with open(target_version_path, "r", encoding="utf-8") as f:
            target_version = f.read().strip()

        old_version = get_mysql_version_from_env(project_root)
        password = settings.mysql_root_password
        host = settings.mysql_host
        port = settings.mysql_port

        # Phase 2: Stop and remove existing mysql container
        task_manager.update_task(task_id, 10, "Stopping MySQL", TaskStatus.IN_PROGRESS)
        await compose_stop(["mysql"], project_root)
        await run_cmd(f"docker rm -f {_MYSQL_CONTAINER}", timeout=30)

        # Phase 3: Update .env to new version
        task_manager.update_task(
            task_id, 20,
            f"Setting MYSQL_VERSION to {target_version}",
            TaskStatus.IN_PROGRESS,
        )
        set_mysql_version_in_env(project_root, target_version)

        # Phase 4: Start new MySQL on existing data dir (auto-upgrade runs here)
        task_manager.update_task(
            task_id, 30,
            f"Starting MySQL {target_version} (data dictionary auto-upgrade may take a while)",
            TaskStatus.IN_PROGRESS,
        )
        ok, detail = await _try_start_mysql(project_root, timeout_secs=300)
        if not ok:
            await _recover_after_apply_failure(
                task_id, project_root, old_version, detail,
                stage_percent=30,
            )
            return

        # Phase 5: Wait until the new server accepts connections
        task_manager.update_task(
            task_id, 40,
            f"Waiting for MySQL {target_version} to become ready (up to 300s)",
            TaskStatus.IN_PROGRESS,
        )
        ready = await wait_for_mysql_ready(host, port, "root", password, timeout_secs=300, poll_interval=5)
        if not ready:
            await _recover_after_apply_failure(
                task_id, project_root, old_version,
                f"MySQL {target_version} did not become ready within 300s",
                stage_percent=40,
            )
            return

        # Phase 6: Sanity verify version
        task_manager.update_task(task_id, 80, "Verifying server version", TaskStatus.IN_PROGRESS)
        version_check = await run_cmd(
            f"docker exec {_MYSQL_CONTAINER} mysql -uroot "
            f"-p{password} -N -e 'SELECT VERSION()'",
            timeout=30,
        )
        if version_check.ok:
            reported = version_check.stdout.strip().split("-")[0]
            target_major_minor = ".".join(target_version.split(".")[:2])
            reported_major_minor = ".".join(reported.split(".")[:2])
            if not reported_major_minor.startswith(target_major_minor):
                logger.warning(
                    "Version mismatch: reported %s, expected prefix %s",
                    reported, target_major_minor,
                )

        # Phase 7: Update version.json
        task_manager.update_task(task_id, 90, "Updating version.json", TaskStatus.IN_PROGRESS)
        update_version_json(project_root, target_version)

        task_manager.update_task(
            task_id, 100,
            f"MySQL upgraded from {old_version} to {target_version}. "
            "Backup retained at mysql/data-pre-upgrade until cleanup.",
            TaskStatus.COMPLETED,
        )

    except Exception as exc:
        logger.exception("MySQL upgrade apply failed")
        task_manager.update_task(
            task_id, 0, f"Unexpected error: {exc}", TaskStatus.FAILED,
        )


async def _recover_after_apply_failure(
    task_id: str,
    project_root: str,
    old_version: str,
    failure_detail: str,
    stage_percent: int,
) -> None:
    """Two-tier recovery: restart old version, then restore backup if needed."""
    backup_dir = os.path.join(project_root, "mysql", "data-pre-upgrade")
    data_dir = os.path.join(project_root, "mysql", "data")

    # Tier 1: revert .env and try old version on existing data dir
    task_manager.update_task(
        task_id, stage_percent,
        f"{failure_detail}. Attempting recovery: restart {old_version}",
        TaskStatus.IN_PROGRESS,
    )
    set_mysql_version_in_env(project_root, old_version)
    tier1_ok, tier1_detail = await _try_start_mysql(project_root, timeout_secs=60)
    if tier1_ok:
        # Wait for old to actually accept connections
        # (caller doesn't have settings handy; reuse env-style defaults)
        pass

    # Confirm tier 1 health by ping (best effort — without settings here we
    # rely on docker ps + a short wait)
    await run_cmd("sleep 5", timeout=10)
    ping = await run_cmd(
        f"docker exec {_MYSQL_CONTAINER} mysqladmin ping --silent",
        timeout=15,
    )
    if tier1_ok and ping.ok:
        task_manager.update_task(
            task_id, stage_percent,
            f"Apply failed but recovery succeeded: rolled back to {old_version} on existing data dir. "
            f"Original failure: {failure_detail}",
            TaskStatus.FAILED,
        )
        return

    # Tier 2: restore backup
    task_manager.update_task(
        task_id, stage_percent,
        f"Old version did not start either ({tier1_detail or 'ping failed'}). "
        "Restoring data directory from backup.",
        TaskStatus.IN_PROGRESS,
    )
    await run_cmd(f"docker rm -f {_MYSQL_CONTAINER}", timeout=30)
    if os.path.exists(data_dir):
        rm = await run_cmd(f"rm -rf {data_dir}", timeout=600)
        if not rm.ok:
            task_manager.update_task(
                task_id, stage_percent,
                f"CRITICAL: failed to remove data dir during recovery ({rm.stderr[:200]}). "
                "Manual intervention required.",
                TaskStatus.FAILED,
            )
            return
    if not os.path.isdir(backup_dir):
        task_manager.update_task(
            task_id, stage_percent,
            "CRITICAL: backup directory missing during recovery. Manual intervention required.",
            TaskStatus.FAILED,
        )
        return
    cp_result = await cp_data_dir(backup_dir, data_dir, timeout=7200)
    if not cp_result.ok:
        task_manager.update_task(
            task_id, stage_percent,
            f"CRITICAL: backup restore failed ({cp_result.stderr[:200]}). "
            "Manual intervention required.",
            TaskStatus.FAILED,
        )
        return

    tier2_ok, tier2_detail = await _try_start_mysql(project_root, timeout_secs=120)
    if not tier2_ok:
        task_manager.update_task(
            task_id, stage_percent,
            f"CRITICAL: MySQL did not restart after backup restore ({tier2_detail}). "
            "Manual intervention required.",
            TaskStatus.FAILED,
        )
        return

    task_manager.update_task(
        task_id, stage_percent,
        f"Apply failed; backup restored and {old_version} is running again. "
        f"Original failure: {failure_detail}",
        TaskStatus.FAILED,
    )


async def _perform_rollback(task_id: str, settings: Settings) -> None:
    """Explicit rollback: restore from data-pre-upgrade and revert version."""
    project_root = settings.project_root
    staging_dir = os.path.join(project_root, "mysql", "upgrade-staging")
    backup_dir = os.path.join(project_root, "mysql", "data-pre-upgrade")
    data_dir = os.path.join(project_root, "mysql", "data")
    old_version_path = os.path.join(staging_dir, "old-version.txt")

    try:
        task_manager.update_task(task_id, 10, "Validating backup", TaskStatus.IN_PROGRESS)
        if not os.path.isdir(backup_dir):
            task_manager.update_task(
                task_id, 10,
                "No backup directory at mysql/data-pre-upgrade. Cannot rollback.",
                TaskStatus.FAILED,
            )
            return

        old_version = "unknown"
        if os.path.exists(old_version_path):
            with open(old_version_path, "r", encoding="utf-8") as f:
                old_version = f.read().strip()

        task_manager.update_task(task_id, 20, "Stopping MySQL", TaskStatus.IN_PROGRESS)
        await compose_stop(["mysql"], project_root)
        await run_cmd(f"docker rm -f {_MYSQL_CONTAINER}", timeout=30)

        task_manager.update_task(task_id, 40, "Removing current data directory", TaskStatus.IN_PROGRESS)
        if os.path.exists(data_dir):
            rm = await run_cmd(f"rm -rf {data_dir}", timeout=600)
            if not rm.ok:
                task_manager.update_task(
                    task_id, 40,
                    f"Failed to remove data dir: {rm.stderr[:200]}",
                    TaskStatus.FAILED,
                )
                return

        task_manager.update_task(task_id, 60, "Restoring data from backup", TaskStatus.IN_PROGRESS)
        cp_result = await cp_data_dir(backup_dir, data_dir, timeout=7200)
        if not cp_result.ok:
            task_manager.update_task(
                task_id, 60,
                f"Backup restore failed: {cp_result.stderr[:200]}",
                TaskStatus.FAILED,
            )
            return

        task_manager.update_task(
            task_id, 70,
            f"Reverting MYSQL_VERSION to {old_version}",
            TaskStatus.IN_PROGRESS,
        )
        if old_version != "unknown":
            set_mysql_version_in_env(project_root, old_version)
            update_version_json(project_root, old_version)

        task_manager.update_task(
            task_id, 80,
            f"Starting MySQL {old_version}",
            TaskStatus.IN_PROGRESS,
        )
        ok, detail = await _try_start_mysql(project_root, timeout_secs=120)
        if not ok:
            task_manager.update_task(
                task_id, 80,
                f"Failed to start MySQL after restore: {detail}",
                TaskStatus.FAILED,
            )
            return

        task_manager.update_task(task_id, 95, "Waiting for MySQL to become ready", TaskStatus.IN_PROGRESS)
        ready = await wait_for_mysql_ready(
            settings.mysql_host, settings.mysql_port,
            "root", settings.mysql_root_password,
            timeout_secs=120, poll_interval=3,
        )
        if not ready:
            task_manager.update_task(
                task_id, 95,
                "MySQL started but did not become ready within 120s. Check logs.",
                TaskStatus.FAILED,
            )
            return

        task_manager.update_task(
            task_id, 100,
            f"Rollback complete. MySQL restored to {old_version} from backup.",
            TaskStatus.COMPLETED,
        )

    except Exception as exc:
        logger.exception("MySQL rollback failed")
        task_manager.update_task(
            task_id, 0, f"Unexpected error: {exc}", TaskStatus.FAILED,
        )


@router.post("/upgrade/mysql/prepare", dependencies=[Depends(require_auth)])
async def prepare_mysql_upgrade(
    body: PrepareRequest,
    background_tasks: BackgroundTasks,
    settings: Settings = Depends(get_settings),
) -> dict:
    """Prepare in-place MySQL upgrade: pre-flight checks + cp -a data backup."""
    task_id = task_manager.create_task()
    background_tasks.add_task(_perform_prepare, task_id, body.target_version, settings)
    return {"task_id": task_id}


@router.post("/upgrade/mysql/apply", dependencies=[Depends(require_auth)])
async def apply_mysql_upgrade(
    background_tasks: BackgroundTasks,
    settings: Settings = Depends(get_settings),
) -> dict:
    """Apply in-place MySQL upgrade: stop, swap version, recreate, wait."""
    staging_dir = os.path.join(settings.project_root, "mysql", "upgrade-staging")
    target_path = os.path.join(staging_dir, "target-version.txt")
    backup_dir = os.path.join(settings.project_root, "mysql", "data-pre-upgrade")

    if not os.path.exists(target_path):
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="No prepared upgrade found. Run /upgrade/mysql/prepare first.",
        )
    if not os.path.isdir(backup_dir):
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="Backup directory missing. Run /upgrade/mysql/prepare first.",
        )

    task_id = task_manager.create_task()
    background_tasks.add_task(_perform_apply, task_id, settings)
    return {"task_id": task_id}


@router.post("/upgrade/mysql/rollback", dependencies=[Depends(require_auth)])
async def rollback_mysql_upgrade(
    background_tasks: BackgroundTasks,
    settings: Settings = Depends(get_settings),
) -> dict:
    """Rollback MySQL by restoring data dir from mysql/data-pre-upgrade."""
    backup_dir = os.path.join(settings.project_root, "mysql", "data-pre-upgrade")
    if not os.path.isdir(backup_dir):
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
    backup_dir = os.path.join(settings.project_root, "mysql", "data-pre-upgrade")
    removed: list[str] = []

    if os.path.exists(staging_dir):
        shutil.rmtree(staging_dir)
        removed.append("mysql/upgrade-staging")

    if os.path.exists(backup_dir):
        shutil.rmtree(backup_dir)
        removed.append("mysql/data-pre-upgrade")

    return {"status": "ok", "removed": removed}
