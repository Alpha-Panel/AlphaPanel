# AlphaPanel Next

AlphaPanel Next is a Laravel 12 control panel for managing Docker-hosted domains and generating per-domain runtime configs for:

- `FrankenPHP + Caddy` (modern Laravel sites)
- `Apache + PHP-FPM` behind Caddy reverse proxy (legacy sites)

It provides:

- Authenticated web UI aligned with the legacy panel theme
- JSON API endpoints for domain CRUD/apply actions
- Idempotent config rendering + safe atomic file writes
- Apply runs with status tracking, dry-run previews, and rollback on failure
- Audit logs for domain and apply actions
- Real-time UI updates via Laravel Reverb

## Core Domain Model

`domains` stores all runtime config source-of-truth records.

Fields implemented:

- `fqdn` (unique)
- `type` (`legacy|modern`)
- `root_path`
- `enable_www_redirect`
- `additional_hostnames` (JSON)
- `enable_tls`
- `tls_mode` (`certbot_paths`)
- `enable_worker`
- `worker_num`
- `notes`
- `status` (`active|disabled`)
- `created_by`, `updated_by`
- timestamps

## Services and Architecture

Implemented services:

- `app/Services/DomainConfigService.php` (config generation and file writing)
- `app/Services/DockerControlService.php`
- `app/Services/ApplyChangesService.php`

Behavior:

- Renders Caddy/Apache/FPM config per active domain
- Writes configs atomically (`tmp` file + rename)
- Enforces safe relative writes under configured base paths
- Builds dry-run diff previews
- Reloads Caddy (admin API first, Docker exec fallback)
- Reloads Apache/PHP-FPM through Docker API (socket-proxy host)
- Rolls back written files if reload fails

## Commands

- `php artisan panel:apply`
- `php artisan panel:apply --dry-run`
- `php artisan panel:render {fqdn}`

## API Endpoints

Under `api/v1` (session-authenticated; write actions admin-only):

- `GET /api/v1/domains`
- `GET /api/v1/domains/{domain}`
- `POST /api/v1/domains`
- `PUT|PATCH /api/v1/domains/{domain}`
- `DELETE /api/v1/domains/{domain}`
- `POST /api/v1/apply/preview`
- `POST /api/v1/apply`

## Web Pages

- Dashboard (`/`)
- Domains list (`/domains`)
- Domain create/edit/show
- Apply page + dry-run preview (`/apply`)

## Database Migrations (Fresh Build)

Migrations are built for a fresh first run:

- `database/migrations/0001_01_01_000000_create_users_table.php`
- `database/migrations/0001_01_01_000001_create_cache_table.php`
- `database/migrations/0001_01_01_000002_create_jobs_table.php`
- `database/migrations/2026_02_14_130720_create_php_versions_table.php`
- `database/migrations/2026_02_14_130728_create_domains_table.php`
- `database/migrations/2026_02_14_130736_create_apply_runs_table.php`
- `database/migrations/2026_02_14_130745_create_audit_logs_table.php`
- `database/migrations/2026_02_14_134903_add_created_by_to_domains_table.php`

## Theme Porting (Mandatory Reuse)

Legacy source analyzed and ported from `eski/` (`OLD_PANEL_PATH`) into current project (`NEW_PANEL_PATH`).

### Copied folders/files

- `eski/resources/views/layouts/Cryptograph/*` -> `resources/views/layouts/Cryptograph/*`
- `eski/resources/views/auth/login.blade.php` -> `resources/views/auth/login.blade.php`
- `eski/public/themes/*` -> `public/themes/*`
- `eski/public/css/AlphaPanel.css` -> `public/css/AlphaPanel.css`
- `eski/public/js/AlphaPanel.js` -> `public/js/AlphaPanel.js`
- `eski/public/js/webauthn.js` -> `public/js/webauthn.js`
- `eski/public/img/*` -> `public/img/*`
- `eski/public/fontawesome/*` -> `public/fontawesome/*`
- `eski/public/font-mfizz/*` -> `public/font-mfizz/*`
- `eski/public/OneSignalSDKWorker.js` -> `public/OneSignalSDKWorker.js`
- `eski/public/OneSignalSDKWorker.min.js` -> `public/OneSignalSDKWorker.min.js`
- `eski/public/favicon.ico` -> `public/favicon.ico`

### Theme compatibility wiring

- `config/theme.php` added (`THEME_PATH`, default `Cryptograph`)
- New pages extend `layouts.'.config('theme.theme').'.app` (legacy shell)
- Auth page uses legacy `Cryptograph` auth layout
- Legacy menu/DOM/CSS/JS structure preserved

### Asset build plan (old vs new)

Old project build tooling inspected:

- `eski/package.json`
- `eski/vite.config.js`
- Inputs were `resources/sass/app.scss`, `resources/js/app.js`, `resources/js/index.js`

Current implementation uses already-built legacy static assets under `public/` to preserve exact runtime theme behavior.

If you need to rebuild assets in old style:

1. Install old frontend deps (matching legacy lockfile) in `OLD_PANEL_PATH`.
2. Run `npm run dev` for development or `npm run build` for production in `OLD_PANEL_PATH`.
3. Copy generated assets into this project’s `public/themes`, `public/css`, `public/js`, `public/img`, `public/font*` paths.

## Configuration

Main settings are in `config/panel.php`:

- Config output paths (`/etc/frankenphp/sites-enabled`, `/etc/apache2/sites-enabled`, `/etc/php/8.5/fpm/pool.d`)
- Caddy main config path (`/etc/frankenphp/Caddyfile`)
- Docker API host (`DOCKER_HOST`)
- Container names (`frankenphp`, `php-code-server`)

Optional legacy FPM user strategy:

- `PANEL_LEGACY_DERIVE_UNIX_USER`
- `PANEL_LEGACY_DEFAULT_UNIX_USER`
- `PANEL_LEGACY_DEFAULT_UNIX_GROUP`

Admin seed defaults (first seed only):

- `PANEL_ADMIN_NAME`
- `PANEL_ADMIN_USERNAME`
- `PANEL_ADMIN_EMAIL`
- `PANEL_ADMIN_PASSWORD`

## Local Run

1. Install dependencies:
   - `composer install`
2. Configure `.env`
3. Run migrations:
   - `php artisan migrate --force`
4. Seed first admin user:
   - `php artisan db:seed --force`

## Tests

Added tests:

- `tests/Unit/DomainConfigServiceTest.php`
- `tests/Feature/Feature/DomainCrudTest.php`

Run:

- `php artisan test --compact tests/Unit/DomainConfigServiceTest.php`
- `php artisan test --compact tests/Feature/Feature/DomainCrudTest.php`
- Note: You may see deprecation warnings about "Metadata found in doc-comment" for PHPUnit 12. These indicate that test annotations will be replaced by PHP attributes in future versions.

## Formatting

Run:

- `vendor/bin/pint --dirty --format agent`