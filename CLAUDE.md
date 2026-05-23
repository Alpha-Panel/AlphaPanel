# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

AlphaPanel-Docker is a Docker Compose-based web hosting stack. It consists of:
- A **Laravel 13 control panel** (`alpha-panel/web/httpdocs/`) for managing hosted domains
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


# Workflow Orchestration

## 1. Plan Mode Default

- Enter plan mode for ANY non-trivial task (3+ steps or architectural decisions)
- If something goes sideways, STOP and re-plan immediately — don't keep pushing
- Use plan mode for verification steps, not just building
- Write detailed specs upfront to reduce ambiguity

## 2. Subagent Strategy

- Use subagents liberally to keep main context window clean
- Offload research, exploration, and parallel analysis to subagents
- For complex problems, throw more compute at it via subagents
- One task per subagent for focused execution

## 3. Self-Improvement Loop

- After ANY correction from the user: update `tasks/lessons.md` with the pattern
- Write rules for yourself that prevent the same mistake
- Ruthlessly iterate on these lessons until mistake rate drops
- Review lessons at session start for relevant project

## 4. Verification Before Done

- Never mark a task complete without proving it works
- Diff behavior between main and your changes when relevant
- Ask yourself: "Would a staff engineer approve this?"
- Run tests, check logs, demonstrate correctness

## 5. Demand Elegance (Balanced)

- For non-trivial changes: pause and ask "is there a more elegant way?"
- If a fix feels hacky: "Knowing everything I know now, implement the elegant solution"
- Skip this for simple, obvious fixes — don't over-engineer
- Challenge your own work before presenting it

## 6. Autonomous Bug Fixing

- When given a bug report: just fix it. Don't ask for hand-holding
- Point at logs, errors, failing tests — then resolve them
- Zero context switching required from the user
- Go fix failing CI tests without being told how

---

# Task Management

1. **Plan First**: Write plan to `tasks/todo.md` with checkable items
2. **Verify Plan**: Check in before starting implementation
3. **Track Progress**: Mark items complete as you go
4. **Explain Changes**: High-level summary at each step
5. **Document Results**: Add review section to `tasks/todo.md`
6. **Capture Lessons**: Update `tasks/lessons.md` after corrections

---

# Core Principles

- **Simplicity First**: Make every change as simple as possible. Impact minimal code.
- **No Laziness**: Find root causes. No temporary fixes. Senior developer standards.
- **Minimal Impact**: Changes should only touch what's necessary. Avoid introducing bugs.