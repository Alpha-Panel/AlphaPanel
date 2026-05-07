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

if [ ! -f /etc/os-release ]; then
    die "Cannot detect OS. /etc/os-release missing."
fi
# shellcheck source=/dev/null
. /etc/os-release
OS_ID="${ID:-unknown}"
say "Detected OS: ${PRETTY_NAME:-$OS_ID}"

case "$OS_ID" in
    ubuntu|debian)
        apt-get update -qq
        say "Installing prerequisites (curl, git, python3, python3-venv, python3-pip)..."
        apt-get install -y curl git python3 python3-venv python3-pip >/dev/null
        ;;
    centos|rhel|rocky|almalinux|fedora)
        say "Installing prerequisites (curl, git, python3, python3-pip)..."
        dnf install -y curl git python3 python3-pip >/dev/null
        ;;
    *)
        die "Unsupported OS: $OS_ID"
        ;;
esac
ok "Prerequisites installed."

if ! command -v docker >/dev/null 2>&1; then
    say "Installing Docker via get.docker.com..."
    curl -fsSL https://get.docker.com | sh
    systemctl enable --now docker
    ok "Docker installed."
else
    ok "Docker already installed: $(docker --version)"
fi
docker compose version >/dev/null 2>&1 || die "Docker Compose plugin missing. Install: https://docs.docker.com/compose/install/"

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

if [ ! -f "${INSTALL_DIR}/external-services/local-services.yaml" ]; then
    say "Creating external-services/local-services.yaml..."
    printf '# Local extra services (git-ignored). Add your own compose services here.\n' \
        > "${INSTALL_DIR}/external-services/local-services.yaml"
    ok "external-services/local-services.yaml created."
fi

if [ ! -f "${INSTALL_DIR}/ftp-config/users.env" ]; then
    say "Creating ftp-config/users.env..."
    printf 'USERS=""\n' > "${INSTALL_DIR}/ftp-config/users.env"
    ok "ftp-config/users.env created."
fi

say "Materializing stub files (php.ini, supervisor confs)..."
for ver in 7.0 7.1 7.2 7.3 7.4 8.0 8.1 8.2 8.3 8.4 8.5; do
    stub="${INSTALL_DIR}/php-code-server/${ver}/php.ini.stub"
    target="${INSTALL_DIR}/php-code-server/${ver}/php.ini"
    [ -f "$stub" ] && [ ! -f "$target" ] && cp "$stub" "$target"

    stub="${INSTALL_DIR}/php-code-server/supervisor.d/php-fpm-${ver}.conf.stub"
    target="${INSTALL_DIR}/php-code-server/supervisor.d/php-fpm-${ver}.conf"
    [ -f "$stub" ] && [ ! -f "$target" ] && cp "$stub" "$target"
done
ok "Stub files materialized."

HOST_IP=$(ip route get 1.1.1.1 2>/dev/null \
    | awk '/src/ {for(i=1;i<=NF;i++) if($i=="src") print $(i+1); exit}' \
    || echo "localhost")

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
