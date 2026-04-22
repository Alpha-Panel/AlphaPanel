from __future__ import annotations

import subprocess
import time

from installer.errors import InstallerError
from installer.log_queue import LogQueue


def wait_for_mysql(root_password: str, timeout: float = 180.0, interval: float = 3.0) -> None:
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        result = subprocess.run(
            [
                "docker",
                "exec",
                "mysql",
                "mysqladmin",
                "ping",
                "-h127.0.0.1",
                "-uroot",
                f"-p{root_password}",
                "--silent",
            ],
            capture_output=True,
            text=True,
        )
        if result.returncode == 0:
            return
        time.sleep(interval)
    raise InstallerError("mysql_wait", f"MySQL did not become ready in {timeout}s")


def _run_artisan_streaming(args: list[str], log_queue: LogQueue, phase: str) -> None:
    cmd = ["docker", "exec", "alpha_panel_web", "php", "artisan", *args]
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
        raise InstallerError(phase, f"artisan {args[0]} exited with {rc}")


def run_migrations(log_queue: LogQueue) -> None:
    _run_artisan_streaming(["migrate", "--force"], log_queue, "migrate")


def seed_php_versions(log_queue: LogQueue) -> None:
    _run_artisan_streaming(
        ["db:seed", "--class=PhpVersionSeeder", "--force"],
        log_queue,
        "seed",
    )


def create_admin_user(
    name: str,
    username: str,
    email: str,
    password: str,
    log_queue: LogQueue,
) -> None:
    _run_artisan_streaming(
        [
            "app:add-admin-user",
            f"--name={name}",
            f"--username={username}",
            f"--email={email}",
            f"--password={password}",
        ],
        log_queue,
        "admin_user",
    )
