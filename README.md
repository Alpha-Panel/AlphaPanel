# Docker Compose Web Stack

This repository provides a Docker Compose setup to spin up a FrankenPHP Caddy web server and MySQL database to host your websites. For sites using standard `.htaccess` rewrite rules, Apache and PHP-FPM are included and proxied through Caddy. Caddy’s built-in Cloudflare DNS support allows you to set your Cloudflare API key in the environment and obtain SSL certificates via DNS-01 challenge.

---

## Caddyfile

Create a Caddyfile at `frankenphp/sites-enabled/domain.com/Caddyfile`:

```caddyfile
(reverse-proxy-domain-com) {
    import common-headers
    import common-tls
    encode zstd br gzip
    root * /var/www/vhosts/domain.com/httpdocs/public
    php_server
    file_server
    log {
        output file /var/log/caddy/domain.com.log
        format console
    }
}

domain.com:80 {
    import common-headers
    root * /var/www/vhosts/domain.com/httpdocs/public
    redir https://domain.com{uri}
}

www.domain.com:80 {
    import common-headers
    root * /var/www/vhosts/domain.com/httpdocs/public
    redir https://domain.com{uri}
}

domain.com:443 {
    import reverse-proxy-domain-com
}

www.domain.com:443 {
    redir https://domain.com{uri}
}

*.domain.com:443 {
    import reverse-proxy-domain-com
}
```

## 🚀 Overview

- **FrankenPHP Caddy** as the public web server  
- **Apache + PHP-FPM** for sites relying on standard `.htaccess` rewrites (proxied through Caddy)  
- **MySQL**, **Meilisearch**, **Redis** for data storage and search  
- **FTP** for file transfers (manage via `ftp-config/users.env`)  
- **Web-based Code Editor** for in-browser development  
- **Vaultwarden** (Bitwarden-compatible) for self-hosted password management  

> **Note:**  
> Project-specific/local services should be added under `includeservices/` as local override files instead of changing the main `docker-compose.yaml`.
> `include:` support requires Docker Compose `v2.20.3+`.

---

## ⚙️ Configuration

1. **Clone this repository.**  
2. Rename .env.example to .env and set the following variables:
      ```bash
      mv .env.example .env
      ```

      ```dotenv
      CF_API_TOKEN=your_cloudflare_api_token
      # MySQL
      MYSQL_ROOT_PASSWORD=your_mysql_root_password

      # Cloudflare DNS (for Caddy DNS-01 challenge)
      CLOUDFLARE_API_TOKEN=your_cloudflare_api_token

      # Network IPs
      PRIVATE_NETWORK_IP=your_private_network_ip
      PUBLIC_NETWORK_IP=your_public_network_ip
   ```
3. In the `ftp-config/` directory, rename:

   ```bash
   mv users.env.example users.env
   ```
4. Edit ftp-config/users.env to define your FTP users.
Format:
  ```bash
# username|password|home_directory|uid
alice|secret123|/var/www/vhosts/alice|1001 \
bob|anotherPass|/var/www/vhosts/bob|1002 \
```
5. (Optional) Add local-only services in `includeservices/`:
   ```dotenv
   LOCAL_SERVICES_COMPOSE_FILE=./includeservices/local-services.yaml
   ```
   then follow `includeservices/README.md`.

## 🏃 Usage
Bring all services up in detached mode:

```bash
docker compose up -d
```

View logs in real time:

```bash
docker compose logs -f
```

Stop and remove containers:

```bash
docker compose down
```

## 🧩 Local-only Extra Services

Local service files can be added as:

```bash
cp external-services/mongodb.example.yaml external-services/mongodb.yaml
cp external-services/backlink_service.example.yaml external-services/backlink_service.yaml
```

Edit those copied files as needed.  
`includeservices/*.yaml` and `includeservices/*.yml` files are ignored by git, so they will not be pushed.

## 📦 Services Included

- **FrankenPHP Caddy Web Server**  
- **Apache** (behind Caddy for legacy `.htaccess` support)  
- **PHP-FPM**  
- **FTP** (configured via `ftp-config/users.env`)  
- **Redis**  
- **Memcached**  
- **MySQL**  
- **Meilisearch**  
- **Web-based Code Editor**  
- **Vaultwarden Password Manager**  
- **Portainer**  
- **Jenkins**  
- **N8N**  
- **Optional local services** from `includeservices/*.yaml`  


## ❓ Troubleshooting

- **Service fails to start**
  Check logs with:
  ```bash
  docker compose logs -f <service>
  ```
  and confirm local service files in `includeservices/` are valid.

- **FTP users not loading:**
  Ensure ftp-config/users.env exists (renamed from users.env.example) and has correct formatting.
- **Caddy proxy issues:**
  Verify your site definitions in caddy/Caddyfile and that Apache-backed sites are reachable on their internal ports.

## After stack is up

```bash
chmod -R u+rwX,g+rwX deploy_cache
chown -R 1000:1000 deploy_cache

chmod -R u+rwX,g+rwX n8n
chown -R 1000:1000 n8n

chmod -R u+rwX,g+rwX backup
chown -R 1000:1000 backup
```
