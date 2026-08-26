from __future__ import annotations

import subprocess
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


def _session(base_url: str) -> requests.Session:
    """
    Portainer 2.20+ wraps its API in gorilla/csrf. Every state-changing request must
    carry the `_gorilla_csrf` cookie plus the matching X-CSRF-Token header, or it is
    rejected with a bare 403 before any auth or admin check runs. A single GET primes
    both: the cookie lands in the session jar, the token comes back as a header.
    """
    s = requests.Session()
    s.verify = False
    try:
        probe = s.get(f"{base_url}/api/status", timeout=10)
        token = probe.headers.get("X-CSRF-Token")
        if token:
            s.headers["X-CSRF-Token"] = token
    except requests.RequestException:
        pass
    return s


def _fail(phase: str, what: str, resp: requests.Response) -> InstallerError:
    body = resp.text[:200].replace("\n", " ").strip()
    return InstallerError(
        phase,
        f"{what} returned {resp.status_code}: {body or '(empty body)'}",
        detail={"body": resp.text[:500]},
    )


def init_portainer_admin(base_url: str, username: str, password: str) -> None:
    payload = {"Username": username, "Password": password}
    s = _session(base_url)
    resp = s.post(f"{base_url}/api/users/admin/init", json=payload, timeout=10)

    if resp.status_code == 403:
        # Portainer also disables admin init once the instance has been up ~5 minutes
        # without an admin. A `compose up --build` that actually builds images blows
        # past that window, and only a restart reopens it.
        subprocess.run(["docker", "restart", "portainer"], check=True, capture_output=True, text=True)
        wait_for_portainer(base_url, timeout=120.0)
        s = _session(base_url)
        resp = s.post(f"{base_url}/api/users/admin/init", json=payload, timeout=10)

    if resp.status_code == 409:
        # Already initialised — acceptable for resume.
        return
    if resp.status_code != 200:
        raise _fail("portainer_admin_init", "Portainer admin init", resp)


def create_access_token(base_url: str, username: str, password: str) -> str:
    s = _session(base_url)
    auth = s.post(
        f"{base_url}/api/auth",
        json={"Username": username, "Password": password},
        timeout=10,
    )
    if auth.status_code != 200:
        raise _fail("portainer_auth", "Portainer auth", auth)
    jwt = auth.json()["jwt"]
    s.headers["Authorization"] = f"Bearer {jwt}"

    whoami = s.get(f"{base_url}/api/users/me", timeout=10)
    user_id = whoami.json()["Id"] if whoami.status_code == 200 else 1

    tok = s.post(
        f"{base_url}/api/users/{user_id}/tokens",
        json={"description": "AlphaPanel", "password": password},
        timeout=10,
    )
    if tok.status_code != 200:
        raise _fail("portainer_token", "Token creation", tok)
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
    Returns the endpoint ID of the agent endpoint (Type=2).
    """
    s = _session(base_url)
    s.headers["X-API-Key"] = api_key

    # Check existing endpoints; prefer agent type (Type=2)
    resp = s.get(f"{base_url}/api/endpoints", timeout=10)
    if resp.status_code == 200:
        body = resp.json()
        if isinstance(body, list) and body:
            for ep in body:
                if ep.get("Type") == 2:
                    return int(ep["Id"])
            # No agent endpoint found, fall through to creation

    # No endpoints — create the agent endpoint
    create = s.post(
        f"{base_url}/api/endpoints",
        data={
            "Name": "local",
            "EndpointCreationType": "2",  # Agent
            "URL": "tcp://portainer_agent:9001",
            "TLSSkipVerify": "true",
            "GroupID": "1",
            "PublicURL": "",
        },
        timeout=30,
    )
    if create.status_code not in (200, 201):
        raise _fail("portainer_endpoint", "Agent endpoint creation", create)
    return int(create.json()["Id"])
