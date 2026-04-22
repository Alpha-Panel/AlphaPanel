# Web Installer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the 720-line Bash `install.sh` with a minimal bootstrap that launches a Python Flask wizard, which collects configuration, writes `.env` files, brings up Docker, initialises Portainer, runs migrations, and issues a Let's Encrypt wildcard cert via Cloudflare DNS-01 — all streamed to the browser over SSE.

**Architecture:** Bash bootstrap installs prerequisites (Docker, Python, venv) and launches `installer.app`. Flask exposes a single-page wizard with 8 steps; SSE streams install logs. A new Laravel artisan command `panel:issue-installer-cert` wraps the existing `AcmeService::requestCertificateDnsCloudflare` for the base domain wildcard. Idempotent per step; `.installer_state.json` tracks progress and allows resume; a Reset endpoint tears everything down.

**Tech Stack:** Bash (bootstrap only), Python 3.10+, Flask, `requests`, standard library `subprocess`/`threading`/`os`. Laravel 12 for the new artisan command. Existing Docker Compose stack untouched.

**Source spec:** `docs/superpowers/specs/2026-04-21-web-installer-design.md`

**Git policy:** This project's `CLAUDE.md` forbids Claude from creating git commits. Each task ends with **"Stage"** (running `git add`) — the user commits manually.

---

## File Structure

Files created or modified, grouped by responsibility:

**Bootstrap:**
- Modify: `install.sh` — trim to ~50 lines, install prereqs + launch Python
- Modify: `.gitignore` — add `.installer-venv/`, `.installer_state.json`

**Python installer package:**
- Create: `installer/__init__.py`
- Create: `installer/app.py` — Flask routes, SSE, install thread orchestration
- Create: `installer/state.py` — JSON state file read/write
- Create: `installer/secrets_gen.py` — hex + base64 secret generation
- Create: `installer/errors.py` — `InstallerError` exception
- Create: `installer/log_queue.py` — thread-safe log queue for SSE
- Create: `installer/steps/__init__.py`
- Create: `installer/steps/system.py` — OS/IP detection
- Create: `installer/steps/env_writer.py` — root `.env` + Laravel `.env`
- Create: `installer/steps/caddyfile.py` — Jenkins + base-domain Caddyfiles
- Create: `installer/steps/cloudflare.py` — `secrets/cloudflare.ini` + token verify
- Create: `installer/steps/ssh_key.py` — ed25519 + `authorized_keys`
- Create: `installer/steps/directories.py` — `mkdir -p` for all data dirs
- Create: `installer/steps/compose.py` — `docker compose up -d --build` with stdout pipe
- Create: `installer/steps/portainer.py` — wait, admin init, token, endpoint
- Create: `installer/steps/database.py` — migrate, seed, admin user
- Create: `installer/steps/ssl.py` — invoke `panel:issue-installer-cert`
- Create: `installer/steps/caddy_reload.py` — reload via Portainer exec
- Create: `installer/steps/reset.py` — `docker compose down -v` + cleanup
- Create: `installer/templates/wizard.html` — single-page wizard
- Create: `installer/static/wizard.js` — step state machine + EventSource
- Create: `installer/static/wizard.css` — minimal styling

**Python tests:**
- Create: `installer/tests/__init__.py`
- Create: `installer/tests/test_secrets_gen.py`
- Create: `installer/tests/test_state.py`
- Create: `installer/tests/test_env_writer.py`
- Create: `installer/tests/test_system.py`

**Laravel artisan command:**
- Create: `alpha-panel/web/httpdocs/app/Console/Commands/IssueInstallerCertCommand.php`
- Create: `alpha-panel/web/httpdocs/tests/Feature/Console/IssueInstallerCertCommandTest.php`

**Dependency declarations:**
- Create: `installer/requirements.txt` — `flask`, `requests`

---

## Task 0: Prep — Branch & Dir Structure

**Files:**
- Create: `installer/`, `installer/steps/`, `installer/tests/`, `installer/templates/`, `installer/static/`
- Create: `docs/superpowers/plans/` (already done for this file)

- [ ] **Step 1: Verify current branch and clean state**

Run: `git status && git branch --show-current`
Expected: Clean working tree, current branch visible. If dirty, stop and let the user reconcile.

- [ ] **Step 2: Create installer package skeleton**

Run:
```bash
mkdir -p installer/steps installer/tests installer/templates installer/static
touch installer/__init__.py installer/steps/__init__.py installer/tests/__init__.py
```

- [ ] **Step 3: Write `installer/requirements.txt`**

```
flask==3.*
requests==2.*
```

- [ ] **Step 4: Update `.gitignore`**

Append these two lines if not already present:

```
.installer-venv/
.installer_state.json
```

Run: `grep -E '^\.installer-(venv|state)' .gitignore` → expect both lines.

- [ ] **Step 5: Stage**

```bash
git add installer/ .gitignore docs/superpowers/plans/2026-04-21-web-installer.md
```

---

## Task 1: `installer/errors.py` — Exception Class

**Files:**
- Create: `installer/errors.py`
- Test: `installer/tests/test_errors.py`

- [ ] **Step 1: Write the failing test**

`installer/tests/test_errors.py`:

```python
from installer.errors import InstallerError


def test_installer_error_carries_phase_and_detail():
    err = InstallerError(phase="portainer", message="admin init failed", detail={"status": 409})
    assert err.phase == "portainer"
    assert err.message == "admin init failed"
    assert err.detail == {"status": 409}
    assert "portainer" in str(err)
    assert "admin init failed" in str(err)


def test_installer_error_detail_defaults_to_empty_dict():
    err = InstallerError(phase="x", message="y")
    assert err.detail == {}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `python -m pytest installer/tests/test_errors.py -v`
Expected: FAIL with `ModuleNotFoundError: No module named 'installer.errors'`

- [ ] **Step 3: Write minimal implementation**

`installer/errors.py`:

```python
from __future__ import annotations

from typing import Any


class InstallerError(Exception):
    """Raised by any installer step. Carries a structured phase name and optional detail."""

    def __init__(self, phase: str, message: str, detail: dict[str, Any] | None = None) -> None:
        super().__init__(f"[{phase}] {message}")
        self.phase = phase
        self.message = message
        self.detail = detail or {}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `python -m pytest installer/tests/test_errors.py -v`
Expected: 2 passed.

- [ ] **Step 5: Stage**

```bash
git add installer/errors.py installer/tests/test_errors.py
```

---

## Task 2: `installer/secrets_gen.py` — Secret Generation

**Files:**
- Create: `installer/secrets_gen.py`
- Test: `installer/tests/test_secrets_gen.py`

- [ ] **Step 1: Write the failing test**

`installer/tests/test_secrets_gen.py`:

```python
import re
from installer.secrets_gen import gen_hex, gen_b64, gen_all_panel_secrets


def test_gen_hex_default_32_bytes_returns_64_hex_chars():
    out = gen_hex()
    assert re.fullmatch(r"[0-9a-f]{64}", out)


def test_gen_hex_custom_length():
    assert len(gen_hex(16)) == 32  # 16 bytes → 32 hex chars
    assert len(gen_hex(4)) == 8


def test_gen_b64_returns_base64_without_newline():
    out = gen_b64()
    assert "\n" not in out
    assert re.fullmatch(r"[A-Za-z0-9+/=]+", out)
    # 32 bytes base64 → 44 chars with padding
    assert len(out) == 44


def test_gen_all_panel_secrets_returns_full_dict():
    secrets = gen_all_panel_secrets()
    expected_keys = {
        "mysql_root_password",
        "meilisearch_master_key",
        "alpha_panel_meilisearch_master_key",
        "postgresql_password",
        "n8n_encryption_key",
        "pma_blowfish_secret",
        "vaultwarden_db_password",
        "panel_db_pass",
        "ftp_mysql_password",
        "crowdsec_firewall_bouncer_key",
        "crowdsec_dashboard_api_key",
        "update_agent_secret",
        "reverb_app_id",
        "reverb_app_key",
        "reverb_app_secret",
        "app_key",
        "code_server_password",
    }
    assert set(secrets.keys()) == expected_keys
    for value in secrets.values():
        assert len(value) > 0
    # APP_KEY must start with "base64:"
    assert secrets["app_key"].startswith("base64:")


def test_gen_all_panel_secrets_returns_unique_values_per_call():
    a = gen_all_panel_secrets()
    b = gen_all_panel_secrets()
    assert a != b
```

- [ ] **Step 2: Run test to verify it fails**

Run: `python -m pytest installer/tests/test_secrets_gen.py -v`
Expected: FAIL with import error.

- [ ] **Step 3: Write implementation**

`installer/secrets_gen.py`:

```python
from __future__ import annotations

import base64
import secrets


def gen_hex(num_bytes: int = 32) -> str:
    """Return a random hex string of `2 * num_bytes` characters."""
    return secrets.token_hex(num_bytes)


def gen_b64(num_bytes: int = 32) -> str:
    """Return a base64-encoded random string, no trailing newline."""
    return base64.b64encode(secrets.token_bytes(num_bytes)).decode("ascii")


def gen_all_panel_secrets() -> dict[str, str]:
    """
    Generate every internal credential needed by the stack.

    Returns a flat dict mirroring the variable names used in root `.env`
    and Laravel `.env`, in snake_case. The caller maps keys to the UPPER_CASE
    env var names.
    """
    return {
        "mysql_root_password": gen_hex(16),
        "meilisearch_master_key": gen_hex(32),
        "alpha_panel_meilisearch_master_key": gen_hex(20),
        "postgresql_password": gen_hex(16),
        "n8n_encryption_key": gen_hex(32),
        "pma_blowfish_secret": gen_hex(16),
        "vaultwarden_db_password": gen_hex(16),
        "panel_db_pass": gen_hex(16),
        "ftp_mysql_password": gen_hex(16),
        "crowdsec_firewall_bouncer_key": gen_hex(32),
        "crowdsec_dashboard_api_key": gen_hex(32),
        "update_agent_secret": gen_hex(32),
        "reverb_app_id": gen_hex(4),
        "reverb_app_key": gen_hex(16),
        "reverb_app_secret": gen_hex(32),
        "app_key": f"base64:{gen_b64(32)}",
        "code_server_password": gen_hex(12),
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `python -m pytest installer/tests/test_secrets_gen.py -v`
Expected: 5 passed.

- [ ] **Step 5: Stage**

```bash
git add installer/secrets_gen.py installer/tests/test_secrets_gen.py
```

---

## Task 3: `installer/state.py` — State File I/O

**Files:**
- Create: `installer/state.py`
- Test: `installer/tests/test_state.py`

- [ ] **Step 1: Write the failing test**

`installer/tests/test_state.py`:

```python
import json
from pathlib import Path

import pytest

from installer.state import InstallerState, load_state, save_state, clear_state


@pytest.fixture
def state_path(tmp_path: Path) -> Path:
    return tmp_path / ".installer_state.json"


def test_load_state_returns_none_when_file_missing(state_path: Path):
    assert load_state(state_path) is None


def test_save_then_load_round_trip(state_path: Path):
    original = InstallerState(
        form={"base_domain": "example.com"},
        generated_secrets={"mysql_root_password": "abc"},
        completed_phases=["secrets", "env"],
        current_phase="compose_up",
        last_error=None,
    )
    save_state(state_path, original)
    loaded = load_state(state_path)
    assert loaded == original


def test_save_state_writes_mode_600(state_path: Path):
    state = InstallerState()
    save_state(state_path, state)
    mode = state_path.stat().st_mode & 0o777
    assert mode == 0o600


def test_save_state_includes_version_and_timestamps(state_path: Path):
    state = InstallerState(form={"x": "y"})
    save_state(state_path, state)
    raw = json.loads(state_path.read_text())
    assert raw["version"] == 1
    assert "started_at" in raw
    assert "updated_at" in raw


def test_clear_state_removes_file(state_path: Path):
    save_state(state_path, InstallerState())
    assert state_path.exists()
    clear_state(state_path)
    assert not state_path.exists()


def test_clear_state_is_idempotent(state_path: Path):
    clear_state(state_path)  # should not raise
```

- [ ] **Step 2: Run test to verify it fails**

Run: `python -m pytest installer/tests/test_state.py -v`
Expected: FAIL with import error.

- [ ] **Step 3: Write implementation**

`installer/state.py`:

```python
from __future__ import annotations

import json
import os
from dataclasses import dataclass, field, asdict
from datetime import datetime, timezone
from pathlib import Path
from typing import Any


@dataclass
class InstallerState:
    form: dict[str, Any] = field(default_factory=dict)
    generated_secrets: dict[str, str] = field(default_factory=dict)
    completed_phases: list[str] = field(default_factory=list)
    current_phase: str | None = None
    last_error: dict[str, Any] | None = None


def load_state(path: Path) -> InstallerState | None:
    if not path.exists():
        return None
    raw = json.loads(path.read_text())
    return InstallerState(
        form=raw.get("form", {}),
        generated_secrets=raw.get("generated_secrets", {}),
        completed_phases=raw.get("completed_phases", []),
        current_phase=raw.get("current_phase"),
        last_error=raw.get("last_error"),
    )


def save_state(path: Path, state: InstallerState) -> None:
    now = datetime.now(timezone.utc).isoformat()
    payload: dict[str, Any] = {"version": 1}
    existing = None
    if path.exists():
        try:
            existing = json.loads(path.read_text())
        except json.JSONDecodeError:
            existing = None
    payload["started_at"] = (existing or {}).get("started_at", now)
    payload["updated_at"] = now
    payload.update(asdict(state))
    path.write_text(json.dumps(payload, indent=2))
    os.chmod(path, 0o600)


def clear_state(path: Path) -> None:
    if path.exists():
        path.unlink()
```

- [ ] **Step 4: Run test to verify it passes**

Run: `python -m pytest installer/tests/test_state.py -v`
Expected: 6 passed.

- [ ] **Step 5: Stage**

```bash
git add installer/state.py installer/tests/test_state.py
```

---

## Task 4: `installer/log_queue.py` — Thread-Safe Log Queue

**Files:**
- Create: `installer/log_queue.py`
- Test: `installer/tests/test_log_queue.py`

- [ ] **Step 1: Write the failing test**

`installer/tests/test_log_queue.py`:

```python
import threading
import time

from installer.log_queue import LogQueue


def test_put_and_stream_yields_all_messages():
    q = LogQueue()
    q.put({"type": "line", "text": "hello"})
    q.put({"type": "line", "text": "world"})
    q.close()
    collected = list(q.stream())
    assert collected == [
        {"type": "line", "text": "hello"},
        {"type": "line", "text": "world"},
    ]


def test_stream_yields_from_concurrent_producer():
    q = LogQueue()

    def producer():
        for i in range(5):
            q.put({"type": "line", "text": f"msg-{i}"})
            time.sleep(0.01)
        q.close()

    threading.Thread(target=producer).start()

    collected = []
    for item in q.stream():
        collected.append(item)
    assert len(collected) == 5
    assert collected[0]["text"] == "msg-0"
    assert collected[-1]["text"] == "msg-4"


def test_put_after_close_raises():
    q = LogQueue()
    q.close()
    try:
        q.put({"type": "line", "text": "x"})
        assert False, "expected RuntimeError"
    except RuntimeError:
        pass
```

- [ ] **Step 2: Run test to verify it fails**

Run: `python -m pytest installer/tests/test_log_queue.py -v`
Expected: FAIL, module missing.

- [ ] **Step 3: Write implementation**

`installer/log_queue.py`:

```python
from __future__ import annotations

import queue
from typing import Any, Iterator

_SENTINEL = object()


class LogQueue:
    """Thread-safe queue with a stream() iterator that ends when close() is called."""

    def __init__(self) -> None:
        self._q: queue.Queue[Any] = queue.Queue()
        self._closed = False

    def put(self, item: dict[str, Any]) -> None:
        if self._closed:
            raise RuntimeError("LogQueue is closed")
        self._q.put(item)

    def close(self) -> None:
        if self._closed:
            return
        self._closed = True
        self._q.put(_SENTINEL)

    def stream(self) -> Iterator[dict[str, Any]]:
        while True:
            item = self._q.get()
            if item is _SENTINEL:
                return
            yield item
```

- [ ] **Step 4: Run test to verify it passes**

Run: `python -m pytest installer/tests/test_log_queue.py -v`
Expected: 3 passed.

- [ ] **Step 5: Stage**

```bash
git add installer/log_queue.py installer/tests/test_log_queue.py
```

---

## Task 5: `installer/steps/system.py` — OS + IP Detection

**Files:**
- Create: `installer/steps/system.py`
- Test: `installer/tests/test_system.py`

- [ ] **Step 1: Write the failing test**

`installer/tests/test_system.py`:

```python
from unittest.mock import patch

from installer.steps.system import detect_os, detect_private_ip, detect_public_ip


def test_detect_os_parses_os_release(tmp_path):
    fake = tmp_path / "os-release"
    fake.write_text('ID=ubuntu\nPRETTY_NAME="Ubuntu 22.04.3 LTS"\n')
    info = detect_os(os_release_path=fake)
    assert info == {"id": "ubuntu", "pretty": "Ubuntu 22.04.3 LTS"}


def test_detect_os_unknown_when_file_missing(tmp_path):
    fake = tmp_path / "missing"
    info = detect_os(os_release_path=fake)
    assert info == {"id": "unknown", "pretty": "unknown"}


def test_detect_private_ip_parses_ip_route_output():
    fake_output = "1.1.1.1 via 10.0.0.1 dev eth0 src 10.0.0.42 uid 0 \n    cache"
    with patch("installer.steps.system._run", return_value=fake_output):
        assert detect_private_ip() == "10.0.0.42"


def test_detect_private_ip_falls_back_to_localhost_on_failure():
    with patch("installer.steps.system._run", side_effect=Exception("no route")):
        assert detect_private_ip() == "127.0.0.1"


def test_detect_public_ip_uses_first_successful_service():
    with patch("installer.steps.system._http_get", side_effect=["203.0.113.5", "other"]):
        assert detect_public_ip() == "203.0.113.5"


def test_detect_public_ip_returns_private_on_all_services_failing():
    with patch("installer.steps.system._http_get", side_effect=Exception("no net")):
        with patch("installer.steps.system.detect_private_ip", return_value="10.0.0.42"):
            assert detect_public_ip() == "10.0.0.42"
```

- [ ] **Step 2: Run test to verify it fails**

Run: `python -m pytest installer/tests/test_system.py -v`
Expected: FAIL, module missing.

- [ ] **Step 3: Write implementation**

`installer/steps/system.py`:

```python
from __future__ import annotations

import re
import subprocess
import urllib.request
from pathlib import Path

_PUBLIC_IP_URLS = (
    "https://ifconfig.me",
    "https://api.ipify.org",
)


def _run(cmd: list[str]) -> str:
    return subprocess.check_output(cmd, text=True, timeout=10)


def _http_get(url: str, timeout: int = 8) -> str:
    with urllib.request.urlopen(url, timeout=timeout) as r:
        return r.read().decode("ascii").strip()


def detect_os(os_release_path: Path = Path("/etc/os-release")) -> dict[str, str]:
    if not os_release_path.exists():
        return {"id": "unknown", "pretty": "unknown"}
    info = {"id": "unknown", "pretty": "unknown"}
    for line in os_release_path.read_text().splitlines():
        if "=" not in line:
            continue
        k, v = line.split("=", 1)
        v = v.strip('"').strip()
        if k == "ID":
            info["id"] = v
        elif k == "PRETTY_NAME":
            info["pretty"] = v
    return info


def detect_private_ip() -> str:
    try:
        out = _run(["ip", "route", "get", "1.1.1.1"])
        m = re.search(r"\bsrc\s+(\S+)", out)
        if m:
            return m.group(1)
    except Exception:
        pass
    return "127.0.0.1"


def detect_public_ip() -> str:
    for url in _PUBLIC_IP_URLS:
        try:
            ip = _http_get(url)
            if ip:
                return ip
        except Exception:
            continue
    return detect_private_ip()
```

- [ ] **Step 4: Run test to verify it passes**

Run: `python -m pytest installer/tests/test_system.py -v`
Expected: 6 passed.

- [ ] **Step 5: Stage**

```bash
git add installer/steps/system.py installer/tests/test_system.py
```

---

## Task 6: `installer/steps/env_writer.py` — `.env` Writers

**Context for the engineer:** The current `install.sh` (lines 354-447) shows the exact shape of the root `.env`. For the Laravel `.env`, lines 449-532 show which keys are `sed`-replaced and which are appended. Mirror those writes exactly.

**Files:**
- Create: `installer/steps/env_writer.py`
- Test: `installer/tests/test_env_writer.py`

- [ ] **Step 1: Read the reference files to confirm key lists**

Open and skim:
- `install.sh` lines 354-447 — root `.env` keys
- `install.sh` lines 449-532 — Laravel `.env` keys
- `alpha-panel/web/httpdocs/.env.example` — the Laravel template the installer copies

You do not edit these yet. You only need the key list so the writer is accurate.

- [ ] **Step 2: Write the failing test**

`installer/tests/test_env_writer.py`:

```python
from pathlib import Path

import pytest

from installer.steps.env_writer import write_root_env, write_laravel_env


@pytest.fixture
def form_and_secrets():
    form = {
        "base_domain": "example.com",
        "panel_domain": "server.example.com",
        "pma_domain": "pma.example.com",
        "code_server_domain": "file.example.com",
        "vaultwarden_domain": "password.example.com",
        "n8n_domain": "n8n.example.com",
        "portainer_domain": "portainer.example.com",
        "jenkins_domain": "jenkins.example.com",
        "jenkins_admin_ips": "",
        "cf_api_token": "cf-token-abc",
        "admin_email": "admin@example.com",
        "panel_admin_name": "Admin User",
        "panel_admin_username": "admin",
        "panel_admin_email": "admin@example.com",
        "panel_admin_password": "supersecret",
        "private_ip": "10.0.0.5",
        "public_ip": "203.0.113.5",
    }
    secrets = {
        "mysql_root_password": "mysqlroot",
        "meilisearch_master_key": "meilikey",
        "alpha_panel_meilisearch_master_key": "apmeilikey",
        "postgresql_password": "pgpass",
        "n8n_encryption_key": "n8nkey",
        "pma_blowfish_secret": "pmabf",
        "vaultwarden_db_password": "vwdbpass",
        "panel_db_pass": "paneldbpass",
        "ftp_mysql_password": "ftpdb",
        "crowdsec_firewall_bouncer_key": "cs-fw",
        "crowdsec_dashboard_api_key": "cs-dash",
        "update_agent_secret": "update-secret",
        "reverb_app_id": "abcd",
        "reverb_app_key": "reverbkey",
        "reverb_app_secret": "reverbsecret",
        "app_key": "base64:AAAA",
        "code_server_password": "cspass",
    }
    return form, secrets


def test_write_root_env_includes_all_expected_keys(tmp_path: Path, form_and_secrets):
    form, secrets = form_and_secrets
    path = tmp_path / ".env"
    write_root_env(path, form=form, secrets=secrets)
    content = path.read_text()
    for key in [
        "CF_API_TOKEN=cf-token-abc",
        "ADMIN_EMAIL=admin@example.com",
        'MYSQL_ROOT_PASSWORD="mysqlroot"',
        "MYSQL_DATABASE=AlphaPanel",
        "BASE_DOMAIN=example.com",
        "PANEL_DOMAIN=server.example.com",
        "PORTAINER_DOMAIN=portainer.example.com",
        'MEILISEARCH_MASTER_KEY="meilikey"',
        "PRIVATE_NETWORK_IP=10.0.0.5",
        "PUBLIC_NETWORK_IP=203.0.113.5",
        'REVERB_APP_ID' not in "",  # sentinel; real check below
    ]:
        if isinstance(key, str):
            assert key in content, f"missing key in root .env: {key}"


def test_write_root_env_chmods_to_600(tmp_path: Path, form_and_secrets):
    form, secrets = form_and_secrets
    path = tmp_path / ".env"
    write_root_env(path, form=form, secrets=secrets)
    assert (path.stat().st_mode & 0o777) == 0o600


def test_write_laravel_env_substitutes_keys(tmp_path: Path, form_and_secrets):
    form, secrets = form_and_secrets
    example = tmp_path / ".env.example"
    example.write_text(
        "APP_NAME=Laravel\n"
        "APP_ENV=local\n"
        "APP_KEY=\n"
        "APP_DEBUG=true\n"
        "APP_URL=http://localhost\n"
        "APP_LOCALE=tr\n"
        "DB_CONNECTION=sqlite\n"
        "# DB_HOST=127.0.0.1\n"
        "# DB_PORT=3306\n"
        "# DB_DATABASE=laravel\n"
        "# DB_USERNAME=root\n"
        "# DB_PASSWORD=\n"
        "CACHE_STORE=database\n"
        "QUEUE_CONNECTION=database\n"
        "REDIS_HOST=127.0.0.1\n"
        "REVERB_APP_ID=\n"
        "REVERB_APP_KEY=\n"
        "REVERB_APP_SECRET=\n"
        "REVERB_HOST=localhost\n"
        "REVERB_PORT=8080\n"
        "REVERB_SCHEME=http\n"
        "LOG_LEVEL=debug\n"
        "SESSION_DOMAIN=null\n"
        "MAIL_FROM_ADDRESS=hello@example.com\n"
        "PANEL_ADMIN_NAME=\n"
        "PANEL_ADMIN_USERNAME=\n"
        "PANEL_ADMIN_EMAIL=\n"
        "PANEL_ADMIN_PASSWORD=\n"
    )
    target = tmp_path / ".env"
    write_laravel_env(
        target=target,
        example=example,
        form=form,
        secrets=secrets,
        install_dir="/opt/alphapanel-docker",
    )
    content = target.read_text()

    assert "APP_NAME=AlphaPanel" in content
    assert "APP_ENV=production" in content
    assert "APP_KEY=base64:AAAA" in content
    assert "APP_DEBUG=false" in content
    assert "APP_URL=https://server.example.com:8443" in content
    assert "APP_LOCALE=en" in content
    assert "DB_CONNECTION=mysql" in content
    assert "DB_HOST=mysql" in content
    assert "DB_PORT=3306" in content
    assert "DB_DATABASE=AlphaPanel" in content
    assert "DB_USERNAME=alphapanel" in content
    assert "DB_PASSWORD=paneldbpass" in content
    assert "CACHE_STORE=redis" in content
    assert "QUEUE_CONNECTION=redis" in content
    assert "REDIS_HOST=redis" in content
    assert "REVERB_APP_ID=abcd" in content
    assert "REVERB_APP_KEY=reverbkey" in content
    assert "REVERB_APP_SECRET=reverbsecret" in content
    assert "REVERB_HOST=server.example.com" in content
    assert "REVERB_PORT=443" in content
    assert "REVERB_SCHEME=https" in content
    assert "SESSION_DOMAIN=server.example.com" in content
    assert 'MAIL_FROM_ADDRESS="admin@example.com"' in content
    assert 'PANEL_ADMIN_NAME="Admin User"' in content
    assert "PANEL_ADMIN_USERNAME=admin" in content
    assert "PANEL_ADMIN_EMAIL=admin@example.com" in content
    assert "PANEL_ADMIN_PASSWORD=supersecret" in content


def test_write_laravel_env_appends_production_block(tmp_path: Path, form_and_secrets):
    form, secrets = form_and_secrets
    example = tmp_path / ".env.example"
    example.write_text("APP_NAME=Laravel\n")
    target = tmp_path / ".env"
    write_laravel_env(
        target=target,
        example=example,
        form=form,
        secrets=secrets,
        install_dir="/opt/alphapanel-docker",
    )
    content = target.read_text()

    # Appended block (see install.sh lines 485-532)
    assert "SCOUT_DRIVER=meilisearch" in content
    assert "MEILISEARCH_HOST=http://alpha_panel_meilisearch:7700" in content
    assert "MEILISEARCH_KEY=apmeilikey" in content
    assert "PANEL_CADDY_MAIN_CONFIG=/etc/frankenphp-container/Caddyfile" in content
    assert "PANEL_CADDY_SITES_BASE=/etc/frankenphp-container/sites-enabled" in content
    assert "PANEL_CADDY_ADMIN_URL=http://frankenphp:2019" in content
    assert "PANEL_FRANKENPHP_CONTAINER=frankenphp" in content
    assert "PANEL_PHP_CODE_SERVER_CONTAINER=php-code-server" in content
    assert "COMPOSE_PROJECT_ROOT_HOST=/opt/alphapanel-docker" in content
    assert "PORTAINER_URL=https://portainer.example.com:8443" in content
    assert "PMA_URL=https://pma.example.com:8443/index.php?server=2" in content
    assert "JENKINS_URL=https://jenkins.example.com" in content
    assert "UPDATE_AGENT_SECRET=update-secret" in content
    assert "UPDATE_AGENT_URL=http://update-agent:8100" in content


def test_write_laravel_env_chmods_600(tmp_path: Path, form_and_secrets):
    form, secrets = form_and_secrets
    example = tmp_path / ".env.example"
    example.write_text("APP_NAME=Laravel\n")
    target = tmp_path / ".env"
    write_laravel_env(
        target=target,
        example=example,
        form=form,
        secrets=secrets,
        install_dir="/opt/alphapanel-docker",
    )
    assert (target.stat().st_mode & 0o777) == 0o600
```

- [ ] **Step 3: Run test to verify it fails**

Run: `python -m pytest installer/tests/test_env_writer.py -v`
Expected: FAIL, module missing.

- [ ] **Step 4: Write implementation**

`installer/steps/env_writer.py`:

```python
from __future__ import annotations

import os
import re
from pathlib import Path
from typing import Any

# Fixed constants that the installer does not derive from form input.
_PANEL_DB_NAME = "AlphaPanel"
_PANEL_DB_USER = "alphapanel"
_POSTGRESQL_USER = "admin"


def _env_line(key: str, value: str, quoted: bool = False) -> str:
    if quoted:
        return f'{key}="{value}"\n'
    return f"{key}={value}\n"


def write_root_env(path: Path, form: dict[str, Any], secrets: dict[str, str]) -> None:
    """Write the Compose-level `.env` used by docker compose variable substitution."""
    lines: list[str] = []
    add = lines.append

    add("# ─── Cloudflare ───\n")
    add(_env_line("CF_API_TOKEN", form["cf_api_token"]))
    add("\n# ─── Admin ───\n")
    add(_env_line("ADMIN_EMAIL", form["admin_email"]))

    add("\n# ─── MySQL ───\n")
    add(_env_line("MYSQL_VERSION", "9.3.0"))
    add(_env_line("MYSQL_ROOT_PASSWORD", secrets["mysql_root_password"], quoted=True))
    add(_env_line("MYSQL_DATABASE", _PANEL_DB_NAME))

    add("\n# ─── FTP ───\n")
    add(_env_line("FTP_MYSQL_PASSWORD", secrets["ftp_mysql_password"], quoted=True))

    add("\n# ─── Meilisearch ───\n")
    add(_env_line("MEILISEARCH_MASTER_KEY", secrets["meilisearch_master_key"], quoted=True))
    add(_env_line("ALPHA_PANEL_MEILISEARCH_MASTER_KEY", secrets["alpha_panel_meilisearch_master_key"], quoted=True))

    add("\n# ─── PostgreSQL (N8N) ───\n")
    add(_env_line("POSTGRESQL_USER", _POSTGRESQL_USER))
    add(_env_line("POSTGRESQL_PASSWORD", secrets["postgresql_password"], quoted=True))

    add("\n# ─── Network ───\n")
    add(_env_line("PRIVATE_NETWORK_IP", form["private_ip"]))
    add(_env_line("PUBLIC_NETWORK_IP", form["public_ip"]))

    add("\n# ─── Domains ───\n")
    for key in [
        "base_domain", "panel_domain", "pma_domain", "code_server_domain",
        "vaultwarden_domain", "n8n_domain", "portainer_domain", "jenkins_domain",
    ]:
        add(_env_line(key.upper(), form[key]))
    add(_env_line("JENKINS_ADMIN_IPS", form.get("jenkins_admin_ips", "")))

    add("\n# ─── Vaultwarden ───\n")
    add(_env_line("VAULTWARDEN_DB_HOST", "mysql"))
    add(_env_line("VAULTWARDEN_DB_NAME", "bitwarden"))
    add(_env_line("VAULTWARDEN_DB_USER", "bitwarden"))
    add(_env_line("VAULTWARDEN_DB_PASSWORD", secrets["vaultwarden_db_password"], quoted=True))

    add("\n# ─── Code Server ───\n")
    add(_env_line("CODE_SERVER_PASSWORD", secrets["code_server_password"], quoted=True))
    add(_env_line("CODE_SERVER_SUDO_PASSWORD", secrets["code_server_password"], quoted=True))
    add(_env_line("CODE_SERVER_PWA_APP_NAME", "AlphaPanel Code Server"))

    add("\n# ─── N8N ───\n")
    add(_env_line("N8N_EMAIL_MODE", "smtp"))
    add(_env_line("N8N_SMTP_HOST", ""))
    add(_env_line("N8N_SMTP_PORT", "587"))
    add(_env_line("N8N_SMTP_USER", ""))
    add(_env_line("N8N_SMTP_PASS", ""))
    add(_env_line("N8N_SMTP_SENDER", ""))
    add(_env_line("N8N_SMTP_SSL", "false"))
    add(_env_line("N8N_ENCRYPTION_KEY", secrets["n8n_encryption_key"], quoted=True))

    add("\n# ─── phpMyAdmin ───\n")
    add(_env_line("PMA_BLOWFISH_SECRET", secrets["pma_blowfish_secret"]))
    add(_env_line("PANEL_DB_NAME", _PANEL_DB_NAME))
    add(_env_line("PANEL_DB_USER", _PANEL_DB_USER))
    add(_env_line("PANEL_DB_PASS", secrets["panel_db_pass"]))
    add(_env_line("PMA_URL", f"https://{form['pma_domain']}:8443/index.php?server=2"))
    add(_env_line("PANEL_APP_KEY", secrets["app_key"]))

    add("\n# ─── Update Agent ───\n")
    add(_env_line("UPDATE_AGENT_SECRET", secrets["update_agent_secret"]))
    add(_env_line("PANEL_GITHUB_REPO", "alphapanel/alphapanel-docker"))

    add("\n# ─── CrowdSec ───\n")
    add(_env_line("CROWDSEC_FIREWALL_BOUNCER_KEY", secrets["crowdsec_firewall_bouncer_key"]))
    add(_env_line("CROWDSEC_DASHBOARD_API_KEY", secrets["crowdsec_dashboard_api_key"]))
    add(_env_line("CROWDSEC_LAPI_URL", "http://crowdsec:8080"))

    add("\n# Reverb (used by Compose + panel)\n")
    add(_env_line("REVERB_APP_ID", secrets["reverb_app_id"]))
    add(_env_line("REVERB_APP_KEY", secrets["reverb_app_key"]))
    add(_env_line("REVERB_APP_SECRET", secrets["reverb_app_secret"]))

    path.write_text("".join(lines))
    os.chmod(path, 0o600)


_SUBSTITUTIONS_FROM_FORM = {
    "APP_NAME": ("AlphaPanel", False),
    "APP_ENV": ("production", False),
    "APP_DEBUG": ("false", False),
    "APP_LOCALE": ("en", False),
    "DB_CONNECTION": ("mysql", False),
    "CACHE_STORE": ("redis", False),
    "QUEUE_CONNECTION": ("redis", False),
    "REDIS_HOST": ("redis", False),
    "REVERB_PORT": ("443", False),
    "REVERB_SCHEME": ("https", False),
    "LOG_LEVEL": ("error", False),
    "DB_HOST": ("mysql", False),
    "DB_PORT": ("3306", False),
    "DB_DATABASE": (_PANEL_DB_NAME, False),
    "DB_USERNAME": (_PANEL_DB_USER, False),
}


def _replace_env_line(text: str, key: str, value: str, quoted: bool = False) -> str:
    """
    Replace lines like `KEY=old` or `# KEY=old` with `KEY=value`.
    If the key does not exist in the file, append it.
    """
    pattern = rf"^#?\s*{re.escape(key)}=.*$"
    replacement = f'{key}="{value}"' if quoted else f"{key}={value}"
    if re.search(pattern, text, flags=re.MULTILINE):
        return re.sub(pattern, replacement, text, flags=re.MULTILINE)
    if not text.endswith("\n"):
        text += "\n"
    return text + replacement + "\n"


def write_laravel_env(
    target: Path,
    example: Path,
    form: dict[str, Any],
    secrets: dict[str, str],
    install_dir: str,
) -> None:
    text = example.read_text()

    for key, (value, quoted) in _SUBSTITUTIONS_FROM_FORM.items():
        text = _replace_env_line(text, key, value, quoted)

    text = _replace_env_line(text, "APP_KEY", secrets["app_key"])
    text = _replace_env_line(text, "APP_URL", f"https://{form['panel_domain']}:8443")
    text = _replace_env_line(text, "DB_PASSWORD", secrets["panel_db_pass"])
    text = _replace_env_line(text, "REVERB_APP_ID", secrets["reverb_app_id"])
    text = _replace_env_line(text, "REVERB_APP_KEY", secrets["reverb_app_key"])
    text = _replace_env_line(text, "REVERB_APP_SECRET", secrets["reverb_app_secret"])
    text = _replace_env_line(text, "REVERB_HOST", form["panel_domain"])
    text = _replace_env_line(text, "SESSION_DOMAIN", form["panel_domain"])
    text = _replace_env_line(text, "MAIL_FROM_ADDRESS", form["admin_email"], quoted=True)
    text = _replace_env_line(text, "PANEL_ADMIN_NAME", form["panel_admin_name"], quoted=True)
    text = _replace_env_line(text, "PANEL_ADMIN_USERNAME", form["panel_admin_username"])
    text = _replace_env_line(text, "PANEL_ADMIN_EMAIL", form["panel_admin_email"])
    text = _replace_env_line(text, "PANEL_ADMIN_PASSWORD", form["panel_admin_password"])

    appended = f"""
# ─── Search ───
SCOUT_DRIVER=meilisearch
MEILISEARCH_HOST=http://alpha_panel_meilisearch:7700
MEILISEARCH_KEY={secrets["alpha_panel_meilisearch_master_key"]}

# ─── Panel config ───
PANEL_CADDY_MAIN_CONFIG=/etc/frankenphp-container/Caddyfile
PANEL_CADDY_SITES_BASE=/etc/frankenphp-container/sites-enabled
PANEL_CADDY_ADMIN_URL=http://frankenphp:2019
PANEL_FRANKENPHP_CONTAINER=frankenphp
PANEL_PHP_CODE_SERVER_CONTAINER=php-code-server
PANEL_DOCKER_TIMEOUT=15

COMPOSE_PROJECT_ROOT=/docker_compose_project_root
COMPOSE_PROJECT_ROOT_HOST={install_dir}
PORTAINER_CERTBOT_IMAGE=alphapanel-docker-certbot-init:latest

# ─── Services ───
PORTAINER_URL=https://{form['portainer_domain']}:8443
PORTAINER_API_KEY=
PORTAINER_ENDPOINT_ID=1

PMA_URL=https://{form['pma_domain']}:8443/index.php?server=2
JENKINS_URL=https://{form['jenkins_domain']}
PANEL_DB_HOST=mysql
PANEL_DB_PORT=3306
PANEL_DB_NAME={_PANEL_DB_NAME}
PANEL_DB_USER={_PANEL_DB_USER}
PANEL_DB_PASS={secrets["panel_db_pass"]}

# ─── CrowdSec ───
CROWDSEC_LAPI_URL=http://crowdsec:8080
CROWDSEC_DASHBOARD_API_KEY={secrets["crowdsec_dashboard_api_key"]}

# ─── SSH Terminal ───
PANEL_SSH_HOST=172.17.0.1
PANEL_SSH_PORT=22
PANEL_SSH_USER=root
PANEL_SSH_KEY_PATH=/root/.ssh/alphapanel_ed25519

# ─── Update Agent ───
UPDATE_AGENT_URL=http://update-agent:8100
UPDATE_AGENT_SECRET={secrets["update_agent_secret"]}
PANEL_GITHUB_REPO=alphapanel/alphapanel-docker
UPDATE_AUTO_CHECK=true
"""
    if not text.endswith("\n"):
        text += "\n"
    text += appended

    target.write_text(text)
    os.chmod(target, 0o600)


def set_portainer_credentials(laravel_env: Path, api_key: str, endpoint_id: int) -> None:
    """Called later (Task 10) once Portainer is up."""
    text = laravel_env.read_text()
    text = _replace_env_line(text, "PORTAINER_API_KEY", api_key)
    text = _replace_env_line(text, "PORTAINER_ENDPOINT_ID", str(endpoint_id))
    laravel_env.write_text(text)
```

- [ ] **Step 5: Run test to verify it passes**

Run: `python -m pytest installer/tests/test_env_writer.py -v`
Expected: 4 passed. Fix any mismatches before moving on.

- [ ] **Step 6: Stage**

```bash
git add installer/steps/env_writer.py installer/tests/test_env_writer.py
```

---

## Task 7: `installer/steps/directories.py` — Data Directory Creation

**Files:**
- Create: `installer/steps/directories.py`
- Test: `installer/tests/test_directories.py`

- [ ] **Step 1: Write the failing test**

`installer/tests/test_directories.py`:

```python
from installer.steps.directories import ensure_data_directories


def test_ensure_data_directories_creates_all_expected(tmp_path):
    ensure_data_directories(base=tmp_path, base_domain="example.com")

    expected = [
        "secrets",
        "letsencrypt",
        "deploy_cache",
        "backup",
        "portainer",
        "vaultwarden/data",
        "mysql/data",
        "postgres",
        "redis",
        "meilisearch/data",
        "meilisearch/tmp",
        "alpha-panel/meilisearch/data",
        "alpha-panel/meilisearch/tmp",
        "alpha-panel/web/logs",
        "alpha-panel/web/caddy_data",
        "alpha-panel/web/ssl",
        "frankenphp/caddy_data",
        "frankenphp/logs",
        "frankenphp/waf/generated/domains",
        "frankenphp/sites-enabled/example.com",
        "n8n/data",
        "n8n/files",
        "jenkins/data",
        "code-server/data",
        "external-services",
    ]
    for rel in expected:
        assert (tmp_path / rel).is_dir(), f"missing directory {rel}"


def test_ensure_data_directories_writes_waf_placeholders(tmp_path):
    ensure_data_directories(base=tmp_path, base_domain="example.com")
    assert (tmp_path / "frankenphp/waf/generated/global.conf").exists()
    assert (tmp_path / "frankenphp/waf/generated/domains/000-default.conf").exists()


def test_ensure_data_directories_is_idempotent(tmp_path):
    ensure_data_directories(base=tmp_path, base_domain="example.com")
    # Writing content must not be overwritten on second call.
    (tmp_path / "frankenphp/waf/generated/global.conf").write_text("custom\n")
    ensure_data_directories(base=tmp_path, base_domain="example.com")
    assert (tmp_path / "frankenphp/waf/generated/global.conf").read_text() == "custom\n"
```

- [ ] **Step 2: Run test to verify it fails**

Run: `python -m pytest installer/tests/test_directories.py -v`
Expected: FAIL, missing module.

- [ ] **Step 3: Write implementation**

`installer/steps/directories.py`:

```python
from __future__ import annotations

from pathlib import Path

_DIRS = [
    "secrets",
    "letsencrypt",
    "deploy_cache",
    "backup",
    "portainer",
    "vaultwarden/data",
    "mysql/data",
    "postgres",
    "redis",
    "meilisearch/data",
    "meilisearch/tmp",
    "alpha-panel/meilisearch/data",
    "alpha-panel/meilisearch/tmp",
    "alpha-panel/web/logs",
    "alpha-panel/web/caddy_data",
    "alpha-panel/web/ssl",
    "frankenphp/caddy_data",
    "frankenphp/logs",
    "frankenphp/waf/generated/domains",
    "n8n/data",
    "n8n/files",
    "jenkins/data",
    "code-server/data",
    "external-services",
]


def ensure_data_directories(base: Path, base_domain: str) -> None:
    for rel in _DIRS:
        (base / rel).mkdir(parents=True, exist_ok=True)
    (base / "frankenphp/sites-enabled" / base_domain).mkdir(parents=True, exist_ok=True)

    global_conf = base / "frankenphp/waf/generated/global.conf"
    if not global_conf.exists():
        global_conf.write_text("# Auto-generated by AlphaPanel.\n# (no global IP rules)\n")

    default_domain = base / "frankenphp/waf/generated/domains/000-default.conf"
    if not default_domain.exists():
        default_domain.write_text("# Auto-generated by AlphaPanel.\n# (no domain-specific rules)\n")
```

- [ ] **Step 4: Run test to verify it passes**

Run: `python -m pytest installer/tests/test_directories.py -v`
Expected: 3 passed.

- [ ] **Step 5: Stage**

```bash
git add installer/steps/directories.py installer/tests/test_directories.py
```

---

## Task 8: `installer/steps/caddyfile.py` — Caddyfile Templates

**Context:** See `install.sh` lines 272-352 for the Jenkins + base-domain Caddyfile templates. Port them verbatim to a Python string template.

**Files:**
- Create: `installer/steps/caddyfile.py`
- Test: `installer/tests/test_caddyfile.py`

- [ ] **Step 1: Write the failing test**

`installer/tests/test_caddyfile.py`:

```python
from installer.steps.caddyfile import write_base_domain_caddyfile, write_jenkins_caddyfile


def test_base_domain_caddyfile_created_when_missing(tmp_path):
    target = tmp_path / "example.com" / "Caddyfile"
    write_base_domain_caddyfile(target, base_domain="example.com")
    content = target.read_text()
    assert "example.com" in content
    assert "*.example.com" in content


def test_base_domain_caddyfile_idempotent(tmp_path):
    target = tmp_path / "example.com" / "Caddyfile"
    write_base_domain_caddyfile(target, base_domain="example.com")
    target.write_text("CUSTOM\n")
    write_base_domain_caddyfile(target, base_domain="example.com")
    assert target.read_text() == "CUSTOM\n"


def test_jenkins_caddyfile_open_mode_when_no_admin_ips(tmp_path):
    target = tmp_path / "jenkins.example.com" / "Caddyfile"
    write_jenkins_caddyfile(
        target,
        base_domain="example.com",
        jenkins_domain="jenkins.example.com",
        admin_ips="",
    )
    content = target.read_text()
    assert "jenkins.example.com:443" in content
    assert "reverse_proxy jenkins:8080" in content
    assert "client_ip" not in content  # no admin gate
    assert "respond 403" not in content


def test_jenkins_caddyfile_restricts_to_admin_cidrs(tmp_path):
    target = tmp_path / "jenkins.example.com" / "Caddyfile"
    write_jenkins_caddyfile(
        target,
        base_domain="example.com",
        jenkins_domain="jenkins.example.com",
        admin_ips="1.2.3.4, 10.0.0.0/24",
    )
    content = target.read_text()
    assert "@admin client_ip 1.2.3.4/32 10.0.0.0/24" in content
    assert "respond 403" in content
```

- [ ] **Step 2: Run test to verify it fails**

Run: `python -m pytest installer/tests/test_caddyfile.py -v`
Expected: FAIL, missing module.

- [ ] **Step 3: Write implementation**

`installer/steps/caddyfile.py`:

```python
from __future__ import annotations

from pathlib import Path


def write_base_domain_caddyfile(target: Path, base_domain: str) -> None:
    if target.exists():
        return
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_text(
        f"# Let's Encrypt will issue a wildcard cert for {base_domain} and *.{base_domain}\n"
        "# This file must exist so the panel applies this domain.\n"
        "# import common-tls\n"
    )


def _format_admin_ips(admin_ips: str) -> str:
    parts = []
    for raw in admin_ips.split(","):
        ip = raw.strip()
        if not ip:
            continue
        if "/" not in ip:
            ip = f"{ip}/32"
        parts.append(ip)
    return " ".join(parts)


_JENKINS_OPEN = """# Auto-generated by AlphaPanel installer — DO NOT DELETE
# This file is NOT managed by panel:apply
{jenkins_domain}:443 {{
    tls /etc/letsencrypt/live/{base_domain}/fullchain.pem /etc/letsencrypt/live/{base_domain}/privkey.pem

    handle /__whoami {{
        respond "remote={{http.request.remote.host}}\\nxff={{http.request.header.X-Forwarded-For}}\\ncf={{http.request.header.CF-Connecting-IP}}\\nreal={{http.request.header.X-Real-IP}}\\n"
    }}

    @webhook {{
        path /github-webhook*
        import /etc/frankenphp/_github_hooks_allowlist.caddy
    }}
    handle @webhook {{
        reverse_proxy jenkins:8080
    }}

    handle {{
        reverse_proxy jenkins:8080
    }}
}}
"""

_JENKINS_RESTRICTED = """# Auto-generated by AlphaPanel installer — DO NOT DELETE
# This file is NOT managed by panel:apply
{jenkins_domain}:443 {{
    tls /etc/letsencrypt/live/{base_domain}/fullchain.pem /etc/letsencrypt/live/{base_domain}/privkey.pem

    handle /__whoami {{
        respond "remote={{http.request.remote.host}}\\nxff={{http.request.header.X-Forwarded-For}}\\ncf={{http.request.header.CF-Connecting-IP}}\\nreal={{http.request.header.X-Real-IP}}\\n"
    }}

    @webhook {{
        path /github-webhook*
        import /etc/frankenphp/_github_hooks_allowlist.caddy
    }}
    handle @webhook {{
        reverse_proxy jenkins:8080
    }}

    @admin client_ip {cidr_list}
    handle @admin {{
        reverse_proxy jenkins:8080
    }}

    handle {{
        respond 403
    }}
}}
"""


def write_jenkins_caddyfile(
    target: Path,
    base_domain: str,
    jenkins_domain: str,
    admin_ips: str,
) -> None:
    target.parent.mkdir(parents=True, exist_ok=True)
    cidrs = _format_admin_ips(admin_ips)
    if cidrs:
        target.write_text(
            _JENKINS_RESTRICTED.format(
                jenkins_domain=jenkins_domain,
                base_domain=base_domain,
                cidr_list=cidrs,
            )
        )
    else:
        target.write_text(
            _JENKINS_OPEN.format(
                jenkins_domain=jenkins_domain,
                base_domain=base_domain,
            )
        )
```

- [ ] **Step 4: Run test to verify it passes**

Run: `python -m pytest installer/tests/test_caddyfile.py -v`
Expected: 4 passed.

- [ ] **Step 5: Stage**

```bash
git add installer/steps/caddyfile.py installer/tests/test_caddyfile.py
```

---

## Task 9: `installer/steps/cloudflare.py` — CF Token I/O + Verification

**Files:**
- Create: `installer/steps/cloudflare.py`
- Test: `installer/tests/test_cloudflare.py`

- [ ] **Step 1: Write the failing test**

`installer/tests/test_cloudflare.py`:

```python
from unittest.mock import patch, MagicMock

import pytest

from installer.errors import InstallerError
from installer.steps.cloudflare import verify_token, write_cloudflare_ini


def test_write_cloudflare_ini_writes_with_mode_600(tmp_path):
    target = tmp_path / "secrets" / "cloudflare.ini"
    write_cloudflare_ini(target, token="abc123")
    content = target.read_text()
    assert "dns_cloudflare_api_token = abc123" in content
    assert (target.stat().st_mode & 0o777) == 0o600


def test_verify_token_returns_true_on_active_status():
    fake_response = MagicMock()
    fake_response.status_code = 200
    fake_response.json.return_value = {"success": True, "result": {"status": "active"}}
    with patch("installer.steps.cloudflare.requests.get", return_value=fake_response):
        assert verify_token("valid-token") is True


def test_verify_token_raises_installer_error_on_non_active():
    fake_response = MagicMock()
    fake_response.status_code = 200
    fake_response.json.return_value = {"success": True, "result": {"status": "disabled"}}
    with patch("installer.steps.cloudflare.requests.get", return_value=fake_response):
        with pytest.raises(InstallerError) as exc:
            verify_token("bad-token")
        assert exc.value.phase == "cloudflare_verify"


def test_verify_token_raises_installer_error_on_http_error():
    fake_response = MagicMock()
    fake_response.status_code = 401
    fake_response.json.return_value = {"success": False, "errors": [{"message": "Unauthorized"}]}
    with patch("installer.steps.cloudflare.requests.get", return_value=fake_response):
        with pytest.raises(InstallerError):
            verify_token("bad-token")
```

- [ ] **Step 2: Run test to verify it fails**

Run: `python -m pytest installer/tests/test_cloudflare.py -v`
Expected: FAIL, missing module.

- [ ] **Step 3: Write implementation**

`installer/steps/cloudflare.py`:

```python
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
```

- [ ] **Step 4: Run test to verify it passes**

Run: `python -m pytest installer/tests/test_cloudflare.py -v`
Expected: 4 passed.

- [ ] **Step 5: Stage**

```bash
git add installer/steps/cloudflare.py installer/tests/test_cloudflare.py
```

---

## Task 10: `installer/steps/ssh_key.py` — ed25519 + `authorized_keys`

**Files:**
- Create: `installer/steps/ssh_key.py`
- Test: `installer/tests/test_ssh_key.py`

- [ ] **Step 1: Write the failing test**

`installer/tests/test_ssh_key.py`:

```python
import os
import subprocess
from unittest.mock import patch

from installer.steps.ssh_key import ensure_ssh_key


def test_ensure_ssh_key_generates_keypair_when_missing(tmp_path):
    key_dir = tmp_path / "keys"
    auth_keys = tmp_path / "authorized_keys"

    def fake_check_call(cmd, **kwargs):
        # Fake ssh-keygen: write two files
        priv = cmd[cmd.index("-f") + 1]
        with open(priv, "w") as f:
            f.write("PRIVATE\n")
        with open(priv + ".pub", "w") as f:
            f.write("ssh-ed25519 AAAAB3Nz fakehost\n")
        return 0

    with patch("installer.steps.ssh_key.subprocess.check_call", side_effect=fake_check_call):
        result = ensure_ssh_key(
            key_dir=key_dir,
            authorized_keys_path=auth_keys,
            comment="alphapanel-terminal@host",
        )

    assert (key_dir / "alphapanel_ed25519").exists()
    assert (key_dir / "alphapanel_ed25519.pub").exists()
    assert (key_dir / "alphapanel_ed25519").stat().st_mode & 0o777 == 0o600
    assert auth_keys.read_text().strip().endswith("fakehost")
    assert auth_keys.stat().st_mode & 0o777 == 0o600
    assert result == key_dir / "alphapanel_ed25519"


def test_ensure_ssh_key_does_not_regenerate_when_present(tmp_path):
    key_dir = tmp_path / "keys"
    key_dir.mkdir()
    priv = key_dir / "alphapanel_ed25519"
    pub = key_dir / "alphapanel_ed25519.pub"
    priv.write_text("EXISTING\n")
    pub.write_text("ssh-ed25519 AAAA fakehost\n")
    os.chmod(priv, 0o600)

    auth_keys = tmp_path / "authorized_keys"

    with patch("installer.steps.ssh_key.subprocess.check_call") as mock_run:
        ensure_ssh_key(
            key_dir=key_dir,
            authorized_keys_path=auth_keys,
            comment="x",
        )
    mock_run.assert_not_called()
    assert priv.read_text() == "EXISTING\n"


def test_ensure_ssh_key_appends_pub_key_only_once(tmp_path):
    key_dir = tmp_path / "keys"
    key_dir.mkdir()
    (key_dir / "alphapanel_ed25519").write_text("priv\n")
    (key_dir / "alphapanel_ed25519.pub").write_text("ssh-ed25519 DUP host\n")

    auth_keys = tmp_path / "authorized_keys"

    for _ in range(3):
        ensure_ssh_key(
            key_dir=key_dir,
            authorized_keys_path=auth_keys,
            comment="x",
        )

    content = auth_keys.read_text()
    assert content.count("ssh-ed25519 DUP host") == 1
```

- [ ] **Step 2: Run test to verify it fails**

Run: `python -m pytest installer/tests/test_ssh_key.py -v`
Expected: FAIL, missing module.

- [ ] **Step 3: Write implementation**

`installer/steps/ssh_key.py`:

```python
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
        subprocess.check_call([
            "ssh-keygen", "-t", "ed25519",
            "-f", str(priv),
            "-N", "",
            "-C", comment,
        ])
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
```

- [ ] **Step 4: Run test to verify it passes**

Run: `python -m pytest installer/tests/test_ssh_key.py -v`
Expected: 3 passed.

- [ ] **Step 5: Stage**

```bash
git add installer/steps/ssh_key.py installer/tests/test_ssh_key.py
```

---

## Task 11: `installer/steps/compose.py` — Compose Up With Streaming Output

**Files:**
- Create: `installer/steps/compose.py`
- Test: `installer/tests/test_compose.py`

- [ ] **Step 1: Write the failing test**

`installer/tests/test_compose.py`:

```python
from unittest.mock import patch, MagicMock

import pytest

from installer.errors import InstallerError
from installer.log_queue import LogQueue
from installer.steps.compose import compose_up


def _fake_popen(lines: list[str], returncode: int):
    proc = MagicMock()
    proc.stdout = iter([line + "\n" for line in lines])
    proc.wait.return_value = returncode
    proc.returncode = returncode
    return proc


def test_compose_up_streams_each_line_to_queue(tmp_path):
    q = LogQueue()
    fake = _fake_popen(["Creating alpha-panel-web ... done", "Starting mysql ..."], returncode=0)
    with patch("installer.steps.compose.subprocess.Popen", return_value=fake):
        compose_up(project_dir=tmp_path, log_queue=q)
    q.close()
    items = list(q.stream())
    lines = [i["text"] for i in items if i["type"] == "line"]
    assert "Creating alpha-panel-web ... done" in lines
    assert "Starting mysql ..." in lines


def test_compose_up_raises_on_nonzero_exit(tmp_path):
    q = LogQueue()
    fake = _fake_popen(["err: something"], returncode=1)
    with patch("installer.steps.compose.subprocess.Popen", return_value=fake):
        with pytest.raises(InstallerError) as exc:
            compose_up(project_dir=tmp_path, log_queue=q)
        assert exc.value.phase == "compose_up"
```

- [ ] **Step 2: Run test to verify it fails**

Run: `python -m pytest installer/tests/test_compose.py -v`
Expected: FAIL, missing module.

- [ ] **Step 3: Write implementation**

`installer/steps/compose.py`:

```python
from __future__ import annotations

import subprocess
from pathlib import Path

from installer.errors import InstallerError
from installer.log_queue import LogQueue


def compose_up(project_dir: Path, log_queue: LogQueue) -> None:
    cmd = ["docker", "compose", "up", "-d", "--build"]
    log_queue.put({"type": "line", "text": f"$ {' '.join(cmd)}"})
    proc = subprocess.Popen(
        cmd,
        cwd=str(project_dir),
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        text=True,
        bufsize=1,
    )
    for line in proc.stdout:
        log_queue.put({"type": "line", "text": line.rstrip("\n")})
    rc = proc.wait()
    if rc != 0:
        raise InstallerError("compose_up", f"docker compose up exited with code {rc}")
```

- [ ] **Step 4: Run test to verify it passes**

Run: `python -m pytest installer/tests/test_compose.py -v`
Expected: 2 passed.

- [ ] **Step 5: Stage**

```bash
git add installer/steps/compose.py installer/tests/test_compose.py
```

---

## Task 12: `installer/steps/portainer.py` — Wait, Admin Init, Token, Endpoint

**Files:**
- Create: `installer/steps/portainer.py`
- Test: `installer/tests/test_portainer.py`

- [ ] **Step 1: Write the failing test**

`installer/tests/test_portainer.py`:

```python
from unittest.mock import patch, MagicMock, call

import pytest

from installer.errors import InstallerError
from installer.steps.portainer import (
    wait_for_portainer,
    init_portainer_admin,
    create_access_token,
    detect_endpoint_id,
)


def _mk_response(status_code=200, json_body=None, text=""):
    resp = MagicMock()
    resp.status_code = status_code
    resp.json.return_value = json_body or {}
    resp.text = text
    return resp


def test_wait_for_portainer_returns_when_status_200():
    with patch("installer.steps.portainer.requests.get") as mock_get:
        mock_get.return_value = _mk_response(200, {"Version": "CE-2.x"})
        wait_for_portainer("http://localhost:9000", timeout=5, interval=0.01)


def test_wait_for_portainer_raises_after_timeout():
    with patch("installer.steps.portainer.requests.get") as mock_get:
        mock_get.side_effect = Exception("connection refused")
        with pytest.raises(InstallerError) as exc:
            wait_for_portainer("http://localhost:9000", timeout=0.05, interval=0.01)
        assert exc.value.phase == "portainer_wait"


def test_init_portainer_admin_posts_credentials():
    with patch("installer.steps.portainer.requests.post") as mock_post:
        mock_post.return_value = _mk_response(200, {"Id": 1, "Username": "admin"})
        init_portainer_admin("http://localhost:9000", "admin", "password123456")
        mock_post.assert_called_once()
        args, kwargs = mock_post.call_args
        assert "/api/users/admin/init" in args[0]
        assert kwargs["json"] == {"Username": "admin", "Password": "password123456"}


def test_init_portainer_admin_skips_on_409_already_initialised():
    with patch("installer.steps.portainer.requests.post") as mock_post:
        mock_post.return_value = _mk_response(409)
        # Should not raise — already initialised is a valid resume state.
        init_portainer_admin("http://localhost:9000", "admin", "password123456")


def test_init_portainer_admin_raises_on_400():
    with patch("installer.steps.portainer.requests.post") as mock_post:
        mock_post.return_value = _mk_response(400, text="Password too short")
        with pytest.raises(InstallerError):
            init_portainer_admin("http://localhost:9000", "admin", "short")


def test_create_access_token_logs_in_then_creates_token():
    auth_response = _mk_response(200, {"jwt": "fake-jwt"})
    whoami_response = _mk_response(200, {"Id": 1})
    token_response = _mk_response(200, {"rawAPIKey": "raw-key-xxx"})

    with patch("installer.steps.portainer.requests.post", side_effect=[auth_response, token_response]) as mock_post, \
         patch("installer.steps.portainer.requests.get", return_value=whoami_response):
        token = create_access_token("http://localhost:9000", "admin", "password123456")
        assert token == "raw-key-xxx"
        assert mock_post.call_count == 2
        # Second POST must carry Authorization header with jwt.
        _, last_kwargs = mock_post.call_args
        assert last_kwargs["headers"]["Authorization"] == "Bearer fake-jwt"


def test_detect_endpoint_id_returns_first_id():
    with patch("installer.steps.portainer.requests.get") as mock_get:
        mock_get.return_value = _mk_response(200, [{"Id": 7, "Name": "primary"}])
        assert detect_endpoint_id("http://localhost:9000", "api-key") == 7


def test_detect_endpoint_id_defaults_to_1_when_empty():
    with patch("installer.steps.portainer.requests.get") as mock_get:
        mock_get.return_value = _mk_response(200, [])
        assert detect_endpoint_id("http://localhost:9000", "api-key") == 1
```

- [ ] **Step 2: Run test to verify it fails**

Run: `python -m pytest installer/tests/test_portainer.py -v`
Expected: FAIL, missing module.

- [ ] **Step 3: Write implementation**

`installer/steps/portainer.py`:

```python
from __future__ import annotations

import time
from typing import Any

import requests

from installer.errors import InstallerError


def wait_for_portainer(base_url: str, timeout: int = 180, interval: float = 3.0) -> None:
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
```

- [ ] **Step 4: Run test to verify it passes**

Run: `python -m pytest installer/tests/test_portainer.py -v`
Expected: 8 passed.

- [ ] **Step 5: Stage**

```bash
git add installer/steps/portainer.py installer/tests/test_portainer.py
```

---

## Task 13: Laravel Artisan Command — `panel:issue-installer-cert`

**Files:**
- Create: `alpha-panel/web/httpdocs/app/Console/Commands/IssueInstallerCertCommand.php`
- Test: `alpha-panel/web/httpdocs/tests/Feature/Console/IssueInstallerCertCommandTest.php`

**Context for the engineer:**
- `AcmeService::requestCertificateDnsCloudflare(Domain $domain, ?callable $onProgress)` exists in `alpha-panel/web/httpdocs/app/Services/Acme/AcmeService.php:93`.
- `AcmeSetting` model exposes an `instance()` method (singleton settings row) — check `alpha-panel/web/httpdocs/app/Models/AcmeSetting.php` for the full field list before writing the test. The test below assumes columns: `provider`, `cloudflare_api_token`, `contact_email`, `email`, `staging`. **Confirm these before writing the test and adjust the assertions to match the actual schema.**
- `Domain` model — check `alpha-panel/web/httpdocs/app/Models/Domain.php` for required fields when creating a record. Minimum: `fqdn`, `status`.
- The command must run inside the container: `docker exec alpha_panel_web php artisan panel:issue-installer-cert --base=example.com --token-file=/app/secrets/cloudflare.ini`.

- [ ] **Step 1: Read existing models to confirm schema**

Open `app/Models/AcmeSetting.php`, `app/Models/Domain.php`, and the latest migrations for both tables. Note the exact column names and any required defaults. Adjust the test assertions in Step 2 to match.

- [ ] **Step 2: Write the failing test**

`tests/Feature/Console/IssueInstallerCertCommandTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\AcmeSetting;
use App\Models\Domain;
use App\Services\Acme\AcmeResult;
use App\Services\Acme\AcmeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class IssueInstallerCertCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_writes_acme_setting_creates_domain_and_calls_service(): void
    {
        $tokenFile = tempnam(sys_get_temp_dir(), 'cf');
        file_put_contents($tokenFile, "dns_cloudflare_api_token = test-token-xyz\n");

        $mockResult = new AcmeResult(true, 'issued', []);
        $mockService = Mockery::mock(AcmeService::class);
        $mockService->shouldReceive('requestCertificateDnsCloudflare')
            ->once()
            ->withArgs(function (Domain $d, $cb) {
                return $d->fqdn === 'example.com';
            })
            ->andReturn($mockResult);

        $this->app->instance(AcmeService::class, $mockService);

        $exit = $this->artisan('panel:issue-installer-cert', [
            '--base' => 'example.com',
            '--token-file' => $tokenFile,
            '--admin-email' => 'admin@example.com',
        ])->run();

        $this->assertSame(0, $exit);

        $setting = AcmeSetting::instance();
        $this->assertSame('cloudflare', $setting->provider);
        $this->assertSame('test-token-xyz', $setting->cloudflare_api_token);
        $this->assertSame('admin@example.com', $setting->email);

        $this->assertDatabaseHas('domains', ['fqdn' => 'example.com']);

        unlink($tokenFile);
    }

    public function test_it_returns_failure_when_service_fails(): void
    {
        $tokenFile = tempnam(sys_get_temp_dir(), 'cf');
        file_put_contents($tokenFile, "dns_cloudflare_api_token = test-token-xyz\n");

        $mockResult = new AcmeResult(false, 'dns propagation timeout', []);
        $mockService = Mockery::mock(AcmeService::class);
        $mockService->shouldReceive('requestCertificateDnsCloudflare')->once()->andReturn($mockResult);
        $this->app->instance(AcmeService::class, $mockService);

        $exit = $this->artisan('panel:issue-installer-cert', [
            '--base' => 'example.com',
            '--token-file' => $tokenFile,
            '--admin-email' => 'admin@example.com',
        ])->run();

        $this->assertSame(1, $exit);
        unlink($tokenFile);
    }

    public function test_it_errors_when_token_file_missing(): void
    {
        $exit = $this->artisan('panel:issue-installer-cert', [
            '--base' => 'example.com',
            '--token-file' => '/nonexistent/cloudflare.ini',
            '--admin-email' => 'admin@example.com',
        ])->run();

        $this->assertSame(1, $exit);
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run (inside the container or locally with DB): `cd alpha-panel/web/httpdocs && php artisan test --compact --filter=IssueInstallerCertCommandTest`
Expected: FAIL because command does not exist.

- [ ] **Step 4: Write implementation**

`app/Console/Commands/IssueInstallerCertCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AcmeSetting;
use App\Models\Domain;
use App\Services\Acme\AcmeService;
use Illuminate\Console\Command;

class IssueInstallerCertCommand extends Command
{
    protected $signature = 'panel:issue-installer-cert
        {--base= : Base (apex) domain to issue wildcard cert for}
        {--token-file= : Path to Cloudflare INI file (dns_cloudflare_api_token=)}
        {--admin-email= : Contact email for ACME account}';

    protected $description = 'Issue a Let\'s Encrypt wildcard certificate via Cloudflare DNS-01 during initial installation';

    public function handle(AcmeService $acmeService): int
    {
        $base = (string) $this->option('base');
        $tokenFile = (string) $this->option('token-file');
        $email = (string) $this->option('admin-email');

        if ($base === '' || $tokenFile === '' || $email === '') {
            $this->error('--base, --token-file, and --admin-email are required');

            return self::FAILURE;
        }

        if (! is_file($tokenFile)) {
            $this->error("Token file not found: {$tokenFile}");

            return self::FAILURE;
        }

        $token = $this->extractTokenFromIni($tokenFile);
        if ($token === null) {
            $this->error("Could not parse dns_cloudflare_api_token from {$tokenFile}");

            return self::FAILURE;
        }

        $setting = AcmeSetting::instance();
        $setting->provider = 'cloudflare';
        $setting->cloudflare_api_token = $token;
        $setting->email = $email;
        $setting->save();

        $domain = Domain::firstOrCreate(
            ['fqdn' => $base],
            ['status' => 'active'],
        );

        $this->info("[acme] Issuing wildcard cert for {$base} and *.{$base}");

        $result = $acmeService->requestCertificateDnsCloudflare(
            $domain,
            function (string $message): void {
                $this->line("[acme] {$message}");
            },
        );

        if (! $result->success) {
            $this->error("[acme] Failed: {$result->message}");

            return self::FAILURE;
        }

        $this->info("[acme] Certificate issued successfully.");

        return self::SUCCESS;
    }

    private function extractTokenFromIni(string $path): ?string
    {
        foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
            if (preg_match('/^\s*dns_cloudflare_api_token\s*=\s*(\S+)\s*$/', $line, $m)) {
                return $m[1];
            }
        }

        return null;
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `cd alpha-panel/web/httpdocs && php artisan test --compact --filter=IssueInstallerCertCommandTest`
Expected: 3 passed. If assertions fail because of schema mismatches (e.g. column is `contact_email` not `email`), fix the command to use the correct column name and re-run.

- [ ] **Step 6: Format with Pint**

Run: `cd alpha-panel/web/httpdocs && vendor/bin/pint --dirty --format agent`

- [ ] **Step 7: Stage**

```bash
git add alpha-panel/web/httpdocs/app/Console/Commands/IssueInstallerCertCommand.php \
        alpha-panel/web/httpdocs/tests/Feature/Console/IssueInstallerCertCommandTest.php
```

---

## Task 14: `installer/steps/ssl.py` — Call the Artisan Command

**Files:**
- Create: `installer/steps/ssl.py`
- Test: `installer/tests/test_ssl.py`

- [ ] **Step 1: Write the failing test**

`installer/tests/test_ssl.py`:

```python
from unittest.mock import patch, MagicMock

import pytest

from installer.errors import InstallerError
from installer.log_queue import LogQueue
from installer.steps.ssl import issue_panel_certificate


def _fake_popen(lines: list[str], returncode: int):
    proc = MagicMock()
    proc.stdout = iter([line + "\n" for line in lines])
    proc.wait.return_value = returncode
    proc.returncode = returncode
    return proc


def test_issue_panel_certificate_invokes_artisan_and_streams_output():
    q = LogQueue()
    proc = _fake_popen(["[acme] starting", "[acme] cert issued"], returncode=0)
    with patch("installer.steps.ssl.subprocess.Popen", return_value=proc) as popen:
        issue_panel_certificate(
            base_domain="example.com",
            admin_email="admin@example.com",
            token_file="/opt/alphapanel-docker/secrets/cloudflare.ini",
            container="alpha_panel_web",
            log_queue=q,
        )
    q.close()
    lines = [i["text"] for i in q.stream() if i["type"] == "line"]
    assert "[acme] starting" in lines
    assert "[acme] cert issued" in lines
    args, _ = popen.call_args
    assert "docker" in args[0]
    assert "exec" in args[0]
    assert "alpha_panel_web" in args[0]
    assert "panel:issue-installer-cert" in args[0]


def test_issue_panel_certificate_raises_when_artisan_fails():
    q = LogQueue()
    proc = _fake_popen(["[acme] dns propagation timeout"], returncode=1)
    with patch("installer.steps.ssl.subprocess.Popen", return_value=proc):
        with pytest.raises(InstallerError) as exc:
            issue_panel_certificate(
                base_domain="example.com",
                admin_email="admin@example.com",
                token_file="/opt/alphapanel-docker/secrets/cloudflare.ini",
                container="alpha_panel_web",
                log_queue=q,
            )
        assert exc.value.phase == "ssl_issue"
```

- [ ] **Step 2: Run test to verify it fails**

Run: `python -m pytest installer/tests/test_ssl.py -v`
Expected: FAIL, missing module.

- [ ] **Step 3: Write implementation**

`installer/steps/ssl.py`:

```python
from __future__ import annotations

import subprocess

from installer.errors import InstallerError
from installer.log_queue import LogQueue


def issue_panel_certificate(
    base_domain: str,
    admin_email: str,
    token_file: str,
    container: str,
    log_queue: LogQueue,
) -> None:
    cmd = [
        "docker", "exec", container,
        "php", "artisan", "panel:issue-installer-cert",
        f"--base={base_domain}",
        f"--token-file={token_file}",
        f"--admin-email={admin_email}",
    ]
    log_queue.put({"type": "line", "text": f"$ {' '.join(cmd)}"})
    proc = subprocess.Popen(
        cmd,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        text=True,
        bufsize=1,
    )
    for line in proc.stdout:
        log_queue.put({"type": "line", "text": line.rstrip("\n")})
    rc = proc.wait()
    if rc != 0:
        raise InstallerError("ssl_issue", f"panel:issue-installer-cert exited with code {rc}")
```

- [ ] **Step 4: Run test to verify it passes**

Run: `python -m pytest installer/tests/test_ssl.py -v`
Expected: 2 passed.

- [ ] **Step 5: Stage**

```bash
git add installer/steps/ssl.py installer/tests/test_ssl.py
```

---

## Task 15: `installer/steps/database.py` — Wait MySQL, Migrate, Seed, Admin User

**Files:**
- Create: `installer/steps/database.py`
- Test: `installer/tests/test_database.py`

- [ ] **Step 1: Write the failing test**

`installer/tests/test_database.py`:

```python
from unittest.mock import patch, MagicMock

import pytest

from installer.errors import InstallerError
from installer.log_queue import LogQueue
from installer.steps.database import (
    wait_for_mysql,
    run_migrations,
    seed_php_versions,
    create_admin_user,
)


def _fake_run(returncode: int):
    proc = MagicMock()
    proc.returncode = returncode
    proc.stdout = ""
    return proc


def test_wait_for_mysql_returns_on_success():
    with patch("installer.steps.database.subprocess.run", return_value=_fake_run(0)):
        wait_for_mysql(root_password="rootpw", timeout=5, interval=0.01)


def test_wait_for_mysql_raises_on_timeout():
    with patch("installer.steps.database.subprocess.run", return_value=_fake_run(1)):
        with pytest.raises(InstallerError) as exc:
            wait_for_mysql(root_password="rootpw", timeout=0.05, interval=0.01)
        assert exc.value.phase == "mysql_wait"


def test_run_migrations_streams_and_raises_on_failure():
    q = LogQueue()
    with patch("installer.steps.database.subprocess.Popen") as mock_popen:
        proc = MagicMock()
        proc.stdout = iter(["migrated FooTable\n"])
        proc.wait.return_value = 0
        mock_popen.return_value = proc
        run_migrations(log_queue=q)
    q.close()
    lines = [i["text"] for i in q.stream()]
    assert any("migrated FooTable" in l for l in lines)


def test_seed_php_versions_uses_expected_artisan_args():
    q = LogQueue()
    with patch("installer.steps.database.subprocess.Popen") as mock_popen:
        proc = MagicMock()
        proc.stdout = iter([])
        proc.wait.return_value = 0
        mock_popen.return_value = proc
        seed_php_versions(log_queue=q)
        args, _ = mock_popen.call_args
        assert "db:seed" in args[0]
        assert "--class=PhpVersionSeeder" in args[0]
        assert "--force" in args[0]


def test_create_admin_user_passes_all_flags():
    q = LogQueue()
    with patch("installer.steps.database.subprocess.Popen") as mock_popen:
        proc = MagicMock()
        proc.stdout = iter([])
        proc.wait.return_value = 0
        mock_popen.return_value = proc
        create_admin_user(
            name="A B",
            username="admin",
            email="a@b.com",
            password="secret",
            log_queue=q,
        )
        args, _ = mock_popen.call_args
        full_cmd = " ".join(args[0])
        assert "app:add-admin-user" in full_cmd
        assert "--name=A B" in full_cmd
        assert "--username=admin" in full_cmd
        assert "--email=a@b.com" in full_cmd
        assert "--password=secret" in full_cmd
```

- [ ] **Step 2: Run test to verify it fails**

Run: `python -m pytest installer/tests/test_database.py -v`
Expected: FAIL, missing module.

- [ ] **Step 3: Write implementation**

`installer/steps/database.py`:

```python
from __future__ import annotations

import subprocess
import time

from installer.errors import InstallerError
from installer.log_queue import LogQueue


def wait_for_mysql(root_password: str, timeout: int = 180, interval: float = 3.0) -> None:
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        result = subprocess.run(
            [
                "docker", "exec", "mysql",
                "mysqladmin", "ping", "-h127.0.0.1",
                "-uroot", f"-p{root_password}", "--silent",
            ],
            capture_output=True,
            text=True,
        )
        if result.returncode == 0:
            return
        time.sleep(interval)
    raise InstallerError("mysql_wait", f"MySQL did not become ready in {timeout}s")


def _run_artisan_streaming(args: list[str], log_queue: LogQueue, phase: str) -> None:
    cmd = ["docker", "exec", "alpha_panel_web", "php", "artisan", *args]
    log_queue.put({"type": "line", "text": f"$ {' '.join(cmd)}"})
    proc = subprocess.Popen(
        cmd,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        text=True,
        bufsize=1,
    )
    for line in proc.stdout:
        log_queue.put({"type": "line", "text": line.rstrip("\n")})
    rc = proc.wait()
    if rc != 0:
        raise InstallerError(phase, f"artisan {args[0]} exited with {rc}")


def run_migrations(log_queue: LogQueue) -> None:
    _run_artisan_streaming(["migrate", "--force"], log_queue, "migrate")


def seed_php_versions(log_queue: LogQueue) -> None:
    _run_artisan_streaming(
        ["db:seed", "--class=PhpVersionSeeder", "--force"],
        log_queue,
        "seed",
    )


def create_admin_user(
    name: str,
    username: str,
    email: str,
    password: str,
    log_queue: LogQueue,
) -> None:
    _run_artisan_streaming(
        [
            "app:add-admin-user",
            f"--name={name}",
            f"--username={username}",
            f"--email={email}",
            f"--password={password}",
        ],
        log_queue,
        "admin_user",
    )
```

- [ ] **Step 4: Run test to verify it passes**

Run: `python -m pytest installer/tests/test_database.py -v`
Expected: 5 passed.

- [ ] **Step 5: Stage**

```bash
git add installer/steps/database.py installer/tests/test_database.py
```

---

## Task 16: `installer/steps/caddy_reload.py` — Reload Caddy After SSL

**Files:**
- Create: `installer/steps/caddy_reload.py`
- Test: `installer/tests/test_caddy_reload.py`

- [ ] **Step 1: Write the failing test**

`installer/tests/test_caddy_reload.py`:

```python
from unittest.mock import patch, MagicMock

import pytest

from installer.errors import InstallerError
from installer.log_queue import LogQueue
from installer.steps.caddy_reload import reload_caddy


def test_reload_caddy_runs_panel_apply_and_reloads():
    q = LogQueue()
    proc = MagicMock()
    proc.stdout = iter(["applied\n"])
    proc.wait.return_value = 0
    with patch("installer.steps.caddy_reload.subprocess.Popen", return_value=proc) as popen:
        reload_caddy(log_queue=q)
    q.close()
    lines = [i["text"] for i in q.stream()]
    assert any("applied" in l for l in lines)
    args, _ = popen.call_args
    assert "panel:apply" in args[0]


def test_reload_caddy_raises_on_nonzero():
    q = LogQueue()
    proc = MagicMock()
    proc.stdout = iter([])
    proc.wait.return_value = 1
    with patch("installer.steps.caddy_reload.subprocess.Popen", return_value=proc):
        with pytest.raises(InstallerError):
            reload_caddy(log_queue=q)
```

- [ ] **Step 2: Run test to verify it fails**

Run: `python -m pytest installer/tests/test_caddy_reload.py -v`
Expected: FAIL, missing module.

- [ ] **Step 3: Write implementation**

`installer/steps/caddy_reload.py`:

```python
from __future__ import annotations

import subprocess

from installer.errors import InstallerError
from installer.log_queue import LogQueue


def reload_caddy(log_queue: LogQueue) -> None:
    """
    Run `php artisan panel:apply` inside the alpha_panel_web container.
    panel:apply regenerates the Caddyfiles and asks Caddy to reload via its
    admin API (which the panel already knows how to reach through the
    FrankenPHP container).
    """
    cmd = ["docker", "exec", "alpha_panel_web", "php", "artisan", "panel:apply"]
    log_queue.put({"type": "line", "text": f"$ {' '.join(cmd)}"})
    proc = subprocess.Popen(
        cmd,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        text=True,
        bufsize=1,
    )
    for line in proc.stdout:
        log_queue.put({"type": "line", "text": line.rstrip("\n")})
    rc = proc.wait()
    if rc != 0:
        raise InstallerError("caddy_reload", f"panel:apply exited with code {rc}")
```

- [ ] **Step 4: Run test to verify it passes**

Run: `python -m pytest installer/tests/test_caddy_reload.py -v`
Expected: 2 passed.

- [ ] **Step 5: Stage**

```bash
git add installer/steps/caddy_reload.py installer/tests/test_caddy_reload.py
```

---

## Task 17: `installer/steps/reset.py` — Full Teardown

**Files:**
- Create: `installer/steps/reset.py`
- Test: `installer/tests/test_reset.py`

- [ ] **Step 1: Write the failing test**

`installer/tests/test_reset.py`:

```python
from pathlib import Path
from unittest.mock import patch, MagicMock

from installer.log_queue import LogQueue
from installer.steps.reset import reset_installation


def test_reset_runs_docker_down_and_deletes_state(tmp_path: Path):
    state_file = tmp_path / ".installer_state.json"
    state_file.write_text("{}")
    env_file = tmp_path / ".env"
    env_file.write_text("X=1\n")
    laravel_env = tmp_path / "alpha-panel" / "web" / "httpdocs" / ".env"
    laravel_env.parent.mkdir(parents=True)
    laravel_env.write_text("Y=2\n")

    q = LogQueue()
    proc = MagicMock()
    proc.stdout = iter(["stopped\n"])
    proc.wait.return_value = 0
    with patch("installer.steps.reset.subprocess.Popen", return_value=proc) as popen:
        reset_installation(
            project_dir=tmp_path,
            state_file=state_file,
            log_queue=q,
        )

    # docker compose down -v --remove-orphans was run
    args, _ = popen.call_args
    assert "docker" in args[0]
    assert "compose" in args[0]
    assert "down" in args[0]
    assert "-v" in args[0]

    # state + env files removed
    assert not state_file.exists()
    assert not env_file.exists()
    assert not laravel_env.exists()


def test_reset_is_idempotent_with_missing_files(tmp_path: Path):
    q = LogQueue()
    proc = MagicMock()
    proc.stdout = iter([])
    proc.wait.return_value = 0
    with patch("installer.steps.reset.subprocess.Popen", return_value=proc):
        reset_installation(
            project_dir=tmp_path,
            state_file=tmp_path / "missing.json",
            log_queue=q,
        )
```

- [ ] **Step 2: Run test to verify it fails**

Run: `python -m pytest installer/tests/test_reset.py -v`
Expected: FAIL, missing module.

- [ ] **Step 3: Write implementation**

`installer/steps/reset.py`:

```python
from __future__ import annotations

import subprocess
from pathlib import Path

from installer.log_queue import LogQueue


def _rm_if_exists(path: Path) -> None:
    if path.exists():
        path.unlink()


def reset_installation(
    project_dir: Path,
    state_file: Path,
    log_queue: LogQueue,
) -> None:
    cmd = ["docker", "compose", "down", "-v", "--remove-orphans"]
    log_queue.put({"type": "line", "text": f"$ {' '.join(cmd)} (in {project_dir})"})
    proc = subprocess.Popen(
        cmd,
        cwd=str(project_dir),
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        text=True,
        bufsize=1,
    )
    for line in proc.stdout:
        log_queue.put({"type": "line", "text": line.rstrip("\n")})
    proc.wait()
    # Tolerate non-zero exit — stack may not have existed.

    _rm_if_exists(state_file)
    _rm_if_exists(project_dir / ".env")
    _rm_if_exists(project_dir / "alpha-panel" / "web" / "httpdocs" / ".env")
```

- [ ] **Step 4: Run test to verify it passes**

Run: `python -m pytest installer/tests/test_reset.py -v`
Expected: 2 passed.

- [ ] **Step 5: Stage**

```bash
git add installer/steps/reset.py installer/tests/test_reset.py
```

---

## Task 18: `installer/app.py` — Flask App, Routes, SSE, Install Thread

**Files:**
- Create: `installer/app.py`
- Test: `installer/tests/test_app.py`

**Context:** `app.py` binds together everything built so far. It is the only file with cross-cutting knowledge. Keep it small.

- [ ] **Step 1: Write the failing test**

`installer/tests/test_app.py`:

```python
import json
from unittest.mock import patch

import pytest

from installer.app import create_app


@pytest.fixture
def client(tmp_path):
    app = create_app(
        project_dir=tmp_path,
        state_file=tmp_path / ".installer_state.json",
    )
    app.config.update({"TESTING": True})
    return app.test_client()


def test_get_state_returns_null_when_no_state(client):
    resp = client.get("/api/state")
    assert resp.status_code == 200
    assert resp.get_json() == {"state": None}


def test_detect_returns_os_ip_info(client):
    with patch("installer.app.detect_os", return_value={"id": "ubuntu", "pretty": "Ubuntu 22.04"}), \
         patch("installer.app.detect_private_ip", return_value="10.0.0.5"), \
         patch("installer.app.detect_public_ip", return_value="203.0.113.5"):
        resp = client.post("/api/detect")
        assert resp.status_code == 200
        data = resp.get_json()
        assert data["os"]["id"] == "ubuntu"
        assert data["private_ip"] == "10.0.0.5"
        assert data["public_ip"] == "203.0.113.5"


def test_verify_cf_token_returns_ok_on_valid(client):
    with patch("installer.app.verify_token", return_value=True):
        resp = client.post(
            "/api/verify-cf-token",
            data=json.dumps({"token": "good"}),
            content_type="application/json",
        )
        assert resp.status_code == 200
        assert resp.get_json() == {"valid": True}


def test_verify_cf_token_returns_error_on_invalid(client):
    from installer.errors import InstallerError
    with patch("installer.app.verify_token", side_effect=InstallerError("cloudflare_verify", "bad")):
        resp = client.post(
            "/api/verify-cf-token",
            data=json.dumps({"token": "bad"}),
            content_type="application/json",
        )
        assert resp.status_code == 400
        body = resp.get_json()
        assert body["valid"] is False
        assert body["phase"] == "cloudflare_verify"


def test_shutdown_schedules_exit(client):
    with patch("installer.app.threading.Timer") as mock_timer:
        resp = client.post("/api/shutdown")
        assert resp.status_code == 200
        mock_timer.assert_called_once()
```

- [ ] **Step 2: Run test to verify it fails**

Run: `python -m pytest installer/tests/test_app.py -v`
Expected: FAIL, missing module.

- [ ] **Step 3: Write implementation**

`installer/app.py`:

```python
from __future__ import annotations

import json
import os
import threading
from dataclasses import asdict
from pathlib import Path

from flask import Flask, Response, jsonify, request, render_template, stream_with_context

from installer.errors import InstallerError
from installer.log_queue import LogQueue
from installer.secrets_gen import gen_all_panel_secrets
from installer.state import InstallerState, clear_state, load_state, save_state
from installer.steps.caddyfile import (
    write_base_domain_caddyfile,
    write_jenkins_caddyfile,
)
from installer.steps.caddy_reload import reload_caddy
from installer.steps.cloudflare import verify_token, write_cloudflare_ini
from installer.steps.compose import compose_up
from installer.steps.database import (
    create_admin_user,
    run_migrations,
    seed_php_versions,
    wait_for_mysql,
)
from installer.steps.directories import ensure_data_directories
from installer.steps.env_writer import (
    set_portainer_credentials,
    write_laravel_env,
    write_root_env,
)
from installer.steps.portainer import (
    create_access_token,
    detect_endpoint_id,
    init_portainer_admin,
    wait_for_portainer,
)
from installer.steps.reset import reset_installation
from installer.steps.ssh_key import ensure_ssh_key
from installer.steps.ssl import issue_panel_certificate
from installer.steps.system import detect_os, detect_private_ip, detect_public_ip


def create_app(project_dir: Path, state_file: Path) -> Flask:
    app = Flask(
        __name__,
        template_folder=str(Path(__file__).parent / "templates"),
        static_folder=str(Path(__file__).parent / "static"),
    )

    log_queue_ref: dict[str, LogQueue | None] = {"q": None}
    install_thread_ref: dict[str, threading.Thread | None] = {"t": None}

    @app.route("/")
    def index():
        return render_template("wizard.html")

    @app.route("/api/state")
    def api_state():
        state = load_state(state_file)
        return jsonify({"state": asdict(state) if state else None})

    @app.post("/api/detect")
    def api_detect():
        return jsonify(
            {
                "os": detect_os(),
                "private_ip": detect_private_ip(),
                "public_ip": detect_public_ip(),
            }
        )

    @app.post("/api/verify-cf-token")
    def api_verify_cf():
        data = request.get_json(force=True)
        try:
            verify_token(data.get("token", ""))
            return jsonify({"valid": True})
        except InstallerError as e:
            return jsonify({"valid": False, "phase": e.phase, "message": e.message}), 400

    @app.post("/api/reset")
    def api_reset():
        q = LogQueue()
        log_queue_ref["q"] = q

        def run():
            try:
                reset_installation(project_dir=project_dir, state_file=state_file, log_queue=q)
            finally:
                q.close()

        threading.Thread(target=run, daemon=True).start()
        return jsonify({"started": True})

    @app.post("/api/submit")
    def api_submit():
        form = request.get_json(force=True)
        state = load_state(state_file) or InstallerState()
        state.form = form
        if not state.generated_secrets:
            state.generated_secrets = gen_all_panel_secrets()
        state.current_phase = "starting"
        state.last_error = None
        save_state(state_file, state)

        q = LogQueue()
        log_queue_ref["q"] = q
        t = threading.Thread(
            target=_run_install,
            args=(project_dir, state_file, state, q),
            daemon=True,
        )
        install_thread_ref["t"] = t
        t.start()
        return jsonify({"started": True})

    @app.route("/api/progress")
    def api_progress():
        q = log_queue_ref["q"]
        if q is None:
            return jsonify({"error": "no install running"}), 400

        @stream_with_context
        def stream():
            for item in q.stream():
                yield f"data: {json.dumps(item)}\n\n"
            yield 'data: {"type":"done"}\n\n'

        return Response(stream(), mimetype="text/event-stream")

    @app.post("/api/shutdown")
    def api_shutdown():
        def _exit():
            os._exit(0)

        threading.Timer(2.0, _exit).start()
        return jsonify({"shutdown_in": 2})

    return app


def _run_install(
    project_dir: Path,
    state_file: Path,
    state: InstallerState,
    q: LogQueue,
) -> None:
    form = state.form
    secrets = state.generated_secrets

    phases = [
        ("directories", lambda: ensure_data_directories(project_dir, form["base_domain"])),
        ("root_env", lambda: write_root_env(project_dir / ".env", form=form, secrets=secrets)),
        ("laravel_env", lambda: write_laravel_env(
            target=project_dir / "alpha-panel" / "web" / "httpdocs" / ".env",
            example=project_dir / "alpha-panel" / "web" / "httpdocs" / ".env.example",
            form=form,
            secrets=secrets,
            install_dir=str(project_dir),
        )),
        ("cloudflare_ini", lambda: write_cloudflare_ini(
            project_dir / "secrets" / "cloudflare.ini",
            token=form["cf_api_token"],
        )),
        ("caddyfiles", lambda: (
            write_base_domain_caddyfile(
                project_dir / "frankenphp" / "sites-enabled" / form["base_domain"] / "Caddyfile",
                base_domain=form["base_domain"],
            ),
            write_jenkins_caddyfile(
                project_dir / "frankenphp" / "sites-enabled" / form["jenkins_domain"] / "Caddyfile",
                base_domain=form["base_domain"],
                jenkins_domain=form["jenkins_domain"],
                admin_ips=form.get("jenkins_admin_ips", ""),
            ),
        )),
        ("ssh_key", lambda: ensure_ssh_key(
            key_dir=project_dir / "alpha-panel" / "web" / "ssh-keys",
            authorized_keys_path=Path("/root/.ssh/authorized_keys"),
            comment=f"alphapanel-terminal@{os.uname().nodename}",
        )),
        ("compose_up", lambda: compose_up(project_dir, q)),
        ("portainer_wait", lambda: wait_for_portainer(
            f"http://{form['private_ip']}:9000",
        )),
        ("portainer_admin", lambda: init_portainer_admin(
            f"http://{form['private_ip']}:9000",
            form["portainer_admin_user"],
            form["portainer_admin_password"],
        )),
        ("portainer_token", lambda: _portainer_token_phase(form, project_dir)),
        ("mysql_wait", lambda: wait_for_mysql(secrets["mysql_root_password"])),
        ("migrate", lambda: run_migrations(q)),
        ("seed", lambda: seed_php_versions(q)),
        ("admin_user", lambda: create_admin_user(
            name=form["panel_admin_name"],
            username=form["panel_admin_username"],
            email=form["panel_admin_email"],
            password=form["panel_admin_password"],
            log_queue=q,
        )),
        ("ssl", lambda: issue_panel_certificate(
            base_domain=form["base_domain"],
            admin_email=form["admin_email"],
            token_file="/secrets/cloudflare.ini",
            container="alpha_panel_web",
            log_queue=q,
        )),
        ("caddy_reload", lambda: reload_caddy(q)),
    ]

    try:
        for name, fn in phases:
            if name in state.completed_phases:
                q.put({"type": "line", "text": f"[skip] {name} already completed"})
                continue
            q.put({"type": "phase", "name": name})
            fn()
            state.completed_phases.append(name)
            state.current_phase = name
            save_state(state_file, state)
        state.current_phase = "done"
        save_state(state_file, state)
        q.put({"type": "done", "panel_url": f"https://{form['panel_domain']}:8443"})
    except InstallerError as e:
        state.last_error = {"phase": e.phase, "message": e.message, "detail": e.detail}
        save_state(state_file, state)
        q.put({"type": "error", "phase": e.phase, "message": e.message})
    except Exception as e:
        state.last_error = {"phase": "unknown", "message": str(e)}
        save_state(state_file, state)
        q.put({"type": "error", "phase": "unknown", "message": str(e)})
    finally:
        q.close()


def _portainer_token_phase(form: dict, project_dir: Path) -> None:
    base_url = f"http://{form['private_ip']}:9000"
    token = create_access_token(
        base_url,
        form["portainer_admin_user"],
        form["portainer_admin_password"],
    )
    endpoint_id = detect_endpoint_id(base_url, token)
    set_portainer_credentials(
        laravel_env=project_dir / "alpha-panel" / "web" / "httpdocs" / ".env",
        api_key=token,
        endpoint_id=endpoint_id,
    )


def main() -> None:
    project_dir = Path(os.environ.get("ALPHAPANEL_PROJECT_DIR", "/opt/alphapanel-docker"))
    state_file = project_dir / ".installer_state.json"
    app = create_app(project_dir=project_dir, state_file=state_file)
    host = "0.0.0.0"
    port = int(os.environ.get("ALPHAPANEL_INSTALLER_PORT", "5000"))
    print(f"Installer running at http://{host}:{port}")
    app.run(host=host, port=port, threaded=True)


if __name__ == "__main__":
    main()
```

- [ ] **Step 4: Run test to verify it passes**

Run: `python -m pytest installer/tests/test_app.py -v`
Expected: 5 passed.

- [ ] **Step 5: Stage**

```bash
git add installer/app.py installer/tests/test_app.py
```

---

## Task 19: Wizard HTML + JS + CSS

**Files:**
- Create: `installer/templates/wizard.html`
- Create: `installer/static/wizard.js`
- Create: `installer/static/wizard.css`

**No tests for pure markup.** Manual verification via browser happens in Task 22.

- [ ] **Step 1: Write `installer/templates/wizard.html`**

```html
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>AlphaPanel Installer</title>
  <link rel="stylesheet" href="{{ url_for('static', filename='wizard.css') }}">
</head>
<body>
  <header>
    <h1>AlphaPanel-Docker Installer</h1>
    <nav id="stepper"></nav>
  </header>

  <main>
    <section id="step-welcome" class="step">
      <h2>Welcome</h2>
      <p>This installer sets up AlphaPanel-Docker in 8 steps. Nothing is committed until the final screen.</p>
      <div id="resume-banner" hidden>
        <p><strong>A previous installation did not finish.</strong></p>
        <div id="resume-error"></div>
        <button id="btn-resume">Resume</button>
        <button id="btn-reset">Reset &amp; start fresh</button>
      </div>
      <button class="next">Next</button>
    </section>

    <section id="step-system" class="step" hidden>
      <h2>System Detection</h2>
      <dl id="system-info"></dl>
      <button class="prev">Back</button>
      <button class="next">Next</button>
    </section>

    <section id="step-domains" class="step" hidden>
      <h2>Domains</h2>
      <form id="form-domains">
        <label>Base domain (apex) <input name="base_domain" required placeholder="example.com"></label>
        <label>Panel <input name="panel_domain" required></label>
        <label>phpMyAdmin <input name="pma_domain" required></label>
        <label>File manager <input name="code_server_domain" required></label>
        <label>Vaultwarden <input name="vaultwarden_domain" required></label>
        <label>N8N <input name="n8n_domain" required></label>
        <label>Portainer <input name="portainer_domain" required></label>
        <label>Jenkins <input name="jenkins_domain" required></label>
        <label>Jenkins admin IPs (optional, CIDRs comma-sep) <input name="jenkins_admin_ips"></label>
      </form>
      <button class="prev">Back</button>
      <button class="next" data-form="form-domains">Next</button>
    </section>

    <section id="step-creds" class="step" hidden>
      <h2>Credentials</h2>
      <form id="form-creds">
        <label>Cloudflare API token <input type="password" name="cf_api_token" required></label>
        <label>Admin email (for Let's Encrypt) <input type="email" name="admin_email" required></label>
        <hr>
        <p>Portainer admin account — used to sign in at <code>https://&lt;portainer domain&gt;</code></p>
        <label>Portainer admin username <input name="portainer_admin_user" value="admin" required></label>
        <label>Portainer admin password (min 12 chars) <input type="password" name="portainer_admin_password" required minlength="12"></label>
      </form>
      <button class="prev">Back</button>
      <button class="next" data-form="form-creds">Next</button>
    </section>

    <section id="step-admin" class="step" hidden>
      <h2>Panel Admin Account</h2>
      <form id="form-admin">
        <label>Display name <input name="panel_admin_name" required></label>
        <label>Username <input name="panel_admin_username" value="admin" required></label>
        <label>Email <input type="email" name="panel_admin_email" required></label>
        <label>Password <input type="password" name="panel_admin_password" required minlength="8"></label>
      </form>
      <button class="prev">Back</button>
      <button class="next" data-form="form-admin">Next</button>
    </section>

    <section id="step-summary" class="step" hidden>
      <h2>Confirm &amp; Start</h2>
      <div id="summary-body"></div>
      <button class="prev">Back</button>
      <button id="btn-start">Start installation</button>
    </section>

    <section id="step-progress" class="step" hidden>
      <h2>Installing…</h2>
      <div id="current-phase"></div>
      <pre id="log"></pre>
      <div id="progress-error" hidden></div>
    </section>

    <section id="step-done" class="step" hidden>
      <h2>All set.</h2>
      <p>Opening the panel in a new tab… installer will shut down in 2 seconds.</p>
      <ul id="service-urls"></ul>
    </section>
  </main>

  <script src="{{ url_for('static', filename='wizard.js') }}"></script>
</body>
</html>
```

- [ ] **Step 2: Write `installer/static/wizard.js`**

```javascript
const steps = ["welcome", "system", "domains", "creds", "admin", "summary", "progress", "done"];
const form = {};
let currentIdx = 0;

function show(idx) {
  steps.forEach((s, i) => {
    document.getElementById(`step-${s}`).hidden = i !== idx;
  });
  currentIdx = idx;
}

function next() { show(currentIdx + 1); }
function prev() { show(currentIdx - 1); }

async function init() {
  const r = await fetch("/api/state");
  const { state } = await r.json();
  if (state && state.current_phase && state.current_phase !== "done") {
    const banner = document.getElementById("resume-banner");
    banner.hidden = false;
    if (state.last_error) {
      document.getElementById("resume-error").textContent =
        `Failed at ${state.last_error.phase}: ${state.last_error.message}`;
    }
  }

  const sys = await (await fetch("/api/detect", { method: "POST" })).json();
  const dl = document.getElementById("system-info");
  dl.innerHTML = `
    <dt>OS</dt><dd>${sys.os.pretty}</dd>
    <dt>Private IP</dt><dd>${sys.private_ip}</dd>
    <dt>Public IP</dt><dd>${sys.public_ip}</dd>`;
  form.private_ip = sys.private_ip;
  form.public_ip = sys.public_ip;

  bindNav();
  bindDomainsAutofill();
  bindStart();
  bindReset();
}

function bindNav() {
  document.querySelectorAll(".next").forEach(btn => {
    btn.addEventListener("click", async () => {
      const formId = btn.dataset.form;
      if (formId) {
        const el = document.getElementById(formId);
        if (!el.reportValidity()) return;
        for (const input of el.querySelectorAll("input")) {
          form[input.name] = input.value;
        }
        if (formId === "form-creds") {
          const resp = await fetch("/api/verify-cf-token", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ token: form.cf_api_token }),
          });
          if (!resp.ok) {
            const err = await resp.json();
            alert(`Cloudflare token invalid: ${err.message}`);
            return;
          }
        }
      }
      if (steps[currentIdx + 1] === "summary") renderSummary();
      next();
    });
  });
  document.querySelectorAll(".prev").forEach(btn => btn.addEventListener("click", prev));
}

function bindDomainsAutofill() {
  const base = document.querySelector("input[name='base_domain']");
  base.addEventListener("blur", () => {
    if (!base.value) return;
    const mapping = {
      panel_domain: `server.${base.value}`,
      pma_domain: `pma.${base.value}`,
      code_server_domain: `file.${base.value}`,
      vaultwarden_domain: `password.${base.value}`,
      n8n_domain: `n8n.${base.value}`,
      portainer_domain: `portainer.${base.value}`,
      jenkins_domain: `jenkins.${base.value}`,
    };
    for (const [name, value] of Object.entries(mapping)) {
      const input = document.querySelector(`input[name='${name}']`);
      if (input && !input.value) input.value = value;
    }
  });
}

function renderSummary() {
  const hidden = ["cf_api_token", "portainer_admin_password", "panel_admin_password"];
  const rows = Object.entries(form)
    .filter(([k]) => !hidden.includes(k))
    .map(([k, v]) => `<tr><td>${k}</td><td>${v}</td></tr>`)
    .join("");
  document.getElementById("summary-body").innerHTML = `<table>${rows}</table>`;
}

function bindStart() {
  document.getElementById("btn-start").addEventListener("click", async () => {
    show(steps.indexOf("progress"));
    await fetch("/api/submit", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(form),
    });
    streamProgress();
  });
}

function streamProgress() {
  const log = document.getElementById("log");
  const phaseLabel = document.getElementById("current-phase");
  const errorPanel = document.getElementById("progress-error");
  const es = new EventSource("/api/progress");
  es.onmessage = (e) => {
    const msg = JSON.parse(e.data);
    if (msg.type === "line") {
      log.textContent += msg.text + "\n";
      log.scrollTop = log.scrollHeight;
    } else if (msg.type === "phase") {
      phaseLabel.textContent = `→ ${msg.name}`;
    } else if (msg.type === "error") {
      errorPanel.hidden = false;
      errorPanel.innerHTML = `<p>Failed at <strong>${msg.phase}</strong>: ${msg.message}</p>
        <button onclick="location.reload()">Reload and resume</button>`;
      es.close();
    } else if (msg.type === "done") {
      es.close();
      renderDone(msg.panel_url);
      show(steps.indexOf("done"));
      window.open(msg.panel_url, "_blank");
      fetch("/api/shutdown", { method: "POST" });
    }
  };
}

function renderDone(panelUrl) {
  const ul = document.getElementById("service-urls");
  const subs = {
    Panel: panelUrl,
    phpMyAdmin: `https://${form.pma_domain}:8443`,
    "File manager": `https://${form.code_server_domain}:8443`,
    Portainer: `https://${form.portainer_domain}:8443`,
    N8N: `https://${form.n8n_domain}:8443`,
    Passwords: `https://${form.vaultwarden_domain}:8443`,
  };
  ul.innerHTML = Object.entries(subs)
    .map(([k, v]) => `<li>${k}: <a href="${v}">${v}</a></li>`)
    .join("");
}

function bindReset() {
  document.getElementById("btn-reset").addEventListener("click", async () => {
    if (!confirm("This will run `docker compose down -v` and delete all .env files. Continue?")) return;
    await fetch("/api/reset", { method: "POST" });
    location.reload();
  });
  document.getElementById("btn-resume").addEventListener("click", () => {
    show(steps.indexOf("progress"));
    fetch("/api/submit", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(form),
    });
    streamProgress();
  });
}

document.addEventListener("DOMContentLoaded", init);
```

- [ ] **Step 3: Write `installer/static/wizard.css`**

```css
:root { font-family: system-ui, sans-serif; color-scheme: light dark; }
body { max-width: 960px; margin: 2rem auto; padding: 0 1rem; }
header h1 { margin: 0 0 1rem; font-size: 1.3rem; }
.step { border: 1px solid currentColor; padding: 1.5rem; border-radius: 8px; }
.step h2 { margin-top: 0; }
form label { display: block; margin-bottom: 0.75rem; }
form label input { display: block; width: 100%; padding: 0.4rem; box-sizing: border-box; font: inherit; }
button { padding: 0.5rem 1rem; margin-right: 0.5rem; cursor: pointer; font: inherit; }
table { border-collapse: collapse; width: 100%; }
td { padding: 0.25rem 0.5rem; border-bottom: 1px solid #8884; }
pre#log { background: #0002; padding: 1rem; max-height: 400px; overflow: auto; font-size: 0.85rem; white-space: pre-wrap; }
#progress-error { background: #f882; border: 1px solid #f00a; padding: 0.75rem; border-radius: 4px; }
#resume-banner { background: #fe82; padding: 0.75rem; border-radius: 4px; margin: 1rem 0; }
```

- [ ] **Step 4: Stage**

```bash
git add installer/templates/ installer/static/
```

---

## Task 20: New `install.sh` Bootstrap

**Files:**
- Modify: `install.sh` — replace entire contents

- [ ] **Step 1: Write the new bootstrap**

Replace `install.sh` contents with:

```bash
#!/usr/bin/env bash
# AlphaPanel-Docker Installer Bootstrap
# Installs prerequisites, then hands off to the Python Flask wizard.
set -euo pipefail

REPO_URL="${ALPHAPANEL_REPO_URL:-git@github.com:Alpha-Panel/AlphaPanel.git}"
INSTALL_DIR="${ALPHAPANEL_INSTALL_DIR:-/opt/alphapanel-docker}"
INSTALLER_PORT="${ALPHAPANEL_INSTALLER_PORT:-5000}"

say()  { echo -e "\033[0;36m=>\033[0m $*"; }
ok()   { echo -e "  \033[0;32m✓\033[0m $*"; }
die()  { echo -e "\033[0;31m✗ ERROR:\033[0m $*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "Run as root: sudo bash install.sh"

. /etc/os-release
case "${ID:-unknown}" in
    ubuntu|debian)
        INSTALL="apt-get install -y"
        apt-get update -qq
        ;;
    centos|rhel|rocky|almalinux|fedora)
        INSTALL="dnf install -y"
        ;;
    *)
        die "Unsupported OS: ${ID:-unknown}"
        ;;
esac

say "Installing prerequisites (curl, git, python3, python3-venv, python3-pip)..."
case "${ID}" in
    ubuntu|debian)
        $INSTALL curl git python3 python3-venv python3-pip >/dev/null
        ;;
    *)
        $INSTALL curl git python3 python3-pip >/dev/null
        ;;
esac
ok "Prerequisites installed."

if ! command -v docker &>/dev/null; then
    say "Installing Docker via get.docker.com..."
    curl -fsSL https://get.docker.com | sh
    systemctl enable --now docker
    ok "Docker installed."
fi
docker compose version >/dev/null 2>&1 || die "Docker Compose plugin missing. Install manually: https://docs.docker.com/compose/install/"

say "Cloning/updating repo at ${INSTALL_DIR}..."
if [ -d "${INSTALL_DIR}/.git" ]; then
    git -C "${INSTALL_DIR}" pull --ff-only
else
    git clone "${REPO_URL}" "${INSTALL_DIR}"
fi
cd "${INSTALL_DIR}"
ok "Repo ready."

say "Creating installer virtualenv..."
if [ ! -x ".installer-venv/bin/python" ]; then
    python3 -m venv .installer-venv
fi
.installer-venv/bin/pip install --quiet --upgrade pip
.installer-venv/bin/pip install --quiet -r installer/requirements.txt
ok "Virtualenv ready."

HOST_IP=$(ip route get 1.1.1.1 2>/dev/null | awk '/src/ {for(i=1;i<=NF;i++) if($i=="src") print $(i+1); exit}')
echo ""
echo "─────────────────────────────────────────────"
echo "  Installer running at:"
echo "    http://${HOST_IP:-localhost}:${INSTALLER_PORT}"
echo ""
echo "  Open the URL in your browser to continue."
echo "─────────────────────────────────────────────"
echo ""

export ALPHAPANEL_PROJECT_DIR="${INSTALL_DIR}"
export ALPHAPANEL_INSTALLER_PORT="${INSTALLER_PORT}"
exec .installer-venv/bin/python -m installer.app
```

- [ ] **Step 2: Make it executable**

Run: `chmod +x install.sh`

- [ ] **Step 3: Syntax check**

Run: `bash -n install.sh`
Expected: no output (syntax OK).

- [ ] **Step 4: Verify line count under 100**

Run: `wc -l install.sh`
Expected: fewer than 100 lines. Current output should be around 70 lines.

- [ ] **Step 5: Stage**

```bash
git add install.sh
```

---

## Task 21: Full Test Suite Run

**Files:** — no code changes; verification only.

- [ ] **Step 1: Run the entire Python test suite**

Run: `python -m pytest installer/tests/ -v`
Expected: All tests pass. Every step, app, state, secrets module reports green.

- [ ] **Step 2: Run the new Laravel test (if DB is reachable)**

The user notes local tests fail on DB — they are expected to pass only inside the Docker stack. If local MySQL is not available, skip and record in the review log. Inside the stack:

```bash
docker exec alpha_panel_web php artisan test --compact --filter=IssueInstallerCertCommandTest
```

- [ ] **Step 3: Stage the plan file itself**

```bash
git add docs/superpowers/plans/2026-04-21-web-installer.md
```

---

## Task 22: Manual End-to-End Verification (on a disposable VM)

**No code.** This is the gatekeeper before marking the feature complete.

- [ ] **Step 1: Spin up a fresh Ubuntu 22.04 VM** (DigitalOcean, Hetzner, Vagrant — any clean root).

- [ ] **Step 2: Point a test base domain at the VM's public IP** — at least an A record for `server.<test-domain>` and a wildcard CNAME/A for `*.<test-domain>`. The rest of the wildcard cert handles subdomains automatically.

- [ ] **Step 3: Ensure Cloudflare API token is an Edit zone DNS token scoped to the test domain.**

- [ ] **Step 4: Run the installer**

```bash
curl -fsSL https://<raw-repo-url>/install.sh | sudo bash
```

- [ ] **Step 5: Walk through the wizard in your laptop browser at the URL the bootstrap printed.** Confirm:

  - System detection screen shows correct IPs and OS.
  - Domain form auto-fills subdomains from the apex.
  - Cloudflare token validation passes.
  - Progress screen scrolls logs with named phases.
  - No phase fails.

- [ ] **Step 6: Verify panel opens over HTTPS with a valid LE cert.**

  - New tab opens `https://server.<test-domain>:8443` automatically.
  - Browser shows padlock (not self-signed warning).
  - Login with the admin credentials works.
  - Open the browser dev console — Reverb WebSocket connects to `wss://` with no TLS error.

- [ ] **Step 7: Verify installer shut down**

```bash
curl -fsSLm 2 "http://<vm-ip>:5000" || echo "installer down (expected)"
```

- [ ] **Step 8: Verify `docker compose ps` shows all services healthy.**

- [ ] **Step 9: Re-run the installer on the same VM**

```bash
sudo bash /opt/alphapanel-docker/install.sh
```

The wizard should open on "Resume" mode, skip all completed phases, and land on the Done screen without changing anything.

- [ ] **Step 10: Test Reset**

From the Welcome screen, click "Reset". Confirm all data volumes are gone (`docker volume ls`) and `.env` files are removed. Re-run installer from scratch — it should complete again with fresh secrets.

- [ ] **Step 11: Record results**

Write a short "Verification Log" entry at the bottom of this file with date, VM specs, test domain, cert issuer, and any issues encountered. Then stage it.

```bash
git add docs/superpowers/plans/2026-04-21-web-installer.md
```

---

## Self-Review Notes (filled during plan authoring)

- **Spec coverage:** Every phase from spec §"Step 7 — Install Phases" maps to a task (directories, env, caddyfiles, cloudflare ini, ssh key, compose up, portainer, mysql, migrate, seed, admin user, SSL, caddy reload, reset). Tasks 18-19 cover the wizard UI. Task 20 covers the bootstrap. Task 22 covers end-to-end.
- **Placeholders:** No "TBD" / "implement later" / "similar to". Every step has a concrete command, file, or code block.
- **Type consistency:** `LogQueue.stream()` yields dicts everywhere. `InstallerError(phase, message, detail)` used uniformly. State `completed_phases` list matches the phase names used in `app.py:_run_install`.
- **Git policy:** Plan ends every task at `git add` — no `git commit`. User commits manually. This matches the project's CLAUDE.md rule.

## Verification Log

_Populated during Task 22._
