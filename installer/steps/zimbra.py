"""Installer step for the optional Zimbra bridge.

The installer never deploys Zimbra itself — Zimbra is always external.
We only capture connection settings so the panel can talk to it.
"""

from __future__ import annotations

from typing import Any
from urllib.parse import urlparse


def validate_zimbra_form(form: dict[str, Any]) -> list[str]:
    """Return user-facing validation errors for the zimbra-config wizard step."""
    errors: list[str] = []
    if not form.get("zimbra_enabled"):
        return errors

    url = form.get("zimbra_admin_url", "").strip()
    if not url:
        errors.append("ZIMBRA_ADMIN_URL is required when Zimbra is enabled.")
    else:
        parsed = urlparse(url)
        if parsed.scheme not in {"http", "https"}:
            errors.append("ZIMBRA_ADMIN_URL must start with http:// or https://.")

    if not form.get("zimbra_admin_user"):
        errors.append("ZIMBRA_ADMIN_USER is required when Zimbra is enabled.")
    if not form.get("zimbra_admin_password"):
        errors.append("ZIMBRA_ADMIN_PASSWORD is required when Zimbra is enabled.")
    if not form.get("zimbra_default_mx_host"):
        errors.append("ZIMBRA_DEFAULT_MX_HOST is required when Zimbra is enabled.")

    priority = form.get("zimbra_default_mx_priority", 10)
    try:
        priority_int = int(priority)
        if not 0 <= priority_int <= 65535:
            errors.append("ZIMBRA_DEFAULT_MX_PRIORITY must be between 0 and 65535.")
    except (TypeError, ValueError):
        errors.append("ZIMBRA_DEFAULT_MX_PRIORITY must be an integer.")

    timeout = form.get("zimbra_timeout_seconds", 15)
    try:
        timeout_int = int(timeout)
        if not 1 <= timeout_int <= 120:
            errors.append("ZIMBRA_TIMEOUT_SECONDS must be between 1 and 120.")
    except (TypeError, ValueError):
        errors.append("ZIMBRA_TIMEOUT_SECONDS must be an integer.")

    return errors
