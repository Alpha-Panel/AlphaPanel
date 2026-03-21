from __future__ import annotations

import asyncio
import uuid
from dataclasses import dataclass, field
from datetime import datetime, timezone
from enum import Enum
from typing import AsyncGenerator


class TaskStatus(str, Enum):
    PENDING = "pending"
    IN_PROGRESS = "in_progress"
    COMPLETED = "completed"
    FAILED = "failed"


@dataclass
class TaskUpdate:
    percent: int
    message: str
    status: TaskStatus
    timestamp: str = field(default_factory=lambda: datetime.now(timezone.utc).isoformat())


@dataclass
class TaskInfo:
    task_id: str
    percent: int = 0
    message: str = "Pending"
    status: TaskStatus = TaskStatus.PENDING
    created_at: str = field(default_factory=lambda: datetime.now(timezone.utc).isoformat())
    updates: list[TaskUpdate] = field(default_factory=list)
    _event: asyncio.Event = field(default_factory=asyncio.Event, repr=False)
    _closed: bool = field(default=False, repr=False)

    def to_dict(self) -> dict:
        return {
            "task_id": self.task_id,
            "percent": self.percent,
            "message": self.message,
            "status": self.status.value,
            "created_at": self.created_at,
        }


class TaskManager:
    """In-memory async task manager with SSE subscription support."""

    def __init__(self) -> None:
        self._tasks: dict[str, TaskInfo] = {}

    def create_task(self) -> str:
        task_id = str(uuid.uuid4())
        self._tasks[task_id] = TaskInfo(task_id=task_id)
        return task_id

    def update_task(
        self,
        task_id: str,
        percent: int,
        message: str,
        status: TaskStatus,
    ) -> None:
        task = self._tasks.get(task_id)
        if task is None:
            return

        task.percent = percent
        task.message = message
        task.status = status

        update = TaskUpdate(percent=percent, message=message, status=status)
        task.updates.append(update)

        # Wake all subscribers
        task._event.set()
        task._event.clear()

        # Mark closed on terminal states so subscribers can exit
        if status in (TaskStatus.COMPLETED, TaskStatus.FAILED):
            task._closed = True
            task._event.set()

    def get_task(self, task_id: str) -> TaskInfo | None:
        return self._tasks.get(task_id)

    async def subscribe(self, task_id: str) -> AsyncGenerator[dict, None]:
        """Yield task state dicts whenever an update occurs.

        The generator terminates when the task reaches a terminal state.
        """
        task = self._tasks.get(task_id)
        if task is None:
            return

        # Emit current state immediately
        yield task.to_dict()

        seen = len(task.updates)

        while not task._closed:
            await task._event.wait()

            # Yield every unseen update
            while seen < len(task.updates):
                upd = task.updates[seen]
                seen += 1
                yield {
                    "task_id": task.task_id,
                    "percent": upd.percent,
                    "message": upd.message,
                    "status": upd.status.value,
                }

        # Drain remaining updates after close
        while seen < len(task.updates):
            upd = task.updates[seen]
            seen += 1
            yield {
                "task_id": task.task_id,
                "percent": upd.percent,
                "message": upd.message,
                "status": upd.status.value,
            }


# Singleton instance used across the application
task_manager = TaskManager()
