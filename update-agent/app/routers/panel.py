from __future__ import annotations

import asyncio
import logging

from fastapi import APIRouter, BackgroundTasks, Depends

from app.auth import require_auth
from app.config import Settings, get_settings
from app.services.panel_ops import compose_exec, compose_up, run_cmd
from app.services.task_manager import TaskStatus, task_manager

logger = logging.getLogger(__name__)

router = APIRouter(tags=["panel"])


async def _perform_panel_update(task_id: str, settings: Settings) -> None:
    """Execute the full panel update sequence."""
    project_root = settings.project_root
    steps = [
        (5, "Enabling maintenance mode"),
        (15, "Pulling latest code"),
        (30, "Installing PHP dependencies"),
        (45, "Running database migrations"),
        (60, "Building frontend assets"),
        (75, "Optimizing application"),
        (85, "Recreating panel container"),
        (92, "Waiting for container health"),
        (100, "Disabling maintenance mode"),
    ]

    try:
        # Step 1: Maintenance mode on
        task_manager.update_task(task_id, steps[0][0], steps[0][1], TaskStatus.IN_PROGRESS)
        result = await compose_exec(
            "alpha_panel_web",
            "php artisan down --retry=60",
            project_root,
            timeout=30,
        )
        if not result.ok:
            task_manager.update_task(
                task_id, steps[0][0],
                f"Warning: Could not enable maintenance mode: {result.stderr}",
                TaskStatus.IN_PROGRESS,
            )

        # Step 2: Git pull
        task_manager.update_task(task_id, steps[1][0], steps[1][1], TaskStatus.IN_PROGRESS)
        result = await run_cmd("git pull --ff-only", cwd=project_root, timeout=120)
        if not result.ok:
            task_manager.update_task(
                task_id, steps[1][0],
                f"Git pull failed: {result.stderr}",
                TaskStatus.FAILED,
            )
            # Try to bring panel back up
            await compose_exec("alpha_panel_web", "php artisan up", project_root, timeout=30)
            return

        # Step 3: Composer install
        task_manager.update_task(task_id, steps[2][0], steps[2][1], TaskStatus.IN_PROGRESS)
        result = await compose_exec(
            "alpha_panel_web",
            "composer install --no-dev --optimize-autoloader --no-interaction",
            project_root,
            timeout=600,
        )
        if not result.ok:
            task_manager.update_task(
                task_id, steps[2][0],
                f"Composer install failed: {result.stderr}",
                TaskStatus.FAILED,
            )
            await compose_exec("alpha_panel_web", "php artisan up", project_root, timeout=30)
            return

        # Step 4: Database migrations
        task_manager.update_task(task_id, steps[3][0], steps[3][1], TaskStatus.IN_PROGRESS)
        result = await compose_exec(
            "alpha_panel_web",
            "php artisan migrate --force",
            project_root,
            timeout=300,
        )
        if not result.ok:
            task_manager.update_task(
                task_id, steps[3][0],
                f"Migration failed: {result.stderr}",
                TaskStatus.FAILED,
            )
            await compose_exec("alpha_panel_web", "php artisan up", project_root, timeout=30)
            return

        # Step 5: Build frontend
        task_manager.update_task(task_id, steps[4][0], steps[4][1], TaskStatus.IN_PROGRESS)
        result = await compose_exec(
            "alpha_panel_web",
            'bash -c "npm ci && npm run build"',
            project_root,
            timeout=600,
        )
        if not result.ok:
            task_manager.update_task(
                task_id, steps[4][0],
                f"Frontend build failed: {result.stderr}",
                TaskStatus.FAILED,
            )
            await compose_exec("alpha_panel_web", "php artisan up", project_root, timeout=30)
            return

        # Step 6: Optimize
        task_manager.update_task(task_id, steps[5][0], steps[5][1], TaskStatus.IN_PROGRESS)
        result = await compose_exec(
            "alpha_panel_web",
            "php artisan optimize",
            project_root,
            timeout=60,
        )
        if not result.ok:
            logger.warning("artisan optimize failed (non-fatal): %s", result.stderr)

        # Step 7: Recreate container
        task_manager.update_task(task_id, steps[6][0], steps[6][1], TaskStatus.IN_PROGRESS)
        result = await compose_up(
            ["alpha_panel_web"],
            project_root,
            force_recreate=True,
        )
        if not result.ok:
            task_manager.update_task(
                task_id, steps[6][0],
                f"Container recreate failed: {result.stderr}",
                TaskStatus.FAILED,
            )
            return

        # Step 8: Wait for healthy
        task_manager.update_task(task_id, steps[7][0], steps[7][1], TaskStatus.IN_PROGRESS)
        for attempt in range(30):
            await asyncio.sleep(2)
            health_result = await run_cmd(
                f"docker compose -f {project_root}/docker-compose.yaml "
                f"ps --format '{{{{.Status}}}}' alpha_panel_web",
                timeout=10,
            )
            if health_result.ok and "healthy" in health_result.stdout.lower():
                break
            if health_result.ok and "running" in health_result.stdout.lower():
                # Running but no healthcheck defined -- good enough
                break
        else:
            logger.warning("Container did not reach healthy state within 60s, proceeding anyway")

        # Step 9: Maintenance mode off
        task_manager.update_task(task_id, steps[8][0], steps[8][1], TaskStatus.IN_PROGRESS)
        result = await compose_exec(
            "alpha_panel_web",
            "php artisan up",
            project_root,
            timeout=30,
        )
        if not result.ok:
            logger.warning("Could not disable maintenance mode: %s", result.stderr)

        task_manager.update_task(
            task_id, 100, "Panel update completed successfully", TaskStatus.COMPLETED
        )

    except Exception as exc:
        logger.exception("Panel update failed with unexpected error")
        task_manager.update_task(
            task_id, 0, f"Unexpected error: {exc}", TaskStatus.FAILED
        )
        # Best-effort recovery: try to bring panel back up
        try:
            await compose_exec("alpha_panel_web", "php artisan up", project_root, timeout=30)
        except Exception:
            pass


@router.post("/update/panel", dependencies=[Depends(require_auth)])
async def update_panel(
    background_tasks: BackgroundTasks,
    settings: Settings = Depends(get_settings),
) -> dict:
    """Trigger a panel update as a background task.

    Returns a task_id that can be used to track progress via the /status endpoint.
    """
    task_id = task_manager.create_task()
    background_tasks.add_task(_perform_panel_update, task_id, settings)
    return {"task_id": task_id}
