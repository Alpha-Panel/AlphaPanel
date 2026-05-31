from __future__ import annotations

import asyncio
import logging

from fastapi import APIRouter, BackgroundTasks, Depends

from app.auth import require_auth
from app.config import Settings, get_settings
from app.services.panel_ops import (
    compose_exec,
    compose_up,
    ensure_compose_project_name,
    ensure_https_git_remote,
    run_cmd,
)
from app.services.task_manager import TaskStatus, task_manager

logger = logging.getLogger(__name__)

router = APIRouter(tags=["panel"])


async def _perform_panel_update(task_id: str, settings: Settings) -> None:
    """Execute the full panel update sequence."""
    project_root = settings.project_root
    steps = [
        (5, "Enabling maintenance mode"),
        (12, "Pulling latest code"),
        (22, "Installing PHP dependencies"),
        (35, "Running database migrations"),
        (50, "Building frontend assets"),
        (60, "Optimizing application"),
        (72, "Rebuilding Docker images"),
        (85, "Applying compose changes"),
        (92, "Recreating panel container"),
        (96, "Waiting for container health"),
        (100, "Disabling maintenance mode"),
    ]

    try:
        # Ensure compose commands target the live stack (re-resolve in case the panel
        # container was down when the agent booted). Without this, exec/up hit an empty
        # project and fail with "service ... is not running".
        await ensure_compose_project_name()

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

        # Guarantee HTTPS transport before pulling (image has no ssh client). Also run at
        # agent startup; repeated here so a long-running agent self-corrects per update.
        remote_result = await ensure_https_git_remote(project_root)
        if not remote_result.ok:
            logger.warning("Could not rewrite git SSH remote to HTTPS: %s", remote_result.stderr)

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

        # Step 6: Clear stale caches, then re-optimize.
        # `optimize:clear` is required before `optimize`; otherwise old config,
        # route, view, and event caches survive the update and serve stale data.
        task_manager.update_task(task_id, steps[5][0], steps[5][1], TaskStatus.IN_PROGRESS)
        result = await compose_exec(
            "alpha_panel_web",
            "php artisan optimize:clear",
            project_root,
            timeout=60,
        )
        if not result.ok:
            logger.warning("artisan optimize:clear failed (non-fatal): %s", result.stderr)

        result = await compose_exec(
            "alpha_panel_web",
            "php artisan optimize",
            project_root,
            timeout=60,
        )
        if not result.ok:
            logger.warning("artisan optimize failed (non-fatal): %s", result.stderr)

        # Step 7: Rebuild images (handles Dockerfile + compose.yaml changes)
        task_manager.update_task(task_id, steps[6][0], steps[6][1], TaskStatus.IN_PROGRESS)
        result = await run_cmd(
            f"docker compose -f {project_root}/docker-compose.yaml build",
            cwd=project_root,
            timeout=1800,
        )
        if not result.ok:
            task_manager.update_task(
                task_id, steps[6][0],
                f"Docker build failed: {result.stderr}",
                TaskStatus.FAILED,
            )
            await compose_exec("alpha_panel_web", "php artisan up", project_root, timeout=30)
            return

        # Step 8: Bring up all changed services except update-agent (self).
        # docker compose up -d only recreates services whose image/config changed.
        # update-agent is excluded because it cannot kill its own container mid-update.
        task_manager.update_task(task_id, steps[7][0], steps[7][1], TaskStatus.IN_PROGRESS)
        services_result = await run_cmd(
            f"docker compose -f {project_root}/docker-compose.yaml config --services",
            cwd=project_root,
            timeout=15,
        )
        if services_result.ok:
            target_services = [
                s.strip()
                for s in services_result.stdout.splitlines()
                if s.strip() and s.strip() != "update-agent"
            ]
            if target_services:
                result = await compose_up(target_services, project_root, force_recreate=False)
                if not result.ok:
                    logger.warning("compose up (non-self services) failed: %s", result.stderr)
        else:
            logger.warning("Could not list compose services: %s", services_result.stderr)

        # Step 9: Force-recreate panel container to guarantee fresh code/env.
        task_manager.update_task(task_id, steps[8][0], steps[8][1], TaskStatus.IN_PROGRESS)
        result = await compose_up(
            ["alpha_panel_web"],
            project_root,
            force_recreate=True,
        )
        if not result.ok:
            task_manager.update_task(
                task_id, steps[8][0],
                f"Container recreate failed: {result.stderr}",
                TaskStatus.FAILED,
            )
            return

        # Step 10: Wait for healthy
        task_manager.update_task(task_id, steps[9][0], steps[9][1], TaskStatus.IN_PROGRESS)
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

        # Step 11: Maintenance mode off
        task_manager.update_task(task_id, steps[10][0], steps[10][1], TaskStatus.IN_PROGRESS)
        result = await compose_exec(
            "alpha_panel_web",
            "php artisan up",
            project_root,
            timeout=30,
        )
        if not result.ok:
            logger.warning("Could not disable maintenance mode: %s", result.stderr)

        task_manager.update_task(
            task_id, 100,
            "Panel update completed. If update-agent itself was changed, "
            "run: docker compose up -d --force-recreate update-agent",
            TaskStatus.COMPLETED,
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
