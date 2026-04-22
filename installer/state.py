from __future__ import annotations

import json
import os
from dataclasses import asdict, dataclass, field
from datetime import datetime, timezone
from pathlib import Path
from typing import Any


@dataclass
class InstallerState:
    form: dict[str, Any] = field(default_factory=dict)
    generated_secrets: dict[str, str] = field(default_factory=dict)
    completed_phases: list[str] = field(default_factory=list)
    current_phase: str | None = None
    last_error: dict[str, Any] | None = None


def load_state(path: Path) -> InstallerState | None:
    if not path.exists():
        return None
    raw = json.loads(path.read_text())
    return InstallerState(
        form=raw.get("form", {}),
        generated_secrets=raw.get("generated_secrets", {}),
        completed_phases=raw.get("completed_phases", []),
        current_phase=raw.get("current_phase"),
        last_error=raw.get("last_error"),
    )


def save_state(path: Path, state: InstallerState) -> None:
    now = datetime.now(timezone.utc).isoformat()
    payload: dict[str, Any] = {"version": 1}
    existing: dict[str, Any] | None = None
    if path.exists():
        try:
            existing = json.loads(path.read_text())
        except json.JSONDecodeError:
            existing = None
    payload["started_at"] = (existing or {}).get("started_at", now)
    payload["updated_at"] = now
    payload.update(asdict(state))
    path.write_text(json.dumps(payload, indent=2))
    os.chmod(path, 0o600)


def clear_state(path: Path) -> None:
    if path.exists():
        path.unlink()
