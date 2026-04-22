from __future__ import annotations

import queue
from typing import Any, Iterator

_SENTINEL = object()


class LogQueue:
    """Thread-safe queue with a stream() iterator that ends when close() is called."""

    def __init__(self) -> None:
        self._q: queue.Queue[Any] = queue.Queue()
        self._closed = False

    def put(self, item: dict[str, Any]) -> None:
        if self._closed:
            raise RuntimeError("LogQueue is closed")
        self._q.put(item)

    def close(self) -> None:
        if self._closed:
            return
        self._closed = True
        self._q.put(_SENTINEL)

    def stream(self) -> Iterator[dict[str, Any]]:
        while True:
            item = self._q.get()
            if item is _SENTINEL:
                return
            yield item
