from __future__ import annotations

from installer.errors import InstallerError
from installer.log_queue import LogQueue
import subprocess


def composer_install(log_queue: LogQueue) -> None:
    cmd = [
        "docker", "exec", "alpha_panel_web",
        "composer", "install",
        "--no-dev", "--optimize-autoloader", "--no-interaction",
    ]
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
        raise InstallerError("composer_install", f"composer install exited with {rc}")
