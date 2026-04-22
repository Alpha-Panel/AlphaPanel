from __future__ import annotations

import subprocess

from installer.errors import InstallerError
from installer.log_queue import LogQueue


def reload_caddy(log_queue: LogQueue) -> None:
    """
    Run `php artisan panel:apply` inside the alpha_panel_web container.
    panel:apply regenerates the Caddyfiles and asks Caddy to reload via its
    admin API (which the panel already knows how to reach through the
    FrankenPHP container).
    """
    cmd = ["docker", "exec", "alpha_panel_web", "php", "artisan", "panel:apply"]
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
        raise InstallerError("caddy_reload", f"panel:apply exited with code {rc}")
