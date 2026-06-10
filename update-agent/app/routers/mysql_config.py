from __future__ import annotations

import logging

from fastapi import APIRouter, BackgroundTasks, Depends, HTTPException, status
from pydantic import BaseModel, Field

from app.auth import require_auth
from app.config import Settings, get_settings
from app.services.mysql_config_ops import (
    ALLOWED_CONF_FILES,
    read_config_file,
    restart_mysql,
    validate_filename,
    write_config_file,
)
from app.services.task_manager import TaskStatus, task_manager

logger = logging.getLogger(__name__)

router = APIRouter(tags=["mysql-config"])


class WriteConfigRequest(BaseModel):
    # Cap config body size: these are small MySQL .cnf files. A bound prevents
    # a giant payload from exhausting memory/disk on write.
    content: str = Field(max_length=65536)


async def _perform_restart(task_id: str, project_root: str) -> None:
    task_manager.update_task(task_id, 10, "Restarting MySQL container", TaskStatus.IN_PROGRESS)
    ok, detail = await restart_mysql(project_root)
    if ok:
        task_manager.update_task(task_id, 100, detail, TaskStatus.COMPLETED)
    else:
        task_manager.update_task(task_id, 0, f"Restart failed: {detail}", TaskStatus.FAILED)


@router.get("/mysql/config/{filename}", dependencies=[Depends(require_auth)])
async def get_config_file(
    filename: str,
    settings: Settings = Depends(get_settings),
) -> dict:
    """Read a mysql/conf.d file."""
    if not validate_filename(filename):
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail=f"Invalid filename. Allowed: {sorted(ALLOWED_CONF_FILES)}",
        )
    content = read_config_file(settings.project_root, filename)
    return {"filename": filename, "content": content}


@router.put("/mysql/config/{filename}", dependencies=[Depends(require_auth)])
async def put_config_file(
    filename: str,
    body: WriteConfigRequest,
    settings: Settings = Depends(get_settings),
) -> dict:
    """Write a mysql/conf.d file atomically."""
    if not validate_filename(filename):
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail=f"Invalid filename. Allowed: {sorted(ALLOWED_CONF_FILES)}",
        )
    try:
        write_config_file(settings.project_root, filename, body.content)
    except OSError as exc:
        logger.exception("Failed to write MySQL config file %s", filename)
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail="Failed to write config file.",
        ) from exc
    return {"status": "ok", "filename": filename}


@router.post("/mysql/config/restart", dependencies=[Depends(require_auth)])
async def restart_mysql_container(
    background_tasks: BackgroundTasks,
    settings: Settings = Depends(get_settings),
) -> dict:
    """Restart the MySQL container."""
    task_id = task_manager.create_task()
    background_tasks.add_task(_perform_restart, task_id, settings.project_root)
    return {"task_id": task_id}
