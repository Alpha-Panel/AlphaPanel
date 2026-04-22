from __future__ import annotations

import os
from pathlib import Path

import requests

from installer.errors import InstallerError

_VERIFY_URL = "https://api.cloudflare.com/client/v4/user/tokens/verify"


def write_cloudflare_ini(target: Path, token: str) -> None:
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_text(f"dns_cloudflare_api_token = {token}\n")
    os.chmod(target, 0o600)


def verify_token(token: str) -> bool:
    try:
        response = requests.get(
            _VERIFY_URL,
            headers={"Authorization": f"Bearer {token}"},
            timeout=10,
        )
    except requests.RequestException as e:
        raise InstallerError("cloudflare_verify", f"Request failed: {e}") from e

    if response.status_code != 200:
        raise InstallerError(
            "cloudflare_verify",
            f"Cloudflare returned {response.status_code}",
            detail={"body": response.text[:500]},
        )
    payload = response.json()
    if not payload.get("success") or payload.get("result", {}).get("status") != "active":
        raise InstallerError(
            "cloudflare_verify",
            "Token is not active",
            detail=payload,
        )
    return True
