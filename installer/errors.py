from __future__ import annotations

from typing import Any


class InstallerError(Exception):
    """Raised by any installer step. Carries a structured phase name and optional detail."""

    def __init__(self, phase: str, message: str, detail: dict[str, Any] | None = None) -> None:
        super().__init__(f"[{phase}] {message}")
        self.phase = phase
        self.message = message
        self.detail = detail or {}
