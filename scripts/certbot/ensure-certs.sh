#!/bin/sh
set -eu

EMAIL="${ADMIN_EMAIL:-}"
if [ -z "$EMAIL" ]; then
  echo "ERROR: ADMIN_EMAIL environment variable is required for certbot issuance." >&2
  exit 1
fi

DOMAIN_ROOT="${CERTBOT_DOMAIN_ROOT:-/etc/frankenphp/sites-enabled}"
PROPAGATION="${CERTBOT_PROPAGATION_SECONDS:-90}"
INCLUDE_WILDCARD="${CERTBOT_INCLUDE_WILDCARD:-1}"
UPDATE_CADDYFILES="${CERTBOT_UPDATE_CADDYFILES:-1}"
USE_STAGING="${CERTBOT_USE_STAGING:-0}"
ACME_SERVER="${CERTBOT_ACME_SERVER:-}"

if [ -z "$ACME_SERVER" ] && [ "$USE_STAGING" != "0" ]; then
  ACME_SERVER="https://acme-staging-v02.api.letsencrypt.org/directory"
fi

CERTBOT_SERVER_ARGS=""
if [ -n "$ACME_SERVER" ]; then
  CERTBOT_SERVER_ARGS="--server $ACME_SERVER"
  echo "INFO: Using ACME server $ACME_SERVER"
fi

inject_caddy_tls() {
  domain="$1"
  if [ "$UPDATE_CADDYFILES" = "0" ]; then
    return 0
  fi

  caddy_dir="$DOMAIN_ROOT/$domain"
  caddy_file="$caddy_dir/Caddyfile"
  if [ ! -f "$caddy_file" ]; then
    echo "WARN: No Caddyfile found for $domain at $caddy_file; skipping TLS injection." >&2
    return 0
  fi

  python3 - "$domain" "$DOMAIN_ROOT" <<'PY'
import pathlib
import re
import sys

domain = sys.argv[1]
root = pathlib.Path(sys.argv[2])
caddyfile = root / domain / "Caddyfile"
cert_line = f"tls /etc/letsencrypt/live/{domain}/fullchain.pem /etc/letsencrypt/live/{domain}/privkey.pem  # certbot-managed"

try:
    text = caddyfile.read_text(encoding="utf-8")
except FileNotFoundError:
    sys.exit(0)

if cert_line in text:
    sys.exit(0)

pattern = re.compile(r"^(\s*)import\s+common-tls\s*$", re.MULTILINE)

def _repl(match):
    indent = match.group(1)
    return f"{indent}{cert_line}"

new_text, count = pattern.subn(_repl, text)
if count == 0:
    if f"/etc/letsencrypt/live/{domain}/" in text:
        sys.exit(0)
    print(f"WARN: {caddyfile} lacks 'import common-tls'; unable to inject certbot TLS for {domain}.", file=sys.stderr)
    sys.exit(0)

if new_text != text:
    caddyfile.write_text(new_text, encoding="utf-8")
    print(f"INFO: Wired certbot certificates into {caddyfile} for {domain}.")
PY
}

if [ ! -d "$DOMAIN_ROOT" ]; then
  echo "WARN: Domain directory $DOMAIN_ROOT not found; skipping issuance." >&2
  exit 0
fi

set -- "$DOMAIN_ROOT"/*
if [ "$1" = "$DOMAIN_ROOT/*" ]; then
  echo "WARN: No domain directories found under $DOMAIN_ROOT; nothing to issue." >&2
  exit 0
fi

STATUS=0
for dir in "$@"; do
  [ -d "$dir" ] || continue
  domain=$(basename "$dir")
  case "$domain" in
    ''|.*)
      continue
      ;;
  esac

  live_dir="/etc/letsencrypt/live/$domain"
  if [ -d "$live_dir" ]; then
    echo "INFO: Certificate already exists for $domain; ensuring Caddy TLS wiring."
    inject_caddy_tls "$domain"
    continue
  fi

  echo "INFO: Requesting certificate for $domain"
  if [ "$INCLUDE_WILDCARD" != "0" ]; then
    wildcard_domain="*.$domain"
    if certbot certonly $CERTBOT_SERVER_ARGS \
        --non-interactive \
        --agree-tos \
        --email "$EMAIL" \
        --dns-cloudflare \
        --dns-cloudflare-credentials /secrets/cloudflare.ini \
        --dns-cloudflare-propagation-seconds "$PROPAGATION" \
        --key-type ecdsa \
        --elliptic-curve secp384r1 \
        -d "$domain" \
        -d "$wildcard_domain"
    then
      echo "INFO: Certificate ready for $domain"
      inject_caddy_tls "$domain"
    else
      echo "ERROR: Failed to issue certificate for $domain" >&2
      STATUS=1
    fi
  else
    if certbot certonly $CERTBOT_SERVER_ARGS \
        --non-interactive \
        --agree-tos \
        --email "$EMAIL" \
        --dns-cloudflare \
        --dns-cloudflare-credentials /secrets/cloudflare.ini \
        --dns-cloudflare-propagation-seconds "$PROPAGATION" \
        --key-type ecdsa \
        --elliptic-curve secp384r1 \
        -d "$domain"
    then
      echo "INFO: Certificate ready for $domain"
      inject_caddy_tls "$domain"
    else
      echo "ERROR: Failed to issue certificate for $domain" >&2
      STATUS=1
    fi
  fi

done

exit "$STATUS"
