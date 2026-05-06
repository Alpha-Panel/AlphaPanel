from __future__ import annotations

import subprocess
import time
from pathlib import Path

from installer.errors import InstallerError

_CERT_DEPENDENT_CONTAINERS = ("alpha_panel_web", "frankenphp")


def generate_self_signed(letsencrypt_dir: Path, base_domain: str) -> None:
    """
    Write a temporary self-signed cert at the Let's Encrypt path so Caddy can
    start before the real DNS-01 cert is issued.  The ssl phase overwrites this.

    If containers were already started (resume case), restarts them so they pick
    up the newly created cert file.
    """
    live_dir = letsencrypt_dir / "live" / base_domain
    fullchain = live_dir / "fullchain.pem"
    privkey = live_dir / "privkey.pem"

    if fullchain.exists() and privkey.exists():
        return

    live_dir.mkdir(parents=True, exist_ok=True)

    result = subprocess.run(
        [
            "openssl", "req", "-x509",
            "-newkey", "rsa:2048",
            "-keyout", str(privkey),
            "-out", str(fullchain),
            "-days", "30",
            "-nodes",
            "-subj", f"/CN=*.{base_domain}",
        ],
        capture_output=True,
        text=True,
    )
    if result.returncode != 0:
        raise InstallerError(
            "ssl_bootstrap",
            "openssl self-signed cert generation failed",
            detail={"stderr": result.stderr[:500]},
        )

    # Restart containers that hold the cert path open (no-op if not yet started).
    for container in _CERT_DEPENDENT_CONTAINERS:
        subprocess.run(["docker", "restart", container], capture_output=True)

    # Give them a moment to come up before compose_up or later phases proceed.
    time.sleep(5)
