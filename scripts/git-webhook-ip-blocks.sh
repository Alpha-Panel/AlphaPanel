#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OUT="${SCRIPT_DIR}/../frankenphp/_github_hooks_allowlist.caddy"

# hooks CIDR listesi çek
CIDRS="$(curl -fsSL https://api.github.com/meta | jq -r '.hooks[]' | tr '\n' ' ')"

mkdir -p "$(dirname "$OUT")"

cat > "$OUT" <<EOF
remote_ip $CIDRS
EOF

# Caddy admin API ile reload (host -> private ip)
# PRIVATE_NETWORK_IP .env'de tanımlı değilse buraya sabit IP yazabilirsin.
ADMIN_HOST="${PRIVATE_NETWORK_IP:-127.0.0.1}"
ADMIN_PORT="${CADDY_ADMIN_PORT:-2019}"

# config reload (dosyaları yeniden okur)
curl -fsS -X POST "http://${ADMIN_HOST}:${ADMIN_PORT}/load" \
  -H "Content-Type: text/caddyfile" \
  --data-binary @"${SCRIPT_DIR}/../frankenphp/Caddyfile"

echo "OK: wrote allowlist to $OUT and reloaded Caddy via admin API"