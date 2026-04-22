# AlphaPanel-Docker Web Installer — Design Spec

**Date:** 2026-04-21
**Status:** Draft (pending review)
**Owner:** Niyazi

## Problem

Current `install.sh` is a ~720-line Bash script that mixes system bootstrap, interactive prompts, `.env` generation, `docker compose up`, Portainer token collection, and Laravel migrations. It is hard to maintain, hard to resume on failure, and requires the operator to manually click through the Portainer UI to produce an API token. It also does not issue a Let's Encrypt certificate for the panel domain, which breaks Reverb WebSocket (`wss://`) on first login.

## Goals

1. Replace shell prompts with a browser-based wizard that runs once per host and then shuts itself down.
2. Keep the bootstrap shell script minimal — only the steps required before Python can run.
3. Auto-generate every internal credential; ask the operator only for things the installer genuinely cannot derive (base domain, Cloudflare API token, admin email, admin account).
4. Fully automate Portainer admin creation and API token issuance — zero manual UI clicks.
5. Issue a Let's Encrypt wildcard certificate via Cloudflare DNS-01 during installation so the panel is reachable over valid HTTPS when the wizard finishes.
6. Be idempotent per step. If the wizard is re-run, completed steps are skipped. A "Reset" action exists for failed half-installs.

## Non-Goals

- Ongoing panel management — this installer runs once, then exits. Day-2 operations remain in the Laravel panel.
- Multi-host orchestration. Single-host installer only.
- Upgrades/migrations. A later release may add an `upgrade` mode; out of scope here.

## High-Level Flow

```
curl -fsSL https://.../install.sh | sudo bash
  │
  ├─ install.sh (bootstrap, ~50 lines)
  │     • root check
  │     • OS detect (ubuntu/debian/rhel/rocky/alma/fedora)
  │     • install: git, curl, python3, python3-venv, python3-pip
  │     • install Docker via get.docker.com if missing
  │     • clone repo to /opt/alphapanel-docker (or pull if exists)
  │     • create .installer-venv, pip install flask requests
  │     • exec .installer-venv/bin/python -m installer.app
  │
  ├─ Flask wizard on http://<server-ip>:5000
  │     • 8-step SPA-like wizard
  │     • SSE log stream during install phase
  │     • state file (.installer_state.json) for resume
  │
  └─ On completion
        • panel URL opened in new browser tab (window.open)
        • POST /shutdown → os._exit(0) after 2s grace period
```

## Wizard Steps

| # | Screen | Input | Action |
|---|--------|-------|--------|
| 1 | Welcome | — | Detect existing `.installer_state.json`. If present and step ≠ `done`, show "Resume" + "Reset" buttons. |
| 2 | System detection | Confirm | Detect OS, private IP (`ip route`), public IP (`ifconfig.me`). Read-only, operator clicks Next. |
| 3 | Domain config | `BASE_DOMAIN`, `PANEL_DOMAIN`, `PMA_DOMAIN`, `CODE_SERVER_DOMAIN`, `VAULTWARDEN_DOMAIN`, `N8N_DOMAIN`, `PORTAINER_DOMAIN`, `JENKINS_DOMAIN`, `JENKINS_ADMIN_IPS` (optional) | Validate FQDNs. Sub-domains pre-filled from `BASE_DOMAIN`. |
| 4 | Credentials | `CF_API_TOKEN`, `ADMIN_EMAIL`, `PORTAINER_ADMIN_USER` (default `admin`), `PORTAINER_ADMIN_PASSWORD` (min 12 chars, Portainer requirement) | Validate CF token via `GET https://api.cloudflare.com/client/v4/user/tokens/verify`. |
| 5 | Panel admin | `PANEL_ADMIN_NAME`, `PANEL_ADMIN_USERNAME`, `PANEL_ADMIN_EMAIL`, `PANEL_ADMIN_PASSWORD` | Standard form validation. |
| 6 | Summary | Confirm | Read-only table. "Start installation" button. |
| 7 | Progress | — | SSE stream. All steps below run sequentially. |
| 8 | Done | — | Show panel URL + all service URLs. JS: `window.open(panelUrl)` then `fetch('/shutdown', {method: 'POST'})`. |

### Step 7 — Install Phases (SSE log order)

1. **Generate internal secrets** — MySQL root, Meilisearch keys, PG password, N8N encryption key, PMA blowfish, Vaultwarden DB pw, Panel DB pw, FTP MySQL pw, CrowdSec keys, Update agent secret, Reverb keys, Laravel `APP_KEY`, code-server password.
2. **Write root `.env`** — merge form input + generated secrets.
3. **Write Laravel `.env`** — `alpha-panel/web/httpdocs/.env` from `.env.example` with `sed`-equivalent Python substitutions.
4. **Write `secrets/cloudflare.ini`** — `chmod 600`.
5. **Create data directories** — all the `mkdir -p` paths from current `install.sh` (lines 227-248).
6. **Write Jenkins + base-domain Caddyfiles** — from current `install.sh` templates (lines 273-351).
7. **Generate SSH key** — ed25519 in `alpha-panel/web/ssh-keys/`, add pub key to `/root/.ssh/authorized_keys`.
8. **`docker compose up -d --build`** — stream stdout/stderr to SSE via `subprocess.Popen` + pipe reader thread.
9. **Wait for Portainer** — poll `http://<private_ip>:9000/api/status` until 200 (max 180s).
10. **Portainer admin init + token** — `POST /api/users/admin/init` → `POST /api/auth` → `POST /api/users/1/tokens`. Persist token + endpoint ID to Laravel `.env`.
11. **Wait for MySQL** — `docker exec mysql mysqladmin ping` loop (max 180s).
12. **`php artisan migrate --force`**.
13. **`php artisan db:seed --class=PhpVersionSeeder --force`**.
14. **`php artisan app:add-admin-user`** with form values.
15. **SSL issuance (new)** — `php artisan panel:issue-installer-cert --base=<BASE_DOMAIN>`. Details below.
16. **`php artisan panel:apply`** — regenerate Caddyfiles with new cert paths.
17. **Caddy reload** — via Portainer exec API (Caddy admin API is localhost-only in the container).
18. **Final health check** — curl `https://<PANEL_DOMAIN>:8443/` — expect 200 with valid cert.

Each phase writes structured log lines to the SSE queue. On any phase failure, Flask stops, the state file records `{step: <phase>, error: <msg>}`, the wizard shows a "Retry from last step" and "Reset" button.

## SSL Issuance — New Artisan Command

**Signature:** `panel:issue-installer-cert {--base=} {--token-file=secrets/cloudflare.ini}`

**Location:** `alpha-panel/web/httpdocs/app/Console/Commands/IssueInstallerCertCommand.php`

**Behaviour:**

1. Read Cloudflare API token from `--token-file` (INI format, key `dns_cloudflare_api_token`).
2. Upsert `AcmeSetting` row: `provider=cloudflare`, `cloudflare_api_token=<token>`, `contact_email=<ADMIN_EMAIL>` (pulled from `config('app.admin_email')` or `.env`).
3. Upsert a `Domain` record for `BASE_DOMAIN` with SAN list: `BASE_DOMAIN`, `*.BASE_DOMAIN`.
4. Call `AcmeService::requestCertificateDnsCloudflare($domain, $progressCallback)`.
5. The progress callback writes `[acme] <message>` lines to stdout so Flask captures them in the SSE stream.
6. On success: save cert/key PEM via `SslCertificate` model (existing flow), mark active.
7. On failure: log error, exit with non-zero code. Flask falls back to `panel:ensure-default-cert` (self-signed) and warns the operator that LE failed — they can retry from the panel UI once logged in.

**Why wildcard:** all panel services (panel, pma, portainer, n8n, vaultwarden, code-server, jenkins) live under one base domain. One wildcard cert covers every service and every future tenant subdomain without re-issuance.

**Rate-limit caution:** LE production has 5 duplicate-cert issuances per week. If the wizard is run and reset repeatedly on the same domain, switch to staging via `--staging` flag on the command (not exposed in the wizard by default; only via CLI re-run).

## File Layout

```
/opt/alphapanel-docker/
├── install.sh                         # ~50 lines bootstrap only
├── installer/
│   ├── __init__.py
│   ├── app.py                         # Flask app + routes + SSE
│   ├── state.py                       # .installer_state.json read/write
│   ├── secrets_gen.py                 # gen_hex, gen_b64 (openssl-equivalent)
│   ├── steps/
│   │   ├── __init__.py
│   │   ├── system.py                  # OS + IP detection
│   │   ├── env_writer.py              # root .env + Laravel .env
│   │   ├── caddyfile.py               # Jenkins + base-domain Caddyfiles
│   │   ├── cloudflare.py              # secrets/cloudflare.ini + token verify
│   │   ├── ssh_key.py                 # ed25519 + authorized_keys
│   │   ├── compose.py                 # docker compose up + stdout pipe
│   │   ├── portainer.py               # wait + admin init + token + endpoint
│   │   ├── database.py                # migrate + seed + admin user
│   │   ├── ssl.py                     # calls panel:issue-installer-cert
│   │   ├── caddy_reload.py            # reload via Portainer exec
│   │   └── reset.py                   # docker compose down -v + clean
│   ├── templates/
│   │   └── wizard.html                # single page, steps toggled via JS
│   └── static/
│       ├── wizard.js                  # step state, fetch, EventSource
│       └── wizard.css                 # minimal styling (no framework)
├── .installer-venv/                   # git-ignored
└── .installer_state.json              # git-ignored
```

**Git ignore additions:** `.installer-venv/`, `.installer_state.json`.

## State File Schema

`.installer_state.json`:
```json
{
  "version": 1,
  "started_at": "2026-04-21T17:00:00Z",
  "updated_at": "2026-04-21T17:05:30Z",
  "form": {
    "base_domain": "example.com",
    "panel_domain": "server.example.com",
    "...": "..."
  },
  "generated_secrets": {
    "mysql_root_password": "...",
    "...": "..."
  },
  "completed_phases": ["secrets", "env", "cloudflare_ini", "directories", "caddyfiles", "ssh_key", "compose_up", "portainer", "mysql_wait", "migrate", "seed", "admin_user"],
  "current_phase": "ssl",
  "last_error": null
}
```

Generated secrets are persisted so a resume does not overwrite existing `.env` with different values.

## API Routes

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/` | Wizard HTML |
| GET | `/api/state` | Return current state file |
| POST | `/api/reset` | Run reset step, clear state |
| POST | `/api/detect` | Re-run system detection, return JSON |
| POST | `/api/verify-cf-token` | Hit Cloudflare API, return valid/invalid |
| POST | `/api/submit` | Accept form, write state, kick off install background thread |
| GET | `/api/progress` | SSE stream — `data: {"phase": "...", "line": "..."}\n\n` |
| POST | `/api/shutdown` | `os._exit(0)` after 2s |

## Error Handling

- Each phase in `steps/` raises `InstallerError(phase, message, detail)` on failure.
- `app.py` catches, writes `last_error` to state, emits `data: {"type": "error", "phase": ..., "message": ...}` to SSE, stops the install thread.
- Wizard UI (step 7) shows a red error panel with the failing phase, message, and a "Retry from last step" button that re-kicks `/api/submit` with `{mode: "resume"}`.
- A "Reset" button is always available on the error panel.

## Security Notes

- Flask binds to `0.0.0.0:5000` so the operator can reach it from their laptop. Installer runs ONCE and exits. No long-running exposure.
- Form values (CF token, admin passwords) are sent over plain HTTP on port 5000. Acceptable because:
  - Installer is invoked over a trusted connection (the operator's SSH session + localhost-to-laptop flow).
  - The alternative (self-signed HTTPS) triggers browser warnings with no real security benefit on first run.
  - Recommended: bind to SSH tunnel only (`ssh -L 5000:localhost:5000 server`) — documented in README, not enforced.
- CF token stored on disk only in `secrets/cloudflare.ini` (mode 0600) and `.env` (mode 0600 — new: installer sets this).
- State file contains generated passwords in plaintext. `.installer_state.json` is `chmod 600` and git-ignored. Deleted on successful completion.

## Shutdown Behaviour

On successful `done` screen:

1. Client JS: `window.open(panelUrl, '_blank')` — opens panel in new tab.
2. Client JS: `await fetch('/api/shutdown', {method: 'POST'})`.
3. Server: schedule `threading.Timer(2, lambda: os._exit(0))`. Gives the client time to receive 200.
4. State file is deleted.

Operator closes the installer tab manually (port 5000 is now dead).

## Open Questions / Deferred

- Multi-language wizard UI — deferred. English only for v1. Operator is expected to be the server admin, not an end user.
- Dark mode — skip for v1. Minimal CSS, system theme respected via `prefers-color-scheme`.
- Cert rotation on reset — if operator runs Reset + Reinstall on the same base domain within LE rate-limit window, re-issuance will 429. Command falls back to staging on repeated failure, with a visible warning.

## Testing Strategy

- Unit tests for `installer/steps/` pure functions (secrets generation, `.env` rendering, state serialisation) — `pytest` in a new `installer/tests/` dir.
- Integration test: run the full installer against a disposable VM via Vagrant or a GitHub Actions VM job. Single smoke test: fresh Ubuntu 22.04 → installer → panel responds 200 on its domain with a valid cert. Deferred to follow-up PR.
- PHP test for `IssueInstallerCertCommand` in `tests/Feature/Console/` — mock `AcmeService::requestCertificateDnsCloudflare` and assert settings/domain records are created correctly.

## Out of Scope for This Spec

- Refactoring the existing Laravel SSL flow. The new artisan command is a thin wrapper around existing `AcmeService` methods.
- Changing `docker-compose.yaml` structure. Installer only writes `.env` and invokes `docker compose up -d --build`.
- Replacing Caddy reload mechanism. Continues to use the existing Portainer exec path documented in `MEMORY.md`.
