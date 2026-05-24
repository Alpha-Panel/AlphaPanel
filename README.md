# AlphaPanel

[![License: AGPL v3](https://img.shields.io/badge/License-AGPL%20v3-blue.svg)](LICENSE)

> Copyright (C) 2026 Muhammed Niyazi Alpay (Cryptograph) — Licensed under [GNU AGPL v3](LICENSE).

AlphaPanel is a self-hosted web hosting control panel built on Docker Compose. It provides a modern, web-based interface for managing hosted domains, SSL certificates, DNS records, databases, FTP users, file systems, security rules, and server processes — without manually editing any configuration files.

It is designed as an open-source alternative to cPanel and Plesk, with a focus on modern PHP stacks, Docker-native infrastructure, and advanced security.

---

## Features at a Glance

- **Domain & vhost management** — create, edit, remove domains; automatic Caddyfile and Apache vhost generation
- **Automatic SSL** — Let's Encrypt certificates via Cloudflare DNS-01 challenge; wildcard and per-domain certs
- **Cloudflare integration** — DNS records, DNSSEC, firewall rules, cache purging, security level
- **Database management** — create MySQL databases and users, set passwords, per-domain isolation
- **FTP users** — per-domain VSFTPD accounts with FTPS support
- **File manager** — browser-based file editor with upload, download, rename, chmod, compress/decompress
- **Package manager** — run npm and Composer commands directly from the panel
- **PHP configuration** — per-domain PHP version (8.0–8.5) and custom php.ini settings
- **Supervisor / Queue management** — manage Laravel Queue Workers, Reverb, Pulse, and Horizon per domain
- **Real-time log viewer** — stream access logs per domain in the browser
- **WAF (Web Application Firewall)** — Coraza + OWASP CRS per domain; detection or blocking mode; custom rules; IP allowlist/blocklist
- **CrowdSec** — intrusion detection dashboard, alerts, and firewall bouncer integration
- **Terminal** — browser-based terminal with Docker exec access to containers; session logging
- **Audit log** — full action trail with IP address and timestamps
- **WebAuthn (passkeys)** — passwordless authentication with FIDO2 keys or biometrics
- **TOTP 2FA** — time-based one-time password with authenticator apps
- **PhpMyAdmin SSO** — single sign-on from the panel without re-entering credentials
- **Real-time notifications** — WebSocket-powered notification system via Laravel Reverb
- **PWA support** — installable as a progressive web app

---

## Stack

### Infrastructure

| Service | Role |
|---|---|
| **FrankenPHP + Caddy** | Public web server (ports 80/443), reverse proxy, PHP runtime |
| **Apache + PHP-FPM** | Legacy `.htaccess` sites (proxied through Caddy) |
| **MySQL 9.3** | Primary database |
| **Redis** | Cache, sessions, queues |
| **Memcached** | Additional caching layer |
| **PostgreSQL** | N8N automation database |
| **Meilisearch** | Full-text search (panel + hosted domains) |
| **Certbot** | Let's Encrypt certificate provisioning and renewal |
| **CrowdSec** | Intrusion detection and prevention |
| **Docker Socket Proxy** | Restricted Docker API access |
| **Portainer** | Docker container management UI |
| **PhpMyAdmin** | Database management (SSO integrated) |
| **Vaultwarden** | Self-hosted Bitwarden password manager |
| **N8N** | Workflow automation |
| **Jenkins** | CI/CD automation |
| **FTP (VSFTPD)** | File transfers with FTPS (port 21, passive 21000–21010) |
| **Code Server** | Browser-based code editor |

### Panel Application

| Layer | Technology |
|---|---|
| Backend | Laravel 12 |
| Frontend | Vue 3 + Inertia.js v2 + Tailwind CSS 4 |
| Build | Vite 7 |
| WebSockets | Laravel Reverb |
| Search | Laravel Scout + Meilisearch |
| Auth | Laravel Fortify + WebAuthn + TOTP |
| Queue | Laravel Queue (Redis driver) |
| Tests | PHPUnit 11 |

---

## Architecture

```
Internet → Caddy (80/443)
             ├── FrankenPHP sites   (php_server)
             ├── Apache proxy       (legacy .htaccess)
             └── AlphaPanel         (alpha_panel_web, internal)

AlphaPanel
  ├── Laravel 12 API / Inertia.js SPA
  ├── Generates Caddyfiles + Apache vhosts via panel:apply
  ├── Manages MySQL databases, FTP users, SSL certs
  ├── Controls Docker via docker-socket-proxy
  └── WebSocket terminal → Portainer exec API
```

- Caddy is the sole public-facing entry point; all per-domain configs live in `frankenphp/sites-enabled/{domain}/Caddyfile`
- The `panel:apply` artisan command generates all config files from the database and reloads Caddy — no manual file editing needed
- Domain provisioning is two-phase: HTTP-only first (pre-cert), then HTTPS after the certificate is issued
- All infra changes (provision, delete, rename) run as background jobs with rollback on failure

---

## Quick Start

### Requirements

- Docker with Compose v2.20.3+
- A Cloudflare account with API token (for DNS-01 challenge)
- A public server with ports 80/443 open

### 1. Clone

```bash
git clone https://github.com/Cryptograph/AlphaPanel-Docker.git
cd AlphaPanel-Docker
```

### 2. Configure environment

```bash
cp .env.example .env
```

Edit `.env` and set at minimum:

```dotenv
# Domains
BASE_DOMAIN=example.com
PANEL_DOMAIN=panel.example.com
PMA_DOMAIN=pma.example.com
VAULTWARDEN_DOMAIN=vault.example.com
N8N_DOMAIN=n8n.example.com
PORTAINER_DOMAIN=portainer.example.com

# Cloudflare
CF_API_TOKEN=your_cloudflare_api_token
CLOUDFLARE_API_TOKEN=your_cloudflare_api_token

# MySQL
MYSQL_ROOT_PASSWORD=your_secure_password

# Network
PRIVATE_NETWORK_IP=10.0.0.1
PUBLIC_NETWORK_IP=1.2.3.4
```

### 3. Configure FTP users

```bash
cp ftp-config/users.env.example ftp-config/users.env
```

Edit `ftp-config/users.env`:

```
# username|password|home_directory|uid
alice|secret123|/var/www/vhosts/alice|1001 \
bob|anotherPass|/var/www/vhosts/bob|1002 \
```

### 4. Start services

```bash
docker compose up -d --build
```

### 5. Fix permissions

```bash
chmod -R u+rwX,g+rwX deploy_cache n8n backup
chown -R 1000:1000 deploy_cache n8n backup
```

### 6. Create admin user

```bash
docker compose exec alpha_panel_web php artisan add-admin-user
```

---

## Security

### Coraza WAF (OWASP CRS)

Coraza runs inside FrankenPHP and applies OWASP Core Rule Set to all incoming requests. Per-domain configuration is managed from the panel:

- Toggle detection-only or blocking mode
- Add custom rules
- Allowlist/blocklist IP addresses
- Disable specific rule IDs

Audit logs are written to `./frankenphp/coraza-logs/audit.log`.

### CrowdSec

CrowdSec reads Coraza audit logs and applies behavior-based blocking. Setup:

1. Add `CROWDSEC_FIREWALL_BOUNCER_KEY` to `.env`
2. Start services:
   ```bash
   docker compose up -d frankenphp crowdsec
   ```
3. Install the host-level nftables bouncer:
   ```bash
   ./scripts/crowdsec/install-host-nft-bouncer.sh
   ```
4. Verify:
   ```bash
   docker compose exec crowdsec cscli collections list
   docker compose exec crowdsec cscli metrics
   ```

### Authentication

- **Passwordless (WebAuthn/Passkeys)** — FIDO2 security keys and biometric authenticators
- **TOTP 2FA** — standard authenticator app support
- **Password + 2FA** — traditional login with optional second factor
- **Audit logging** — every action logged with user, IP, timestamp

---

## Local-only Extra Services

Add local services under `includeservices/` (git-ignored). Requires Docker Compose v2.20.3+.

```dotenv
LOCAL_SERVICES_COMPOSE_FILE=./includeservices/local-services.yaml
```

See `includeservices/README.md` for usage.

---

## Mail Server (Mailu + Zimbra Bridge)

Mail hosting ships as an opt-in external-service. Each panel domain can pick its
mail backend independently: `disabled`, `local` (Mailu), `remote` (custom MX),
or `zimbra` (bridge to an existing Zimbra server).

### Enabling local Mailu

1. Set in root `.env`:
   ```dotenv
   MAIL_ENABLED=true
   MAIL_DOMAIN=mail.example.com
   MAIL_HOSTNAME=mail.example.com
   MAIL_DATA_PATH=./mail-data
   MAIL_ADMIN_PASSWORD=<strong-password>
   MAIL_SECRET_KEY=<16-char-secret>
   ```
   > `MAIL_DATA_PATH` is a **host** path resolved relative to the compose
   > project root. Absolute paths (`/var/lib/mailu`, `/mnt/mail`, …) also work
   > if you need to mount a dedicated disk.
2. `mkdir -p ./mail-data && chmod 750 ./mail-data`
3. `cp external-services/mailu.example.yaml external-services/mailu.yaml`
4. Add `- ./mailu.yaml` to `external-services/local-services.yaml`
5. `docker compose up -d`
6. `docker compose exec alpha_panel_web php artisan mail:bootstrap` — caches the Mailu admin API token

The panel exposes webmail at `https://${MAIL_DOMAIN}:8443/` and the Mailu admin
UI at `/admin`. Caddy uses the wildcard `${BASE_DOMAIN}` certificate; rDNS for
the outbound IP must point to `${MAIL_HOSTNAME}` (set this with your hosting
provider — it cannot be automated).

### Bridging an existing Zimbra server

You do **not** need MAIL_ENABLED for this; the bridge runs panel-side only.

1. Log in to the panel as admin.
2. Go to **Mail → Settings → Zimbra** and fill in admin SOAP URL, admin user,
   admin password, and the MX target host.
3. Click **Test connection** to verify SOAP auth works.
4. Save. On every domain create/edit form a new **Zimbra** option appears under
   *Mail Hosting*. Choosing it links the panel domain to Zimbra **idempotently** —
   if the domain already exists in Zimbra the call is a no-op, no error is raised.

The panel manages mailboxes, passwords, aliases, and forwarding via Zimbra SOAP
Admin API; you never have to log into the Zimbra admin UI for routine ops.

### Per-domain mail hosting

| Mode | Behaviour |
|------|-----------|
| `disabled` | No MX or mailboxes published. Domain has no mail. |
| `local` | Mailu hosts mail; panel publishes MX + SPF + DKIM + DMARC. |
| `remote` | Panel publishes the custom MX you provide. No mailbox management. |
| `zimbra` | Panel writes MX → your Zimbra server; mailboxes managed via SOAP API. |

### API

Every mail-related panel action is mirrored in `/v1/mail/...` and
`/v1/domains/{domain}/mail/...` — see `API-DOCUMENTATION.md`. Token abilities:
`mail:read` and `mail:write`.

### Installer

The Flask installer wizard collects mail + Zimbra settings in optional pages.
Tests: `installer/tests/test_mail.py`, `installer/tests/test_zimbra.py`.

---

## Panel Commands

Run inside `alpha-panel/web/httpdocs/`:

```bash
# Development
composer run dev           # Start dev server + queue + Vite

# Deploy domain configs
php artisan panel:apply              # Generate and deploy all domain configs
php artisan panel:apply --dry-run    # Preview without applying
php artisan panel:render {fqdn}      # Render config for one domain

# Admin
php artisan add-admin-user           # Create an admin account

# Long-running workers (managed automatically by supervisord inside the
# alpha_panel_web container — entries live under alpha-panel/supervisor/*.conf
# and are mounted read-only into /etc/supervisor/conf.d):
#   - terminal:serve                 (terminal.conf)
#   - queue:work                     (AlphaPanel-queue.conf)
#   - reverb:start                   (AlphaPanel-reverb.conf)
#   - schedule:work                  (AlphaPanel-schedule.conf)
#   - system:broadcast-server-stats  (AlphaPanel-server-stats.conf)
# Run manually only for debugging:
php artisan terminal:serve --port=2999
php artisan system:broadcast-server-stats --interval=15

# Tests
php artisan test --compact
php artisan test --compact --filter=testName
```

---

## Troubleshooting

**Service fails to start**
```bash
docker compose logs -f <service>
```

**Caddy config error after domain change**
```bash
docker compose exec frankenphp caddy validate --config /etc/caddy/Caddyfile
```

**FTP users not loading**
Ensure `ftp-config/users.env` exists and uses the correct format.

**Certificate not issuing**
Verify `CF_API_TOKEN` is set and the token has `Zone:DNS:Edit` permissions.

**Panel not accessible**
Check `alpha_panel_web` logs:
```bash
docker compose logs -f alpha_panel_web
```

---

## Security Notes

- **`php-code-server` container runs as `root`** to allow the in-browser code editor full filesystem access across hosted vhosts. This is a development/admin convenience and a deliberate trade-off — keep it gated behind the panel auth layer (`code-server.${BASE_DOMAIN}`), do not expose its port publicly, and consider disabling the service in environments where untrusted operators have panel access.
- **Terminal access** (`/terminal/*` routes) requires `panel.terminal.access` permission and is admin-only for host containers / SSH bridges. Domain terminals require domain ownership.
- **Trusted proxies**: The panel defaults to trusting Docker bridge CIDRs (`10.0.0.0/8,172.16.0.0/12,192.168.0.0/16`). If you front the panel with an additional reverse proxy or a CDN, override `TRUSTED_PROXIES` in `.env` to match exactly the upstream IPs — overly permissive trust enables `X-Forwarded-For` spoofing.

---

## License

This project is licensed under the **GNU Affero General Public License v3.0**.
See the [LICENSE](LICENSE) file for full terms.

Copyright (C) 2026 Muhammed Niyazi Alpay (Cryptograph)
