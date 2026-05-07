from __future__ import annotations

import subprocess
from pathlib import Path

from installer.errors import InstallerError
from installer.log_queue import LogQueue

_STORAGE_DIRS = [
    "storage/framework/cache/data",
    "storage/framework/sessions",
    "storage/framework/views",
    "storage/framework/testing",
    "storage/logs",
]


def _docker_artisan(args: list[str], log_queue: LogQueue, phase: str) -> None:
    cmd = ["docker", "exec", "alpha_panel_web", "php", "artisan", *args]
    log_queue.put({"type": "line", "text": f"$ {' '.join(cmd)}"})
    proc = subprocess.Popen(
        cmd, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True, bufsize=1,
    )
    assert proc.stdout is not None
    for line in proc.stdout:
        log_queue.put({"type": "line", "text": line.rstrip("\n")})
    rc = proc.wait()
    if rc != 0:
        raise InstallerError(phase, f"artisan {args[0]} exited with {rc}")


def composer_install(project_dir: Path, log_queue: LogQueue) -> None:
    # 1. Ensure storage directories exist on the host bind-mount
    httpdocs = project_dir / "alpha-panel" / "web" / "httpdocs"
    for rel in _STORAGE_DIRS:
        (httpdocs / rel).mkdir(parents=True, exist_ok=True)
    log_queue.put({"type": "line", "text": "[setup] storage directories ready"})

    # 2. composer install
    cmd = [
        "docker", "exec", "alpha_panel_web",
        "composer", "install",
        "--no-dev", "--optimize-autoloader", "--no-interaction",
    ]
    log_queue.put({"type": "line", "text": f"$ {' '.join(cmd)}"})
    proc = subprocess.Popen(
        cmd, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True, bufsize=1,
    )
    assert proc.stdout is not None
    for line in proc.stdout:
        log_queue.put({"type": "line", "text": line.rstrip("\n")})
    rc = proc.wait()
    if rc != 0:
        raise InstallerError("composer_install", f"composer install exited with {rc}")

    # 3. storage:link
    _docker_artisan(["storage:link", "--force"], log_queue, "composer_install")
