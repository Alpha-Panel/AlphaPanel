#!/usr/bin/env bash
# AlphaPanel-Docker Installer Bootstrap
# Installs prerequisites, then hands off to the Python Flask wizard.
set -euo pipefail

REPO_URL="${ALPHAPANEL_REPO_URL:-https://github.com/Alpha-Panel/AlphaPanel.git}"
INSTALL_DIR="${ALPHAPANEL_INSTALL_DIR:-/opt/alphapanel-docker}"
INSTALLER_PORT="${ALPHAPANEL_INSTALLER_PORT:-5000}"
MIN_DISK_GB="${ALPHAPANEL_MIN_DISK_GB:-50}"
MIN_COMPOSE="2.20.3"
MIN_PY_MAJOR=3
MIN_PY_MINOR=10

say()  { echo -e "\033[0;36m=>\033[0m $*"; }
ok()   { echo -e "  \033[0;32m✓\033[0m $*"; }
warn() { echo -e "  \033[0;33m!\033[0m $*" >&2; }
die()  { echo -e "\033[0;31m✗ ERROR:\033[0m $*" >&2; exit 1; }

semver_ge() {
    # returns 0 if $1 >= $2
    [ "$(printf '%s\n%s\n' "$2" "$1" | sort -V | head -1)" = "$2" ]
}

[ "$(id -u)" -eq 0 ] || die "Run as root: sudo bash install.sh"

# IG5 — warn if repo URL still points at the placeholder.
case "$REPO_URL" in
    *YOUR_USERNAME*|*your-username*)
        die "ALPHAPANEL_REPO_URL still contains the YOUR_USERNAME placeholder. Set it to your real fork before running."
        ;;
esac

# IG3 — disk space sanity check.
TARGET_FOR_DF="$(dirname "${INSTALL_DIR}")"
[ -d "$TARGET_FOR_DF" ] || TARGET_FOR_DF="/"
AVAIL_KB="$(df -Pk "$TARGET_FOR_DF" | awk 'NR==2 {print $4}')"
AVAIL_GB=$(( AVAIL_KB / 1024 / 1024 ))
if [ "${AVAIL_GB}" -lt "${MIN_DISK_GB}" ]; then
    die "Insufficient disk space at ${TARGET_FOR_DF}: ${AVAIL_GB}G available, ${MIN_DISK_GB}G required."
fi
ok "Disk space OK (${AVAIL_GB}G available at ${TARGET_FOR_DF})."

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
        say "Installing prerequisites (curl, git, python3, python3-venv, python3-pip, openssl)..."
        apt-get install -y curl git python3 python3-venv python3-pip openssl >/dev/null
        ;;
    centos|rhel|rocky|almalinux|fedora)
        say "Installing prerequisites (curl, git, python3, python3-pip, openssl)..."
        dnf install -y curl git python3 python3-pip openssl >/dev/null
        ;;
    *)
        die "Unsupported OS: $OS_ID"
        ;;
esac
ok "Prerequisites installed."

# IG6 — re-verify CLI tools after package install (defensive).
for tool in curl git python3 openssl; do
    command -v "$tool" >/dev/null 2>&1 || die "Required tool '$tool' not on PATH after install."
done

# IG4 — Python 3.10+ check.
PY_VER="$(python3 -c 'import sys; print(f"{sys.version_info[0]}.{sys.version_info[1]}")')"
PY_MAJOR="${PY_VER%%.*}"
PY_MINOR="${PY_VER##*.}"
if [ "$PY_MAJOR" -lt "$MIN_PY_MAJOR" ] || { [ "$PY_MAJOR" -eq "$MIN_PY_MAJOR" ] && [ "$PY_MINOR" -lt "$MIN_PY_MINOR" ]; }; then
    die "Python ${MIN_PY_MAJOR}.${MIN_PY_MINOR}+ required (installer uses type hints). Found: ${PY_VER}."
fi
ok "Python ${PY_VER} OK."

if ! command -v docker >/dev/null 2>&1; then
    say "Installing Docker via get.docker.com..."
    curl -fsSL https://get.docker.com | sh
    systemctl enable --now docker
    ok "Docker installed."
else
    ok "Docker already installed: $(docker --version)"
fi

# DG2 — log rotation defaults so json-file logs don't grow unbounded.
DOCKER_DAEMON_JSON="/etc/docker/daemon.json"
if [ ! -f "$DOCKER_DAEMON_JSON" ]; then
    say "Writing Docker daemon log rotation defaults..."
    mkdir -p /etc/docker
    cat > "$DOCKER_DAEMON_JSON" <<'EOF'
{
  "log-driver": "json-file",
  "log-opts": {
    "max-size": "10m",
    "max-file": "3"
  }
}
EOF
    systemctl restart docker || warn "Docker restart failed — apply daemon.json manually."
    ok "Docker daemon.json log rotation configured."
else
    ok "Docker daemon.json already present (not overwritten)."
fi

# IG2 — wait for the Docker daemon to actually accept connections before probing version.
say "Waiting for Docker daemon..."
for _ in $(seq 1 30); do
    if docker ps >/dev/null 2>&1; then
        break
    fi
    sleep 1
done
docker ps >/dev/null 2>&1 || die "Docker daemon failed to become ready within 30s."
ok "Docker daemon ready."

# IG1 — Compose plugin + version ≥ 2.20.3 (required for `include:`).
docker compose version >/dev/null 2>&1 || die "Docker Compose plugin missing. Install: https://docs.docker.com/compose/install/"
COMPOSE_VERSION="$(docker compose version --short 2>/dev/null || true)"
if [ -z "$COMPOSE_VERSION" ]; then
    die "Could not read Docker Compose version."
fi
if ! semver_ge "$COMPOSE_VERSION" "$MIN_COMPOSE"; then
    die "Docker Compose ${MIN_COMPOSE}+ required (for include:). Current: ${COMPOSE_VERSION}."
fi
ok "Docker Compose ${COMPOSE_VERSION} OK."

say "Cloning/updating repo at ${INSTALL_DIR}..."
if [ -d "${INSTALL_DIR}/.git" ]; then
    git -C "${INSTALL_DIR}" pull --ff-only
elif [ -d "${INSTALL_DIR}" ] && [ -n "$(ls -A "${INSTALL_DIR}" 2>/dev/null || true)" ]; then
    # IG7 — directory exists but is not a git checkout. Bail instead of corrupting state.
    die "${INSTALL_DIR} exists and is not empty but is not a git checkout. Remove it or pick another ALPHAPANEL_INSTALL_DIR."
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
