# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

AlphaPanel-Docker is a Docker Compose-based web hosting stack. It consists of:
- A **Laravel 12 control panel** (`alpha-panel/web/httpdocs/`) for managing hosted domains
- **FrankenPHP + Caddy** as the primary web server and reverse proxy
- **Apache + PHP-FPM** for legacy `.htaccess`-based sites (proxied through Caddy)
- Supporting services: MySQL, Redis, Meilisearch, N8N, Jenkins, Portainer, Vaultwarden, FTP

## Docker Stack Commands

```bash
docker compose up -d                  # Start all services
docker compose down                   # Stop and remove containers
docker compose logs -f <service>      # Follow logs for a service
docker compose build <service>        # Rebuild a specific service image
```

After first start, fix permissions on data directories:
```bash
chmod -R u+rwX,g+rwX deploy_cache n8n backup
chown -R 1000:1000 deploy_cache n8n backup
```

## Laravel Panel Commands

All commands run inside `alpha-panel/web/httpdocs/`:

```bash
composer run dev           # Start dev environment (server + queue + logs + Vite)
composer run setup         # Full initial setup (install, migrate, build)
php artisan test --compact # Run all tests
php artisan test --compact tests/Feature/SomeTest.php   # Single file
php artisan test --compact --filter=testName            # Specific test
vendor/bin/pint --dirty --format agent  # Format changed files
```

**Custom artisan commands:**
```bash
php artisan panel:apply              # Deploy domain configs to Caddy/Apache
php artisan panel:apply --dry-run    # Preview changes without applying
php artisan panel:render {fqdn}      # Render config for a specific domain
```

## Architecture

### Routing & Proxying

- **Caddy** (port 80/443) is the sole public-facing entry point
- Per-domain Caddyfiles live in `frankenphp/sites-enabled/{domain}/Caddyfile`
- Modern PHP sites use FrankenPHP's `php_server` directive directly
- Legacy `.htaccess` sites are proxied to **Apache** (internal port)
- AlphaPanel itself runs on a private `alpha_panel_web` FrankenPHP instance

### Network

All services share the `vhost_network` bridge. The Docker socket is exposed only via `docker-socket-proxy` (restricted API) to avoid direct socket access.

### Adding a New Domain

Create `frankenphp/sites-enabled/{domain}/Caddyfile` following the template in `README.md`. The `panel:apply` command generates these files from the database. Hosted site files live in `vhosts/{domain}/httpdocs/`.

### Local Extra Services

Add local-only services under `includeservices/` (files are git-ignored). Requires Docker Compose v2.20.3+ for `include:` support. Set `LOCAL_SERVICES_COMPOSE_FILE` in `.env`.

## Laravel Panel Architecture

The panel follows Laravel 12 conventions (see `alpha-panel/web/httpdocs/CLAUDE.md` for detailed PHP/Laravel rules loaded via Laravel Boost MCP):

- **Stack**: Laravel 12, Vue 3, Tailwind CSS 4, Inertia.js 2, Vite 7
- **Auth**: Fortify + WebAuthn (passwordless) + 2FA
- **Real-time**: Laravel Reverb (WebSockets)
- **Search**: Meilisearch via Laravel Scout
- **Middleware/routing**: configured in `bootstrap/app.php` (Laravel 11+ structure)
- **Config**: always use `config('key')`, never `env()` outside config files
- **Tests**: PHPUnit 11 feature tests; run after every change

### Key Services

- `DomainConfigService` — generates Caddyfile/Apache vhost configs
- `DockerControlService` — interacts with Docker API (via socket proxy)
- `ApplyChangesService` — atomically deploys configs with rollback on failure
- `CloudflareService` — manages DNS via Cloudflare API

## Environment Setup

Copy `.env.example` to `.env` and set at minimum:
- `CF_API_TOKEN` / `CLOUDFLARE_API_TOKEN` — Cloudflare DNS-01 challenge
- `MYSQL_ROOT_PASSWORD`
- `PRIVATE_NETWORK_IP` / `PUBLIC_NETWORK_IP`

FTP users: copy `ftp-config/users.env.example` to `ftp-config/users.env`.
