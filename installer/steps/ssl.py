from __future__ import annotations

import subprocess
import time

from installer.errors import InstallerError
from installer.log_queue import LogQueue


def issue_panel_certificate(
    base_domain: str,
    admin_email: str,
    token_file: str,
    container: str,
    log_queue: LogQueue,
) -> None:
    cmd = [
        "docker",
        "exec",
        container,
        "php",
        "artisan",
        "panel:issue-installer-cert",
        f"--base={base_domain}",
        f"--token-file={token_file}",
        f"--admin-email={admin_email}",
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
        raise InstallerError("ssl_issue", f"panel:issue-installer-cert exited with code {rc}")


def restart_panel_web(
    log_queue: LogQueue,
    container: str = "alpha_panel_web",
    timeout: float = 90.0,
) -> None:
    """
    The panel's own Caddy reads its certificate at startup, so the certificate issued
    a moment ago is not served until the container restarts — the installer used to
    finish with the panel still presenting the self-signed bootstrap cert, and every
    first visit hit an SSL warning.
    """
    cmd = ["docker", "restart", container]
    log_queue.put({"type": "line", "text": f"$ {' '.join(cmd)}"})
    proc = subprocess.run(cmd, capture_output=True, text=True)
    if proc.returncode != 0:
        raise InstallerError(
            "panel_restart",
            f"Could not restart {container}",
            detail={"stderr": proc.stderr[:500]},
        )

    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        state = subprocess.run(
            ["docker", "inspect", "-f", "{{.State.Running}}", container],
            capture_output=True,
            text=True,
        )
        if state.stdout.strip() == "true":
            log_queue.put({"type": "line", "text": f"{container} is back up with the new certificate"})
            return
        time.sleep(2)

    raise InstallerError("panel_restart", f"{container} did not come back up within {timeout}s")
