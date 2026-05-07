from __future__ import annotations

import subprocess

from installer.errors import InstallerError
from installer.log_queue import LogQueue


def _docker_npm(args: list[str], log_queue: LogQueue, phase: str) -> None:
    cmd = ["docker", "exec", "alpha_panel_web", "npm", *args]
    log_queue.put({"type": "line", "text": f"$ {' '.join(cmd)}"})
    proc = subprocess.Popen(
        cmd,
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
        raise InstallerError(phase, f"npm {args[0]} exited with {rc}")


def npm_build(log_queue: LogQueue) -> None:
    _docker_npm(["install"], log_queue, "npm_build")
    _docker_npm(["run", "build"], log_queue, "npm_build")
