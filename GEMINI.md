# Project Overview

This project provides a Docker Compose setup designed for a comprehensive web development environment. It utilizes **FrankenPHP Caddy** as the primary public web server, with **Apache + PHP-FPM** available for sites that require standard `.htaccess` rewrite rules (proxied through Caddy).

The stack includes a variety of services for data management and development:
*   **Databases:** MySQL, MongoDB
*   **Search Engine:** Meilisearch
*   **Caching/Data Structure Store:** Redis
*   **File Transfer:** FTP (configurable via `ftp-config/users.env`)
*   **Development Tools:** A web-based code editor
*   **Security:** Vaultwarden (Bitwarden-compatible) for self-hosted password management.

The setup is geared towards hosting websites, leveraging Caddy's built-in Cloudflare DNS support for automated SSL certificate acquisition via DNS-01 challenge.

# Building and Running

## Configuration Steps

1.  **Clone this repository.**
2.  Rename `.env.example` to `.env` and configure the following variables:
    ```bash
    mv .env.example .env
    ```
    Set values for:
    *   `CF_API_TOKEN` (Cloudflare API token for Caddy DNS-01 challenge)
    *   `MYSQL_ROOT_PASSWORD`
    *   `CLOUDFLARE_API_TOKEN` (duplicate of CF_API_TOKEN, ensure consistency)
    *   `PRIVATE_NETWORK_IP`
    *   `PUBLIC_NETWORK_IP`
3.  In the `ftp-config/` directory, rename `users.env.example` to `users.env`:
    ```bash
    mv ftp-config/users.env.example ftp-config/users.env
    ```
4.  Edit `ftp-config/users.env` to define your FTP users. The format is:
    ```
    # username|password|home_directory|uid
    alice|secret123|/var/www/vhosts/alice|1001 \
    bob|anotherPass|/var/www/vhosts/bob|1002 \
    ```
5.  **(Optional):** Open `docker-compose.yml` and remove any services you do not need to prevent Docker from attempting to start them.

## Usage Commands

*   **Bring all services up in detached mode:**
    ```bash
    docker compose up -d
    ```
*   **View logs in real time:**
    ```bash
    docker compose logs -f
    ```
*   **Stop and remove containers:**
    ```bash
    docker compose down
    ```

# Development Conventions

## Caddyfile Configuration

For each domain, a `Caddyfile` needs to be created in `frankenphp/sites-enabled/domain.com/Caddyfile`. A template is provided in `README.md`, which includes:
*   `common-headers` and `common-tls` imports.
*   `zstd`, `br`, `gzip` encoding.
*   Root directory definition.
*   `php_server` and `file_server` directives.
*   Structured logging.
*   HTTP to HTTPS redirection for `domain.com` and `www.domain.com`.
*   Wildcard subdomain support.

## Included Services

The `docker-compose.yaml` includes the following services, which can be selectively removed if not needed:
*   FrankenPHP Caddy Web Server
*   Apache (for `.htaccess` support)
*   PHP-FPM
*   FTP
*   Redis
*   MySQL
*   MongoDB
*   Meilisearch
*   Web-based Code Editor
*   Vaultwarden Password Manager

## Troubleshooting

*   **Service fails to start:** Check logs for the specific service using `docker-compose logs <service>`. Ensure unneeded services are removed and necessary configuration files are provided.
*   **FTP users not loading:** Verify `ftp-config/users.env` exists and is correctly formatted.
*   **Caddy proxy issues:** Confirm site definitions in `caddy/Caddyfile` and ensure Apache-backed sites are reachable on their internal ports.