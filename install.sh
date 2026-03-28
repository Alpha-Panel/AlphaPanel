#!/usr/bin/env bash
# AlphaPanel-Docker Installer
# Supports: Ubuntu 20.04+, Debian 11+, CentOS/RHEL 8+, Rocky/AlmaLinux 8+
set -euo pipefail

REPO_URL="git@github.com:Alpha-Panel/AlphaPanel.git"
INSTALL_DIR="/opt/alphapanel-docker"

# ─── Colors ───────────────────────────────────────────────────
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; BOLD='\033[1m'; NC='\033[0m'

say()  { echo -e "${CYAN}${BOLD}=>${NC} $*"; }
ok()   { echo -e "  ${GREEN}✓${NC} $*"; }
warn() { echo -e "  ${YELLOW}⚠${NC}  $*"; }
die()  { echo -e "${RED}✗ ERROR:${NC} $*" >&2; exit 1; }

# Prompt with optional default
ask() {
    local _var="$1" _prompt="$2" _default="${3:-}" _val
    if [ -n "$_default" ]; then
        read -rp "  $_prompt [$_default]: " _val
        eval "$_var='${_val:-$_default}'"
    else
        read -rp "  $_prompt: " _val
        while [ -z "$_val" ]; do
            echo "  This field is required."
            read -rp "  $_prompt: " _val
        done
        eval "$_var='$_val'"
    fi
}

# Prompt that accepts blank input (truly optional)
ask_optional() {
    local _var="$1" _prompt="$2" _val
    read -rp "  $_prompt (press Enter to skip): " _val
    eval "$_var='$_val'"
}

# Silent prompt for secrets (blank → auto-generate)
ask_secret() {
    local _var="$1" _prompt="$2" _val
    read -rsp "  $_prompt (leave blank to auto-generate): " _val
    echo
    eval "$_var='$_val'"
}

# Silent prompt for secrets (required — must not be blank)
ask_secret_required() {
    local _var="$1" _prompt="$2" _val
    while true; do
        read -rsp "  $_prompt: " _val
        echo
        [ -n "$_val" ] && break
        echo "  This field is required."
    done
    printf -v "$_var" '%s' "$_val"
}

gen_secret() { openssl rand -hex "${1:-32}"; }
gen_b64()    { openssl rand -base64 32; }

# Portable sed -i
sed_i() {
    if sed --version 2>/dev/null | grep -q GNU; then
        sed -i "$@"
    else
        sed -i '' "$@"
    fi
}

# ─── Root check ───────────────────────────────────────────────
[ "$(id -u)" -eq 0 ] || die "Please run this script as root: sudo bash install.sh"

# ─── Banner ───────────────────────────────────────────────────
clear
echo ""
echo -e "${BOLD}${CYAN}╔══════════════════════════════════════════════════╗${NC}"
echo -e "${BOLD}${CYAN}║         AlphaPanel-Docker  Installer             ║${NC}"
echo -e "${BOLD}${CYAN}╚══════════════════════════════════════════════════╝${NC}"
echo ""

# ─── OS Detection ─────────────────────────────────────────────
if [ -f /etc/os-release ]; then
    # shellcheck source=/dev/null
    . /etc/os-release
    OS_ID="${ID:-unknown}"
    OS_PRETTY="${PRETTY_NAME:-$OS_ID}"
else
    die "Cannot detect OS. /etc/os-release not found."
fi
say "Detected OS: $OS_PRETTY"

# ─── Install dependencies ─────────────────────────────────────
install_pkg() {
    case "$OS_ID" in
        ubuntu|debian)
            apt-get install -y "$@" >/dev/null ;;
        centos|rhel|rocky|almalinux|fedora)
            dnf install -y "$@" >/dev/null ;;
        *)
            die "Unsupported OS: $OS_ID. Please install the following manually: $*" ;;
    esac
}

# curl
if ! command -v curl &>/dev/null; then
    say "Installing curl..."
    install_pkg curl
    ok "curl installed."
fi

# git
if ! command -v git &>/dev/null; then
    say "Installing git..."
    install_pkg git
    ok "git installed."
fi

# ─── Docker ───────────────────────────────────────────────────
if command -v docker &>/dev/null; then
    ok "Docker already installed: $(docker --version)"
else
    say "Installing Docker..."
    curl -fsSL https://get.docker.com | sh
    systemctl enable --now docker
    ok "Docker installed."
fi

# Docker Compose plugin check (requires v2.20.3+ for include: support)
if ! docker compose version &>/dev/null 2>&1; then
    die "Docker Compose plugin not found. Please install it: https://docs.docker.com/compose/install/"
fi
ok "Docker Compose: $(docker compose version --short)"

# ─── Clone / update repo ──────────────────────────────────────
say "Setting up AlphaPanel-Docker..."
if [ -d "$INSTALL_DIR/.git" ]; then
    warn "Directory $INSTALL_DIR already exists — pulling latest..."
    git -C "$INSTALL_DIR" pull --ff-only
else
    git clone "$REPO_URL" "$INSTALL_DIR"
fi
cd "$INSTALL_DIR"
ok "Repository ready at $INSTALL_DIR"

# ─── IP Detection ─────────────────────────────────────────────
say "Detecting network addresses..."
DETECTED_PRIVATE=$(ip route get 1.1.1.1 2>/dev/null \
    | awk '/src/ {for(i=1;i<=NF;i++) if($i=="src") print $(i+1); exit}' \
    || hostname -I 2>/dev/null | awk '{print $1}' \
    || echo "127.0.0.1")
DETECTED_PUBLIC=$(curl -4 -fsSL --max-time 8 https://ifconfig.me 2>/dev/null \
    || curl -4 -fsSL --max-time 8 https://api.ipify.org 2>/dev/null \
    || echo "$DETECTED_PRIVATE")
ok "Private IP: $DETECTED_PRIVATE"
ok "Public  IP: $DETECTED_PUBLIC"

# ─── Domain Configuration ─────────────────────────────────────
echo ""
echo -e "${BOLD}─── Domain Configuration ─────────────────────────────────${NC}"
echo "  All panel services will be served under your base domain."
echo "  Example: base domain = example.com  →  server.example.com"
echo ""

ask BASE_DOMAIN        "Base domain (e.g. example.com)"
ask PANEL_DOMAIN       "Panel domain"              "server.${BASE_DOMAIN}"
ask PMA_DOMAIN         "phpMyAdmin domain"         "pma.${BASE_DOMAIN}"
ask CODE_SERVER_DOMAIN "File manager domain"       "file.${BASE_DOMAIN}"
ask VAULTWARDEN_DOMAIN "Password manager domain"   "password.${BASE_DOMAIN}"
ask N8N_DOMAIN         "N8N automation domain"     "n8n.${BASE_DOMAIN}"
ask PORTAINER_DOMAIN   "Portainer domain"          "portainer.${BASE_DOMAIN}"
ask JENKINS_DOMAIN     "Jenkins CI domain"         "jenkins.${BASE_DOMAIN}"
ask_optional JENKINS_ADMIN_IPS  "Jenkins admin IPs (comma-separated CIDRs, blank=open)"

# ─── Credentials ──────────────────────────────────────────────
echo ""
echo -e "${BOLD}─── Credentials ──────────────────────────────────────────${NC}"

ask        ADMIN_EMAIL          "Admin email (used for Let's Encrypt)"
ask_secret CF_API_TOKEN         "Cloudflare API Token"
[ -z "${CF_API_TOKEN:-}" ] && die "Cloudflare API Token is required for DNS-01 SSL issuance."

ask_secret MYSQL_ROOT_PASSWORD  "MySQL root password"
[ -z "${MYSQL_ROOT_PASSWORD:-}" ] && MYSQL_ROOT_PASSWORD=$(gen_secret 16)

ask_secret CODE_SERVER_PASSWORD "File manager (code-server) password"
[ -z "${CODE_SERVER_PASSWORD:-}" ] && CODE_SERVER_PASSWORD=$(gen_secret 12)

# ─── Panel Admin Account ──────────────────────────────────
echo ""
echo -e "${BOLD}─── Panel Admin Account ──────────────────────────────────${NC}"
echo "  This account will be used to log in to AlphaPanel."
echo ""

ask PANEL_ADMIN_NAME     "Admin display name"  "Admin User"
ask PANEL_ADMIN_USERNAME "Admin username"       "admin"
ask PANEL_ADMIN_EMAIL    "Admin email"
ask_secret_required PANEL_ADMIN_PASSWORD "Admin password"

# ─── Auto-generate internal credentials ───────────────────────
echo ""
say "Generating secure internal credentials..."

MEILISEARCH_MASTER_KEY=$(gen_secret)
ALPHA_PANEL_MEILISEARCH_MASTER_KEY=$(gen_secret 20)
POSTGRESQL_USER=admin
POSTGRESQL_PASSWORD=$(gen_secret 16)
N8N_ENCRYPTION_KEY=$(gen_secret)
PMA_BLOWFISH_SECRET=$(gen_secret 16)
VAULTWARDEN_DB_PASSWORD=$(gen_secret 16)
PANEL_DB_NAME=AlphaPanel
PANEL_DB_USER=alphapanel
PANEL_DB_PASS=$(gen_secret 16)
CROWDSEC_FIREWALL_BOUNCER_KEY=$(gen_secret)
CROWDSEC_DASHBOARD_API_KEY=$(gen_secret)
UPDATE_AGENT_SECRET=$(gen_secret)
REVERB_APP_ID=$(gen_secret 4)
REVERB_APP_KEY=$(gen_secret 16)
REVERB_APP_SECRET=$(gen_secret)
APP_KEY="base64:$(gen_b64)"

ok "Credentials generated."

# ─── Create required directories ──────────────────────────────
say "Creating required directories..."
mkdir -p \
    secrets \
    letsencrypt \
    deploy_cache \
    backup \
    portainer \
    vaultwarden/data \
    mysql/data \
    postgres \
    redis \
    meilisearch/data meilisearch/tmp \
    alpha-panel/meilisearch/data alpha-panel/meilisearch/tmp \
    alpha-panel/web/logs alpha-panel/web/caddy_data alpha-panel/web/ssl \
    frankenphp/caddy_data frankenphp/logs frankenphp/waf/generated/domains \
    "frankenphp/sites-enabled/${BASE_DOMAIN}" \
    n8n/data n8n/files \
    jenkins/data \
    code-server/data \
    includeservices
ok "Directories created."

if [ ! -f frankenphp/waf/generated/global.conf ]; then
    cat > frankenphp/waf/generated/global.conf <<EOF
# Auto-generated by AlphaPanel.
# (no global IP rules)
EOF
fi

if [ ! -f frankenphp/waf/generated/domains/000-default.conf ]; then
    cat > frankenphp/waf/generated/domains/000-default.conf <<EOF
# Auto-generated by AlphaPanel.
# (no domain-specific rules)
EOF
fi

# ─── secrets/cloudflare.ini ───────────────────────────────────
say "Writing Cloudflare credentials..."
cat > secrets/cloudflare.ini <<EOF
dns_cloudflare_api_token = ${CF_API_TOKEN}
EOF
chmod 600 secrets/cloudflare.ini
ok "secrets/cloudflare.ini created."

# ─── Base domain Caddyfile (triggers certbot wildcard issuance) ─
CADDY_BASE="frankenphp/sites-enabled/${BASE_DOMAIN}/Caddyfile"
if [ ! -f "$CADDY_BASE" ]; then
    cat > "$CADDY_BASE" <<EOF
# Certbot will issue a wildcard cert for ${BASE_DOMAIN} and *.${BASE_DOMAIN}
# This file must exist so the certbot service picks up this domain.
# import common-tls
EOF
    ok "Base domain Caddyfile created: $CADDY_BASE"
fi

# ─── Jenkins Caddyfile ───────────────────────────────────────
say "Generating Jenkins Caddyfile..."
JENKINS_CADDY="frankenphp/sites-enabled/${JENKINS_DOMAIN}/Caddyfile"
mkdir -p "$(dirname "$JENKINS_CADDY")"

if [ -n "${JENKINS_ADMIN_IPS:-}" ]; then
    # Build space-separated CIDR list from comma-separated input
    _ips=""
    IFS=',' read -ra _arr <<< "$JENKINS_ADMIN_IPS"
    for _ip in "${_arr[@]}"; do
        _ip="$(echo "$_ip" | xargs)"
        [ -z "$_ip" ] && continue
        [[ "$_ip" == */* ]] || _ip="${_ip}/32"
        _ips="${_ips} ${_ip}"
    done

    cat > "$JENKINS_CADDY" <<EOCADDY
# Auto-generated by AlphaPanel installer — DO NOT DELETE
# This file is NOT managed by panel:apply
${JENKINS_DOMAIN}:443 {
    tls /etc/letsencrypt/live/${BASE_DOMAIN}/fullchain.pem /etc/letsencrypt/live/${BASE_DOMAIN}/privkey.pem

    handle /__whoami {
        respond "remote={http.request.remote.host}\nxff={http.request.header.X-Forwarded-For}\ncf={http.request.header.CF-Connecting-IP}\nreal={http.request.header.X-Real-IP}\n"
    }

    @webhook {
        path /github-webhook*
        import /etc/frankenphp/_github_hooks_allowlist.caddy
    }
    handle @webhook {
        reverse_proxy jenkins:8080
    }

    @admin client_ip${_ips}
    handle @admin {
        reverse_proxy jenkins:8080
    }

    handle {
        respond 403
    }
}
EOCADDY
else
    cat > "$JENKINS_CADDY" <<EOCADDY
# Auto-generated by AlphaPanel installer — DO NOT DELETE
# This file is NOT managed by panel:apply
${JENKINS_DOMAIN}:443 {
    tls /etc/letsencrypt/live/${BASE_DOMAIN}/fullchain.pem /etc/letsencrypt/live/${BASE_DOMAIN}/privkey.pem

    handle /__whoami {
        respond "remote={http.request.remote.host}\nxff={http.request.header.X-Forwarded-For}\ncf={http.request.header.CF-Connecting-IP}\nreal={http.request.header.X-Real-IP}\n"
    }

    @webhook {
        path /github-webhook*
        import /etc/frankenphp/_github_hooks_allowlist.caddy
    }
    handle @webhook {
        reverse_proxy jenkins:8080
    }

    handle {
        reverse_proxy jenkins:8080
    }
}
EOCADDY
fi
ok "Jenkins Caddyfile created: $JENKINS_CADDY"

# ─── Root .env ────────────────────────────────────────────────
say "Writing root .env..."
cat > .env <<EOF
# ─── Cloudflare ───────────────────────────────────────────────
CF_API_TOKEN=${CF_API_TOKEN}

# ─── Admin ────────────────────────────────────────────────────
ADMIN_EMAIL=${ADMIN_EMAIL}

# ─── MySQL ────────────────────────────────────────────────────
MYSQL_VERSION=9.3.0
MYSQL_ROOT_PASSWORD="${MYSQL_ROOT_PASSWORD}"

# ─── Meilisearch ──────────────────────────────────────────────
MEILISEARCH_MASTER_KEY="${MEILISEARCH_MASTER_KEY}"
ALPHA_PANEL_MEILISEARCH_MASTER_KEY="${ALPHA_PANEL_MEILISEARCH_MASTER_KEY}"

# ─── PostgreSQL (N8N) ─────────────────────────────────────────
POSTGRESQL_USER=${POSTGRESQL_USER}
POSTGRESQL_PASSWORD="${POSTGRESQL_PASSWORD}"

# ─── Network ──────────────────────────────────────────────────
PRIVATE_NETWORK_IP=${DETECTED_PRIVATE}
PUBLIC_NETWORK_IP=${DETECTED_PUBLIC}

# ─── Domains ──────────────────────────────────────────────────
BASE_DOMAIN=${BASE_DOMAIN}
PANEL_DOMAIN=${PANEL_DOMAIN}
PMA_DOMAIN=${PMA_DOMAIN}
CODE_SERVER_DOMAIN=${CODE_SERVER_DOMAIN}
VAULTWARDEN_DOMAIN=${VAULTWARDEN_DOMAIN}
N8N_DOMAIN=${N8N_DOMAIN}
PORTAINER_DOMAIN=${PORTAINER_DOMAIN}
JENKINS_DOMAIN=${JENKINS_DOMAIN}
JENKINS_ADMIN_IPS=${JENKINS_ADMIN_IPS}

# ─── Vaultwarden ──────────────────────────────────────────────
VAULTWARDEN_DB_HOST=mysql
VAULTWARDEN_DB_NAME=bitwarden
VAULTWARDEN_DB_USER=bitwarden
VAULTWARDEN_DB_PASSWORD="${VAULTWARDEN_DB_PASSWORD}"

# ─── Code Server (File Manager) ───────────────────────────────
CODE_SERVER_PASSWORD="${CODE_SERVER_PASSWORD}"
CODE_SERVER_SUDO_PASSWORD="${CODE_SERVER_PASSWORD}"
CODE_SERVER_PWA_APP_NAME="AlphaPanel Code Server"

# ─── N8N ──────────────────────────────────────────────────────
N8N_EMAIL_MODE=smtp
N8N_SMTP_HOST=
N8N_SMTP_PORT=587
N8N_SMTP_USER=
N8N_SMTP_PASS=
N8N_SMTP_SENDER=
N8N_SMTP_SSL=false
N8N_ENCRYPTION_KEY="${N8N_ENCRYPTION_KEY}"

# ─── phpMyAdmin ───────────────────────────────────────────────
PMA_BLOWFISH_SECRET=${PMA_BLOWFISH_SECRET}
PANEL_DB_NAME=${PANEL_DB_NAME}
PANEL_DB_USER=${PANEL_DB_USER}
PANEL_DB_PASS=${PANEL_DB_PASS}
PMA_URL=https://${PMA_DOMAIN}:8443/index.php?server=2

# ─── Update Agent ────────────────────────────────────────────
UPDATE_AGENT_SECRET=${UPDATE_AGENT_SECRET}
PANEL_GITHUB_REPO=alphapanel/alphapanel-docker

# ─── CrowdSec / Coraza ────────────────────────────────────────
CROWDSEC_FIREWALL_BOUNCER_KEY=${CROWDSEC_FIREWALL_BOUNCER_KEY}
CROWDSEC_DASHBOARD_API_KEY=${CROWDSEC_DASHBOARD_API_KEY}
CROWDSEC_LAPI_URL=http://crowdsec:8080

# ─── Resource Limits (optional) ──────────────────────────────
# Per-service CPU/memory limits. Disabled by default (services use all available resources).
# To enable: uncomment COMPOSE_FILE and customise the limits below.
# COMPOSE_FILE=docker-compose.yaml:docker-compose.resources.yaml
# FRANKENPHP_CPU_LIMIT=2.0
# FRANKENPHP_MEM_LIMIT=2g
# ALPHA_PANEL_CPU_LIMIT=1.0
# ALPHA_PANEL_MEM_LIMIT=1g
# CODE_SERVER_CPU_LIMIT=1.0
# CODE_SERVER_MEM_LIMIT=1g
# MYSQL_CPU_LIMIT=2.0
# MYSQL_MEM_LIMIT=4g
# REDIS_CPU_LIMIT=0.5
# REDIS_MEM_LIMIT=512m
EOF
ok "Root .env created."

# ─── Alpha Panel Laravel .env ─────────────────────────────────
say "Writing Alpha Panel application .env..."
LARAVEL_ENV="alpha-panel/web/httpdocs/.env"
cp "alpha-panel/web/httpdocs/.env.example" "$LARAVEL_ENV"

# Update values via sed
sed_i "s|^APP_NAME=.*|APP_NAME=AlphaPanel|"                               "$LARAVEL_ENV"
sed_i "s|^APP_ENV=.*|APP_ENV=production|"                                  "$LARAVEL_ENV"
sed_i "s|^APP_KEY=.*|APP_KEY=${APP_KEY}|"                                  "$LARAVEL_ENV"
sed_i "s|^APP_DEBUG=.*|APP_DEBUG=false|"                                   "$LARAVEL_ENV"
sed_i "s|^APP_URL=.*|APP_URL=https://${PANEL_DOMAIN}:8443|"               "$LARAVEL_ENV"
sed_i "s|^APP_LOCALE=.*|APP_LOCALE=en|"                                    "$LARAVEL_ENV"
sed_i "s|^DB_CONNECTION=.*|DB_CONNECTION=mysql|"                           "$LARAVEL_ENV"
sed_i "s|^# DB_HOST=.*|DB_HOST=mysql|"                                     "$LARAVEL_ENV"
sed_i "s|^# DB_PORT=.*|DB_PORT=3306|"                                      "$LARAVEL_ENV"
sed_i "s|^# DB_DATABASE=.*|DB_DATABASE=${PANEL_DB_NAME}|"                 "$LARAVEL_ENV"
sed_i "s|^# DB_USERNAME=.*|DB_USERNAME=${PANEL_DB_USER}|"                 "$LARAVEL_ENV"
sed_i "s|^# DB_PASSWORD=.*|DB_PASSWORD=${PANEL_DB_PASS}|"                 "$LARAVEL_ENV"
sed_i "s|^CACHE_STORE=.*|CACHE_STORE=redis|"                               "$LARAVEL_ENV"
sed_i "s|^QUEUE_CONNECTION=.*|QUEUE_CONNECTION=redis|"                     "$LARAVEL_ENV"
sed_i "s|^REDIS_HOST=.*|REDIS_HOST=redis|"                                 "$LARAVEL_ENV"
sed_i "s|^REVERB_APP_ID=.*|REVERB_APP_ID=${REVERB_APP_ID}|"               "$LARAVEL_ENV"
sed_i "s|^REVERB_APP_KEY=.*|REVERB_APP_KEY=${REVERB_APP_KEY}|"             "$LARAVEL_ENV"
sed_i "s|^REVERB_APP_SECRET=.*|REVERB_APP_SECRET=${REVERB_APP_SECRET}|"   "$LARAVEL_ENV"
sed_i "s|^REVERB_HOST=.*|REVERB_HOST=${PANEL_DOMAIN}|"                    "$LARAVEL_ENV"
sed_i "s|^REVERB_PORT=.*|REVERB_PORT=443|"                                 "$LARAVEL_ENV"
sed_i "s|^REVERB_SCHEME=.*|REVERB_SCHEME=https|"                          "$LARAVEL_ENV"
sed_i "s|^LOG_LEVEL=.*|LOG_LEVEL=error|"                                   "$LARAVEL_ENV"
sed_i "s|^SESSION_DOMAIN=.*|SESSION_DOMAIN=${PANEL_DOMAIN}|"               "$LARAVEL_ENV"
sed_i "s|^MAIL_FROM_ADDRESS=.*|MAIL_FROM_ADDRESS=\"${ADMIN_EMAIL}\"|"      "$LARAVEL_ENV"
sed_i "s|^PANEL_ADMIN_NAME=.*|PANEL_ADMIN_NAME=\"${PANEL_ADMIN_NAME}\"|"   "$LARAVEL_ENV"
sed_i "s|^PANEL_ADMIN_USERNAME=.*|PANEL_ADMIN_USERNAME=${PANEL_ADMIN_USERNAME}|" "$LARAVEL_ENV"
sed_i "s|^PANEL_ADMIN_EMAIL=.*|PANEL_ADMIN_EMAIL=${PANEL_ADMIN_EMAIL}|"    "$LARAVEL_ENV"
sed_i "s|^PANEL_ADMIN_PASSWORD=.*|PANEL_ADMIN_PASSWORD=${PANEL_ADMIN_PASSWORD}|" "$LARAVEL_ENV"

# Append production-specific vars
cat >> "$LARAVEL_ENV" <<EOF

# ─── Search ───────────────────────────────────────────────────
SCOUT_DRIVER=meilisearch
MEILISEARCH_HOST=http://alpha_panel_meilisearch:7700
MEILISEARCH_KEY=${ALPHA_PANEL_MEILISEARCH_MASTER_KEY}

# ─── Panel config ─────────────────────────────────────────────
PANEL_CADDY_MAIN_CONFIG=/etc/frankenphp-container/Caddyfile
PANEL_CADDY_SITES_BASE=/etc/frankenphp-container/sites-enabled
PANEL_CADDY_ADMIN_URL=http://frankenphp:2019
PANEL_FRANKENPHP_CONTAINER=frankenphp
PANEL_PHP_CODE_SERVER_CONTAINER=php-code-server
PANEL_DOCKER_TIMEOUT=15

COMPOSE_PROJECT_ROOT=/docker_compose_project_root
COMPOSE_PROJECT_ROOT_HOST=${INSTALL_DIR}
PORTAINER_CERTBOT_IMAGE=alphapanel-docker-certbot-init:latest

# ─── Services ─────────────────────────────────────────────────
PORTAINER_URL=https://${PORTAINER_DOMAIN}:8443
PORTAINER_API_KEY=
PORTAINER_ENDPOINT_ID=1

PMA_URL=https://${PMA_DOMAIN}:8443/index.php?server=2
JENKINS_URL=https://${JENKINS_DOMAIN}
PANEL_DB_HOST=mysql
PANEL_DB_PORT=3306
PANEL_DB_NAME=${PANEL_DB_NAME}
PANEL_DB_USER=${PANEL_DB_USER}
PANEL_DB_PASS=${PANEL_DB_PASS}

# ─── CrowdSec ─────────────────────────────────────────────────
CROWDSEC_LAPI_URL=http://crowdsec:8080
CROWDSEC_DASHBOARD_API_KEY=${CROWDSEC_DASHBOARD_API_KEY}

# ─── SSH Terminal ─────────────────────────────────────────────
PANEL_SSH_HOST=${DOCKER_HOST_IP:-172.17.0.1}
PANEL_SSH_PORT=22
PANEL_SSH_USER=root
PANEL_SSH_KEY_PATH=/root/.ssh/alphapanel_ed25519

# ─── Update Agent ────────────────────────────────────────────
UPDATE_AGENT_URL=http://update-agent:8100
UPDATE_AGENT_SECRET=${UPDATE_AGENT_SECRET}
PANEL_GITHUB_REPO=alphapanel/alphapanel-docker
UPDATE_AUTO_CHECK=true
EOF
ok "Alpha Panel .env created."

# ─── ftp-config/users.env ─────────────────────────────────────
if [ ! -f ftp-config/users.env ]; then
    cp ftp-config/users.env.example ftp-config/users.env
    ok "ftp-config/users.env created from example."
fi

# ─── external-services placeholder ──────────────────────────────
if [ ! -f includeservices/local-services.yaml ]; then
    if [ -f includeservices/empty.yaml ]; then
        cp includeservices/empty.yaml includeservices/local-services.yaml
    else
        echo "# No local services" > includeservices/local-services.yaml
    fi
fi

# ─── Permissions ──────────────────────────────────────────────
say "Setting permissions on data directories..."
chmod -R u+rwX,g+rwX deploy_cache n8n 2>/dev/null || true
chown -R 1000:1000 deploy_cache n8n 2>/dev/null || true
ok "Permissions set."

# ─── SSH Key for Host Terminal Access ─────────────────────────
say "Generating SSH key for host terminal access..."
SSH_KEY_DIR="alpha-panel/web/ssh-keys"
SSH_KEY_PATH="${SSH_KEY_DIR}/alphapanel_ed25519"
mkdir -p "$SSH_KEY_DIR"
if [ ! -f "$SSH_KEY_PATH" ]; then
    ssh-keygen -t ed25519 -f "$SSH_KEY_PATH" -N "" -C "alphapanel-terminal@$(hostname)" >/dev/null 2>&1
    chmod 600 "$SSH_KEY_PATH"
    chmod 644 "${SSH_KEY_PATH}.pub"
    ok "SSH key pair generated: ${SSH_KEY_PATH}"
else
    ok "SSH key already exists: ${SSH_KEY_PATH}"
fi

# Add public key to host authorized_keys
HOST_AUTH_KEYS="/root/.ssh/authorized_keys"
mkdir -p /root/.ssh
chmod 700 /root/.ssh
PUB_KEY=$(cat "${SSH_KEY_PATH}.pub")
if ! grep -qF "$PUB_KEY" "$HOST_AUTH_KEYS" 2>/dev/null; then
    echo "$PUB_KEY" >> "$HOST_AUTH_KEYS"
    chmod 600 "$HOST_AUTH_KEYS"
    ok "Public key added to host ${HOST_AUTH_KEYS}"
else
    ok "Public key already in host authorized_keys."
fi

# Detect host IP accessible from container (docker bridge gateway)
DOCKER_HOST_IP=$(docker network inspect bridge --format '{{range .IPAM.Config}}{{.Gateway}}{{end}}' 2>/dev/null || echo "172.17.0.1")
[ -z "$DOCKER_HOST_IP" ] && DOCKER_HOST_IP="172.17.0.1"
ok "Docker host gateway IP: ${DOCKER_HOST_IP}"

# Ensure sshd is running on the host
if command -v systemctl &>/dev/null; then
    if ! systemctl is-active --quiet sshd 2>/dev/null && ! systemctl is-active --quiet ssh 2>/dev/null; then
        warn "SSH server (sshd) is not running on the host. Starting it..."
        install_pkg openssh-server
        systemctl enable --now sshd 2>/dev/null || systemctl enable --now ssh 2>/dev/null || true
    fi
    ok "Host SSH server is running."
fi

# ─── Summary before launch ────────────────────────────────────
echo ""
echo -e "${BOLD}─── Configuration Summary ────────────────────────────────${NC}"
echo -e "  Panel domain:    ${CYAN}${PANEL_DOMAIN}${NC}"
echo -e "  phpMyAdmin:      ${CYAN}${PMA_DOMAIN}${NC}"
echo -e "  File manager:    ${CYAN}${CODE_SERVER_DOMAIN}${NC}"
echo -e "  Portainer:       ${CYAN}${PORTAINER_DOMAIN}${NC}"
echo -e "  N8N:             ${CYAN}${N8N_DOMAIN}${NC}"
echo -e "  Password mgr:    ${CYAN}${VAULTWARDEN_DOMAIN}${NC}"
echo -e "  Private IP:      ${CYAN}${DETECTED_PRIVATE}${NC}"
echo -e "  Public IP:       ${CYAN}${DETECTED_PUBLIC}${NC}"
echo ""
echo -e "  ${YELLOW}SSL certificates will be issued on first start via"
echo -e "  Cloudflare DNS-01 challenge. This may take ~2 minutes.${NC}"
echo ""
read -rp "  Press ENTER to start AlphaPanel-Docker, or Ctrl+C to abort..."

# ─── Build & Start ────────────────────────────────────────────
echo ""
say "Building images and starting services (this may take several minutes)..."
docker compose up -d --build

# ─── Wait for Portainer ───────────────────────────────────
PORTAINER_API_BASE="http://${DETECTED_PRIVATE}:9000"
say "Waiting for Portainer to become ready..."
_max=180; _i=0
printf "  "
while [ $_i -lt $_max ]; do
    if curl -fsk "${PORTAINER_API_BASE}/api/status" &>/dev/null 2>&1; then
        echo "ready."
        break
    fi
    printf "."
    sleep 3
    _i=$((_i + 3))
done
[ $_i -ge $_max ] && { echo ""; warn "Portainer did not respond in ${_max}s — it may still be initializing."; }

echo ""
echo -e "${BOLD}─── Portainer Setup ──────────────────────────────────────${NC}"
echo ""
echo -e "  Portainer is now running. Open this URL in your browser:"
echo -e "  ${CYAN}http://${DETECTED_PRIVATE}:9000${NC}"
echo ""
echo -e "  Complete these steps in the Portainer UI:"
echo -e "  ${BOLD}1.${NC} Set an admin username and password"
echo -e "  ${BOLD}2.${NC} Click ${BOLD}Get Started${NC} → select the local Docker environment"
echo -e "  ${BOLD}3.${NC} Click your user icon (top-right) → ${BOLD}My account${NC}"
echo -e "  ${BOLD}4.${NC} Scroll to ${BOLD}Access tokens${NC} → ${BOLD}Add access token${NC}"
echo -e "  ${BOLD}5.${NC} Enter a name (e.g. ${BOLD}AlphaPanel${NC}) and copy the generated token"
echo ""
ask PORTAINER_API_KEY "Paste the Portainer access token"

# Auto-detect first endpoint ID via Portainer API
say "Detecting Portainer endpoint ID..."
PORTAINER_ENDPOINTS=$(curl -fsk \
    -H "X-API-Key: ${PORTAINER_API_KEY}" \
    "${PORTAINER_API_BASE}/api/endpoints" 2>/dev/null || echo "")
PORTAINER_ENDPOINT_ID=$(echo "$PORTAINER_ENDPOINTS" \
    | grep -o '"Id":[0-9]*' | head -1 | grep -o '[0-9]*')
if [ -z "$PORTAINER_ENDPOINT_ID" ]; then
    warn "Could not auto-detect endpoint ID — defaulting to 1."
    PORTAINER_ENDPOINT_ID=1
else
    ok "Detected endpoint ID: ${PORTAINER_ENDPOINT_ID}"
fi

# Persist Portainer credentials into the Laravel .env
sed_i "s|^PORTAINER_API_KEY=.*|PORTAINER_API_KEY=${PORTAINER_API_KEY}|"             "$LARAVEL_ENV"
sed_i "s|^PORTAINER_ENDPOINT_ID=.*|PORTAINER_ENDPOINT_ID=${PORTAINER_ENDPOINT_ID}|" "$LARAVEL_ENV"
ok "Portainer credentials saved to Laravel .env."

# ─── Wait for MySQL ───────────────────────────────────────
say "Waiting for MySQL to be ready..."
_max=180; _i=0
printf "  "
while [ $_i -lt $_max ]; do
    if docker exec mysql mysqladmin ping -h127.0.0.1 \
        -u root -p"${MYSQL_ROOT_PASSWORD}" --silent 2>/dev/null; then
        echo "ready."
        break
    fi
    printf "."
    sleep 3
    _i=$((_i + 3))
done
[ $_i -ge $_max ] && die "MySQL did not become ready in ${_max}s. Check: docker compose logs mysql"

# ─── Database Migrations & Seeding ───────────────────────
say "Running database migrations..."
docker exec alpha_panel_web php artisan migrate --force
ok "Migrations complete."

say "Seeding database (PHP versions)..."
docker exec alpha_panel_web php artisan db:seed --class=PhpVersionSeeder --force
ok "Database seeded."

say "Creating panel admin user..."
docker exec alpha_panel_web php artisan app:add-admin-user \
    --name="$PANEL_ADMIN_NAME" \
    --username="$PANEL_ADMIN_USERNAME" \
    --email="$PANEL_ADMIN_EMAIL" \
    --password="$PANEL_ADMIN_PASSWORD"
ok "Admin user created."

# ─── Done ─────────────────────────────────────────────────────
echo ""
echo -e "${GREEN}${BOLD}╔══════════════════════════════════════════════════════╗${NC}"
echo -e "${GREEN}${BOLD}║           Installation complete!                     ║${NC}"
echo -e "${GREEN}${BOLD}╚══════════════════════════════════════════════════════╝${NC}"
echo ""
echo -e "  ${BOLD}Service URLs${NC}"
echo -e "  Panel:           ${CYAN}https://${PANEL_DOMAIN}:8443${NC}"
echo -e "  phpMyAdmin:      ${CYAN}https://${PMA_DOMAIN}:8443${NC}"
echo -e "  File Manager:    ${CYAN}https://${CODE_SERVER_DOMAIN}:8443${NC}"
echo -e "  Portainer:       ${CYAN}https://${PORTAINER_DOMAIN}:8443${NC}"
echo -e "  N8N:             ${CYAN}https://${N8N_DOMAIN}:8443${NC}"
echo -e "  Passwords:       ${CYAN}https://${VAULTWARDEN_DOMAIN}:8443${NC}"
echo ""
echo -e "  ${BOLD}Panel Login${NC}"
echo -e "  Username:        ${CYAN}${PANEL_ADMIN_USERNAME}${NC}"
echo -e "  Email:           ${CYAN}${PANEL_ADMIN_EMAIL}${NC}"
echo ""
echo -e "  Root .env:       ${BOLD}${INSTALL_DIR}/.env${NC}"
echo -e "  Laravel .env:    ${BOLD}${INSTALL_DIR}/alpha-panel/web/httpdocs/.env${NC}"
echo ""
echo -e "  ${YELLOW}Tip: follow logs with:${NC}  docker compose -C ${INSTALL_DIR} logs -f"
echo ""
