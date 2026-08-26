#!/usr/bin/env bash
#
# One-time upgrade step for installs created before the dedicated PowerDNS
# MySQL account existed.
#
# PowerDNS used to connect as MySQL root, with docker-compose.yaml passing
# MYSQL_ROOT_PASSWORD on the pdns command line. docker-compose.yaml now passes
# POWERDNS_DB_USER / POWERDNS_DB_PASSWORD instead, so an install that pulls this
# change without those variables would start pdns with an empty password and the
# gmysql backend would fail to connect — DNS stops answering.
#
# Idempotent: run it as often as you like.
#
#   bash scripts/upgrade/2026-08-26-powerdns-user.sh
#
set -euo pipefail

cd "$(dirname "$0")/../.."
ENV_FILE=".env"

[ -f "$ENV_FILE" ] || { echo "error: $ENV_FILE not found; run from the project root" >&2; exit 1; }

read_env() {
    # KEY=value from .env, stripping surrounding double quotes. Last wins.
    sed -n -E "s/^$1=\"?([^\"]*)\"?\$/\1/p" "$ENV_FILE" | tail -n1
}

ROOT_PW="$(read_env MYSQL_ROOT_PASSWORD)"
[ -n "$ROOT_PW" ] || { echo "error: MYSQL_ROOT_PASSWORD is empty in $ENV_FILE" >&2; exit 1; }

PDNS_DB="$(read_env POWERDNS_DB_NAME)"; PDNS_DB="${PDNS_DB:-powerdns}"
PDNS_USER="$(read_env POWERDNS_DB_USER)"; PDNS_USER="${PDNS_USER:-powerdns}"
PDNS_PW="$(read_env POWERDNS_DB_PASSWORD)"

BACKUP="${ENV_FILE}.bak-$(date +%Y%m%d-%H%M%S)"
cp -p "$ENV_FILE" "$BACKUP"
chmod 600 "$BACKUP"
echo "==> backed up $ENV_FILE to $BACKUP"

if [ -n "$PDNS_PW" ]; then
    echo "==> reusing the POWERDNS_DB_PASSWORD already in $ENV_FILE"
else
    # Hex only, so it is safe to drop straight into sed and into SQL below.
    PDNS_PW="$(openssl rand -hex 16)"
    echo "==> generated a new POWERDNS_DB_PASSWORD"

    if grep -qE '^POWERDNS_DB_PASSWORD=' "$ENV_FILE"; then
        sed -i -E "s|^POWERDNS_DB_PASSWORD=.*$|POWERDNS_DB_PASSWORD=\"${PDNS_PW}\"|" "$ENV_FILE"
    else
        printf '\nPOWERDNS_DB_PASSWORD="%s"\n' "$PDNS_PW" >> "$ENV_FILE"
    fi
fi

grep -qE '^POWERDNS_DB_NAME=' "$ENV_FILE" || printf '\n# ─── PowerDNS ───\nPOWERDNS_DB_NAME=%s\n' "$PDNS_DB" >> "$ENV_FILE"
grep -qE '^POWERDNS_DB_USER=' "$ENV_FILE" || printf 'POWERDNS_DB_USER=%s\n' "$PDNS_USER" >> "$ENV_FILE"
chmod 600 "$ENV_FILE"
echo "==> POWERDNS_DB_* present in $ENV_FILE"

# Least-privilege account: DML only. The schema is owned by the panel migration,
# so pdns never needs DDL, and it must not reach any other database.
SQL_FILE="$(mktemp)"
trap 'rm -f "$SQL_FILE"' EXIT
cat > "$SQL_FILE" <<SQL
CREATE DATABASE IF NOT EXISTS \`${PDNS_DB}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${PDNS_USER}'@'%' IDENTIFIED BY '${PDNS_PW}';
ALTER USER '${PDNS_USER}'@'%' IDENTIFIED BY '${PDNS_PW}';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${PDNS_DB}\`.* TO '${PDNS_USER}'@'%';
FLUSH PRIVILEGES;
SQL

# The PowerDNS password travels in a 0600 temp file; the root password travels as
# an inherited env var. `docker exec -e RP` (no `=value`) copies it from this
# shell's environment, so neither password appears in the docker argv that `ps`
# and the audit log can see.
docker cp "$SQL_FILE" mysql:/tmp/pdns_upgrade.sql >/dev/null
RP="$ROOT_PW" docker exec -e RP mysql bash -c 'mysql -uroot -p"$RP" < /tmp/pdns_upgrade.sql'
docker exec mysql rm -f /tmp/pdns_upgrade.sql
echo "==> MySQL account '${PDNS_USER}'@'%' created/updated with DML on ${PDNS_DB}.*"

docker compose up -d powerdns
echo "==> powerdns recreated"
echo
echo "Verify with:"
echo "  docker logs powerdns 2>&1 | tail -20        # no 'Access denied' / \"doesn't exist\""
echo "  docker exec powerdns pdns_control rping     # expects PONG"
