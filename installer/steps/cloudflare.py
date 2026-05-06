from __future__ import annotations

import os
from pathlib import Path

import requests

from installer.errors import InstallerError

_CF_BASE = "https://api.cloudflare.com/client/v4"


def write_cloudflare_ini(target: Path, token: str) -> None:
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_text(f"dns_cloudflare_api_token = {token}\n")
    os.chmod(target, 0o600)


def _cf_get(path: str, token: str, timeout: int = 10) -> requests.Response:
    return requests.get(
        f"{_CF_BASE}{path}",
        headers={"Authorization": f"Bearer {token}"},
        timeout=timeout,
    )


def verify_token(token: str) -> bool:
    """
    Verify a Cloudflare API token.

    /v4/user/tokens/verify requires the "User: Read" permission which
    DNS-only tokens don't have — it will return 401.  We fall back to
    /v4/zones?per_page=1 which any Zone-scoped token can reach.
    """
    try:
        resp = _cf_get("/user/tokens/verify", token)
    except requests.RequestException as e:
        raise InstallerError("cloudflare_verify", f"Request failed: {e}") from e

    if resp.status_code == 200:
        payload = resp.json()
        if payload.get("success") and payload.get("result", {}).get("status") == "active":
            return True
        raise InstallerError(
            "cloudflare_verify",
            "Token is not active",
            detail=payload,
        )

    # DNS-only tokens lack User:Read — 401 here is expected; probe via zones.
    if resp.status_code == 401:
        try:
            zone_resp = _cf_get("/zones?per_page=1", token)
        except requests.RequestException as e:
            raise InstallerError("cloudflare_verify", f"Request failed: {e}") from e

        if zone_resp.status_code == 200 and zone_resp.json().get("success"):
            return True

        raise InstallerError(
            "cloudflare_verify",
            "Token rejected by Cloudflare (check Zone:DNS:Edit permission)",
            detail={"verify_status": resp.status_code, "zones_status": zone_resp.status_code},
        )

    raise InstallerError(
        "cloudflare_verify",
        f"Cloudflare returned {resp.status_code}",
        detail={"body": resp.text[:500]},
    )
