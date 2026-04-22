from __future__ import annotations

import subprocess
from pathlib import Path

from installer.errors import InstallerError
from installer.log_queue import LogQueue


def compose_up(project_dir: Path, log_queue: LogQueue) -> None:
    cmd = ["docker", "compose", "up", "-d", "--build"]
    log_queue.put({"type": "line", "text": f"$ {' '.join(cmd)}"})
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
    rc = proc.wait()
    if rc != 0:
        raise InstallerError("compose_up", f"docker compose up exited with code {rc}")
