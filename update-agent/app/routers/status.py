from __future__ import annotations

import json

from fastapi import APIRouter, Depends, HTTPException, status
from sse_starlette.sse import EventSourceResponse

from app.auth import require_auth
from app.services.task_manager import task_manager

router = APIRouter(tags=["status"])


@router.get("/status/{task_id}/current", dependencies=[Depends(require_auth)])
async def get_task_status_current(task_id: str):
    """Return the current task status as a single JSON response (non-streaming)."""
    task = task_manager.get_task(task_id)
    if task is None:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail=f"Task {task_id} not found.",
        )
    return task.to_dict()


@router.get("/status/{task_id}", dependencies=[Depends(require_auth)])
async def stream_task_status(task_id: str):
    """Stream task progress as Server-Sent Events.

    Each event contains:
        {"task_id": "...", "percent": 50, "message": "...", "status": "in_progress"}

    The stream closes automatically when the task reaches a terminal state
    (completed or failed).
    """
    task = task_manager.get_task(task_id)
    if task is None:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail=f"Task {task_id} not found.",
        )

    async def event_generator():
        async for update in task_manager.subscribe(task_id):
            yield {
                "event": "progress",
                "data": json.dumps(update),
            }

    return EventSourceResponse(event_generator())
