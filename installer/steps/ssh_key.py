from __future__ import annotations

import os
import subprocess
from pathlib import Path


def ensure_ssh_key(
    key_dir: Path,
    authorized_keys_path: Path,
    comment: str,
) -> Path:
    key_dir.mkdir(parents=True, exist_ok=True)
    priv = key_dir / "alphapanel_ed25519"
    pub = key_dir / "alphapanel_ed25519.pub"

    if not priv.exists() or not pub.exists():
        subprocess.check_call(
            [
                "ssh-keygen",
                "-t",
                "ed25519",
                "-f",
                str(priv),
                "-N",
                "",
                "-C",
                comment,
            ]
        )
    os.chmod(priv, 0o600)
    os.chmod(pub, 0o644)

    pub_text = pub.read_text().strip()
    authorized_keys_path.parent.mkdir(parents=True, exist_ok=True)
    existing = authorized_keys_path.read_text() if authorized_keys_path.exists() else ""
    if pub_text not in existing:
        if existing and not existing.endswith("\n"):
            existing += "\n"
        authorized_keys_path.write_text(existing + pub_text + "\n")
    os.chmod(authorized_keys_path, 0o600)
    return priv
