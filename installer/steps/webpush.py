from __future__ import annotations

import subprocess
from pathlib import Path

from installer.errors import InstallerError
from installer.log_queue import LogQueue


def _replace_env_line(text: str, key: str, value: str) -> str:
    import re
    pattern = rf"^#?\s*{re.escape(key)}=.*$"
    replacement = f"{key}={value}"
    if re.search(pattern, text, flags=re.MULTILINE):
        return re.sub(pattern, replacement, text, flags=re.MULTILINE)
    if not text.endswith("\n"):
        text += "\n"
    return text + replacement + "\n"


def setup_webpush_vapid(log_queue: LogQueue, admin_email: str, laravel_env: Path) -> None:
    # Generate VAPID keys inside the container (writes VAPID_PUBLIC_KEY and
    # VAPID_PRIVATE_KEY to the bind-mounted .env automatically)
    cmd = ["docker", "exec", "alpha_panel_web", "php", "artisan", "webpush:vapid"]
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
        raise InstallerError("webpush_vapid", f"webpush:vapid exited with {rc}")

    # Set VAPID_SUBJECT in the .env on the host side (bind-mount keeps container in sync)
    text = laravel_env.read_text()
    text = _replace_env_line(text, "VAPID_SUBJECT", f"mailto:{admin_email}")
    laravel_env.write_text(text)
    log_queue.put({"type": "line", "text": f"[webpush] VAPID_SUBJECT set to mailto:{admin_email}"})
