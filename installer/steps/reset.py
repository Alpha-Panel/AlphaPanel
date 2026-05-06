from __future__ import annotations

import shutil
import subprocess
from pathlib import Path

from installer.log_queue import LogQueue

_DATA_DIRS_TO_WIPE = [
    "mysql/data",
    "letsencrypt",
    "portainer",
    "meilisearch/data",
    "alpha-panel/meilisearch/data",
    "redis",
    "postgres",
    "n8n/data",
    "jenkins/data",
    "vaultwarden/data",
    "crowdsec/data",
    "frankenphp/caddy_data",
    "alpha-panel/web/caddy_data",
]


def _rm_if_exists(path: Path) -> None:
    if path.exists():
        path.unlink()


def _rmdir_if_exists(path: Path) -> None:
    if path.exists() and path.is_dir():
        shutil.rmtree(path, ignore_errors=True)


def reset_installation(
    project_dir: Path,
    state_file: Path,
    log_queue: LogQueue,
) -> None:
    cmd = ["docker", "compose", "down", "-v", "--remove-orphans"]
    log_queue.put({"type": "line", "text": f"$ {' '.join(cmd)} (in {project_dir})"})
    proc = subprocess.Popen(
        cmd,
        cwd=str(project_dir),
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        text=True,
        bufsize=1,
    )
    assert proc.stdout is not None
    for line in proc.stdout:
        log_queue.put({"type": "line", "text": line.rstrip("\n")})
    proc.wait()
    # Tolerate non-zero exit — the stack may not have existed.

    # Wipe bind-mount data directories so the next install starts truly fresh.
    for rel in _DATA_DIRS_TO_WIPE:
        _rmdir_if_exists(project_dir / rel)
        log_queue.put({"type": "line", "text": f"[reset] wiped {rel}"})

    _rm_if_exists(state_file)
    _rm_if_exists(project_dir / ".env")
    _rm_if_exists(project_dir / "alpha-panel" / "web" / "httpdocs" / ".env")
