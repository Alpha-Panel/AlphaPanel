from __future__ import annotations

import time

import requests

from installer.errors import InstallerError


def wait_for_portainer(base_url: str, timeout: float = 180.0, interval: float = 3.0) -> None:
    deadline = time.monotonic() + timeout
    last_error: str = "none"
    while time.monotonic() < deadline:
        try:
            resp = requests.get(f"{base_url}/api/status", timeout=5, verify=False)
            if resp.status_code == 200:
                return
            last_error = f"status {resp.status_code}"
        except Exception as e:
            last_error = str(e)
        time.sleep(interval)
    raise InstallerError(
        "portainer_wait",
        f"Portainer did not become ready in {timeout}s",
        detail={"last_error": last_error},
    )


def init_portainer_admin(base_url: str, username: str, password: str) -> None:
    resp = requests.post(
        f"{base_url}/api/users/admin/init",
        json={"Username": username, "Password": password},
        timeout=10,
        verify=False,
    )
    if resp.status_code == 409:
        # Already initialised — acceptable for resume.
        return
    if resp.status_code != 200:
        raise InstallerError(
            "portainer_admin_init",
            f"Portainer admin init returned {resp.status_code}",
            detail={"body": resp.text[:500]},
        )


def create_access_token(base_url: str, username: str, password: str) -> str:
    auth = requests.post(
        f"{base_url}/api/auth",
        json={"Username": username, "Password": password},
        timeout=10,
        verify=False,
    )
    if auth.status_code != 200:
        raise InstallerError(
            "portainer_auth",
            f"Portainer auth returned {auth.status_code}",
            detail={"body": auth.text[:500]},
        )
    jwt = auth.json()["jwt"]

    whoami = requests.get(
        f"{base_url}/api/users/me",
        headers={"Authorization": f"Bearer {jwt}"},
        timeout=10,
        verify=False,
    )
    user_id = whoami.json()["Id"] if whoami.status_code == 200 else 1

    tok = requests.post(
        f"{base_url}/api/users/{user_id}/tokens",
        headers={"Authorization": f"Bearer {jwt}"},
        json={"description": "AlphaPanel", "password": password},
        timeout=10,
        verify=False,
    )
    if tok.status_code != 200:
        raise InstallerError(
            "portainer_token",
            f"Token creation returned {tok.status_code}",
            detail={"body": tok.text[:500]},
        )
    return tok.json()["rawAPIKey"]


def detect_endpoint_id(base_url: str, api_key: str) -> int:
    resp = requests.get(
        f"{base_url}/api/endpoints",
        headers={"X-API-Key": api_key},
        timeout=10,
        verify=False,
    )
    if resp.status_code != 200:
        return 1
    body = resp.json()
    if isinstance(body, list) and body:
        return int(body[0]["Id"])
    return 1


def ensure_agent_endpoint(base_url: str, api_key: str) -> int:
    """
    Create the Portainer agent endpoint (portainer_agent:9001) if none exists.
    Returns the endpoint ID.
    """
    headers = {"X-API-Key": api_key}

    # Check existing endpoints first
    resp = requests.get(f"{base_url}/api/endpoints", headers=headers, timeout=10, verify=False)
    if resp.status_code == 200:
        body = resp.json()
        if isinstance(body, list) and body:
            return int(body[0]["Id"])

    # No endpoints — create the agent endpoint
    create = requests.post(
        f"{base_url}/api/endpoints",
        headers=headers,
        data={
            "Name": "local",
            "EndpointCreationType": "2",  # Agent
            "URL": "tcp://portainer_agent:9001",
            "TLSSkipVerify": "true",
            "GroupID": "1",
            "PublicURL": "",
        },
        timeout=30,
        verify=False,
    )
    if create.status_code not in (200, 201):
        raise InstallerError(
            "portainer_endpoint",
            f"Agent endpoint creation returned {create.status_code}",
            detail={"body": create.text[:500]},
        )
    return int(create.json()["Id"])
