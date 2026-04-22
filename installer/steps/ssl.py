from __future__ import annotations

import subprocess

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
