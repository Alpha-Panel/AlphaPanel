#!/bin/sh
set -e

. /config/users.env

# ─── TLS sertifika hazirla ─────────────────────────────────
TLS_DIR=/tmp/ftp-ssl
mkdir -p "$TLS_DIR"

if [ -n "${BASE_DOMAIN:-}" ] && [ -f "/etc/letsencrypt/live/${BASE_DOMAIN}/fullchain.pem" ]; then
  cp -f "/etc/letsencrypt/live/${BASE_DOMAIN}/fullchain.pem" "$TLS_DIR/fullchain.pem"
  cp -f "/etc/letsencrypt/live/${BASE_DOMAIN}/privkey.pem"   "$TLS_DIR/privkey.pem"
  chmod 600 "$TLS_DIR/privkey.pem"
  echo "TLS: Using Let's Encrypt cert for ${BASE_DOMAIN}"
else
  apk add --no-cache openssl >/dev/null 2>&1 || true
  openssl req -x509 -nodes -days 3650 -newkey rsa:2048 \
    -keyout "$TLS_DIR/privkey.pem" \
    -out "$TLS_DIR/fullchain.pem" \
    -subj "/CN=${BASE_DOMAIN:-ftp-server}" 2>/dev/null
  chmod 600 "$TLS_DIR/privkey.pem"
  echo "TLS: Generated self-signed cert"
fi

# ─── www-data grubu + kullanici sahipligi ───────────────────
if ! getent group www-data >/dev/null 2>&1; then
  addgroup -g 33 www-data
fi

for entry in $USERS; do
  user="${entry%%|*}"
  rest="${entry#*|}"
  pass="${rest%%|*}"
  rest2="${rest#*|}"
  homedir="${rest2%%|*}"
  uid="${rest2##*|}"

  if ! getent group "$user" >/dev/null 2>&1; then
    addgroup -g "$uid" "$user"
  fi
  if ! id -u "$user" >/dev/null 2>&1; then
    adduser -D -u "$uid" -G "$user" -h "$homedir" "$user"
    echo "$user:$pass" | chpasswd
  fi
  addgroup www-data "$user" >/dev/null 2>&1 || true
  [ -d "$homedir" ] && chown -R "${user}:www-data" "$homedir"
done

# ─── Base image config'ine eksik direktifleri ekle ──────────
# Config'i ezmiyoruz, sadece olmayan ayarlari ekliyoruz.
CONF=/etc/vsftpd/vsftpd.conf
for directive in \
  "chroot_local_user=YES" \
  "allow_writeable_chroot=YES" \
  "force_dot_files=YES" \
  "require_ssl_reuse=NO" \
  "seccomp_sandbox=NO"
do
  key="${directive%%=*}"
  grep -q "^${key}=" "$CONF" 2>/dev/null || echo "$directive" >> "$CONF"
done
echo "vsftpd.conf patched (chroot + require_ssl_reuse=NO)"

# ─── Base image'in kendi startup'ina devret ─────────────────
echo "Handing off to base image startup..."
exec /sbin/tini -- /bin/start_vsftpd.sh
