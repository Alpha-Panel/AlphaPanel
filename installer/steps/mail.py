"""Installer steps for the optional Mailu external service.

These run after `env_writer.write_root_env` so MAIL_DATA_PATH and friends
are already in the root `.env`. Each function is idempotent — re-running the
installer with `mail_enabled=true` won't duplicate the include line.
"""

from __future__ import annotations

import os
import shutil
from pathlib import Path
from typing import Any


def setup_mail_external_service(project_dir: Path, form: dict[str, Any]) -> None:
    """If mail_enabled is true, materialize mailu.yaml and ensure local-services.yaml includes it."""
    if not form.get("mail_enabled"):
        return

    services_dir = project_dir / "external-services"
    src = services_dir / "mailu.example.yaml"
    dst = services_dir / "mailu.yaml"

    if not src.exists():
        raise FileNotFoundError(f"Missing template: {src}")

    if not dst.exists():
        shutil.copy(src, dst)

    local_services = services_dir / "local-services.yaml"
    if local_services.exists():
        content = local_services.read_text(encoding="utf-8")
    else:
        content = ""

    if "./mailu.yaml" in content:
        return

    if "include:" in content:
        content = content.rstrip() + "\n  - ./mailu.yaml\n"
    else:
        content = content.rstrip() + "\n\ninclude:\n  - ./mailu.yaml\n"

    local_services.write_text(content, encoding="utf-8")


def ensure_mail_data_dir(form: dict[str, Any]) -> None:
    """Create MAIL_DATA_PATH with 750 perms; safe to re-run."""
    if not form.get("mail_enabled"):
        return

    # Default lives under the compose project root so `docker compose` resolves
    # the volume relative path correctly. The installer's project_dir is the
    # absolute base; we anchor relative values there before mkdir.
    raw = form.get("mail_data_path", "./mail-data")
    project_dir = form.get("_project_dir")
    path = Path(raw)
    if not path.is_absolute() and project_dir:
        path = Path(project_dir) / raw.lstrip("./")
    path.mkdir(parents=True, exist_ok=True)
    try:
        os.chmod(path, 0o750)
    except PermissionError:
        # Not running as root — let the operator chmod manually.
        pass


def validate_mail_form(form: dict[str, Any]) -> list[str]:
    """Return user-facing validation errors for the mail-config wizard step."""
    errors: list[str] = []
    if not form.get("mail_enabled"):
        return errors

    if not form.get("mail_domain"):
        errors.append("MAIL_DOMAIN is required when mail is enabled.")
    if not form.get("mail_data_path"):
        errors.append("MAIL_DATA_PATH is required when mail is enabled.")

    webmail = form.get("mail_webmail", "snappymail")
    if webmail not in {"snappymail", "roundcube", "none"}:
        errors.append("MAIL_WEBMAIL must be one of: snappymail, roundcube, none.")

    antivirus = form.get("mail_antivirus", "none")
    if antivirus not in {"none", "clamav"}:
        errors.append("MAIL_ANTIVIRUS must be one of: none, clamav.")

    return errors
