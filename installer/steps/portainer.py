from __future__ import annotations

import re
import subprocess
import time
from pathlib import Path

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


def _session() -> requests.Session:
    """
    Portainer 2.20+ wraps its API in gorilla/csrf: a state-changing request needs the
    `_gorilla_csrf` cookie plus a matching X-CSRF-Token header, or it is rejected with
    "Forbidden - CSRF token not found in request".

    Portainer only issues that token to an *authenticated* request — before login the
    header comes back empty — so priming with an up-front GET does not work. Capture it
    off every response instead: by the time a POST goes out, the preceding GET has
    supplied both halves.
    """
    s = requests.Session()
    s.verify = False

    def _capture_csrf(resp, *args, **kwargs):
        token = resp.headers.get("X-CSRF-Token")
        if token:
            s.headers["X-CSRF-Token"] = token

    s.hooks["response"].append(_capture_csrf)
    return s


def _fail(phase: str, what: str, resp: requests.Response) -> InstallerError:
    body = resp.text[:200].replace("\n", " ").strip()
    return InstallerError(
        phase,
        f"{what} returned {resp.status_code}: {body or '(empty body)'}",
        detail={"body": resp.text[:500]},
    )


# Portainer 2.39 prints a one-off setup token at startup and refuses admin init
# without it: "Provide the X-Setup-Token header with the token printed in the server
# logs at startup." Reading it back out of the container log is the intended flow.
# The log line ends with a structured field, and the surrounding prose also contains
# the words "setup token", so anchor on the field itself:
#   ... require this setup token in the X-Setup-Token header. | setup_token=2640d34c...
_SETUP_TOKEN_RE = re.compile(r"setup_token=([A-Za-z0-9._\-]{16,})")


# Portainer colourises its own log and the colour reset lands between the field name
# and the value — "\x1b[36msetup_token=\x1b[0m05a2c2db..." — so the escapes have to go
# before the token can be matched.
_ANSI_RE = re.compile(r"\x1b\[[0-9;]*[A-Za-z]")


def _read_setup_token(container: str = "portainer") -> str | None:
    proc = subprocess.run(
        ["docker", "logs", container],
        capture_output=True,
        text=True,
        errors="replace",
    )
    text = _ANSI_RE.sub("", f"{proc.stdout}\n{proc.stderr}")
    # The token is printed once per start, so a restarted container has several in
    # its log. Only the last one is still valid.
    matches = _SETUP_TOKEN_RE.findall(text)
    return matches[-1] if matches else None


def _post_admin_init(base_url: str, payload: dict) -> requests.Response:
    s = _session()
    token = _read_setup_token()
    if token:
        s.headers["X-Setup-Token"] = token
    return s.post(f"{base_url}/api/users/admin/init", json=payload, timeout=10)


def init_portainer_admin(
    base_url: str,
    username: str,
    password: str,
    project_dir: Path | None = None,
) -> None:
    payload = {"Username": username, "Password": password}
    resp = _post_admin_init(base_url, payload)

    if resp.status_code == 403:
        # Two things produce a 403 here: a container still running without the
        # `--no-setup-token` flag from compose, and the ~5 minute window after which
        # Portainer disables admin init entirely (a `compose up --build` that really
        # builds images blows past it). Recreating from compose fixes both, and prints
        # a fresh setup token for the log-scraping fallback above.
        # check=False on purpose: if the recreate itself fails, the retry below still
        # produces a readable InstallerError instead of a CalledProcessError traceback.
        if project_dir is not None:
            subprocess.run(
                ["docker", "compose", "up", "-d", "--force-recreate", "portainer"],
                cwd=str(project_dir),
                check=False,
                capture_output=True,
                text=True,
            )
        else:
            subprocess.run(["docker", "restart", "portainer"], check=False, capture_output=True, text=True)
        wait_for_portainer(base_url, timeout=120.0)
        resp = _post_admin_init(base_url, payload)

    if resp.status_code == 409:
        # Already initialised — acceptable for resume.
        return
    if resp.status_code != 200:
        if resp.status_code == 403 and _read_setup_token() is None:
            raise InstallerError(
                "portainer_admin_init",
                "Portainer demands a setup token but none was found in `docker logs portainer`. "
                "Read the token from that log and create the admin manually in the Portainer UI, "
                "then resume.",
            )
        raise _fail("portainer_admin_init", "Portainer admin init", resp)


def create_access_token(base_url: str, username: str, password: str) -> str:
    s = _session()
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

    # That GET is also what hands us the CSRF token. If it failed, any authenticated
    # GET will do — without a token the POST below is a guaranteed 403.
    if "X-CSRF-Token" not in s.headers:
        s.get(f"{base_url}/api/endpoints", timeout=10)

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
    s = _session()
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
    payload = {
        "Name": "local",
        "EndpointCreationType": "2",  # Agent
        "URL": "tcp://portainer_agent:9001",
        # The agent always speaks TLS with a self-signed cert issued for "localhost",
        # so connecting by service name fails verification. Portainer ignores the skip
        # flags unless TLS itself is switched on, which is why TLSSkipVerify alone
        # produced "certificate is valid for localhost, not portainer_agent".
        "TLS": "true",
        "TLSSkipVerify": "true",
        "TLSSkipClientVerify": "true",
        "GroupID": "1",
        "PublicURL": "",
    }
    create = s.post(f"{base_url}/api/endpoints", data=payload, timeout=30)

    if create.status_code >= 400 and "already paired" in create.text.lower():
        # The agent bonds to the first Portainer that reaches it and refuses every
        # other one until restarted. Wiping Portainer's database while leaving the
        # agent container up — a partial reset — lands exactly here.
        subprocess.run(
            ["docker", "restart", "portainer-agent"],
            check=False,
            capture_output=True,
            text=True,
        )
        time.sleep(5)
        create = s.post(f"{base_url}/api/endpoints", data=payload, timeout=30)

    if create.status_code not in (200, 201):
        raise _fail("portainer_endpoint", "Agent endpoint creation", create)
    return int(create.json()["Id"])
