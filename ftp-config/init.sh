#!/bin/sh
set -e

# /config/users.env içinden USERS'ı al
. /config/users.env

# ─── TLS sertifika hazırlama ────────────────────────────────
# /tmp her zaman yazılabilir — /etc/ssl read-only olabilir
TLS_DIR=/tmp/ftp-ssl
echo "🔒 Preparing FTP TLS certificate…"
mkdir -p "$TLS_DIR"

if [ -n "${BASE_DOMAIN:-}" ] && [ -f "/etc/letsencrypt/live/${BASE_DOMAIN}/fullchain.pem" ]; then
  cp -f "/etc/letsencrypt/live/${BASE_DOMAIN}/fullchain.pem" "$TLS_DIR/fullchain.pem"
  cp -f "/etc/letsencrypt/live/${BASE_DOMAIN}/privkey.pem"   "$TLS_DIR/privkey.pem"
  echo "  ✅ Using Let's Encrypt cert for ${BASE_DOMAIN}"
else
  # Let's Encrypt yoksa self-signed oluştur
  if [ ! -f "$TLS_DIR/fullchain.pem" ]; then
    apk add --no-cache openssl >/dev/null 2>&1 || true
    openssl req -x509 -nodes -days 3650 -newkey rsa:2048 \
      -keyout "$TLS_DIR/privkey.pem" \
      -out "$TLS_DIR/fullchain.pem" \
      -subj "/CN=${BASE_DOMAIN:-ftp-server}" 2>/dev/null
    echo "  ⚠️  Generated self-signed cert (replace with Let's Encrypt for production)"
  else
    echo "  • Self-signed cert already exists"
  fi
fi
chmod 600 "$TLS_DIR/privkey.pem"

echo "🔧 Ensuring www-data group exists…"
if ! getent group www-data >/dev/null 2>&1; then
  addgroup -g 33 www-data
  echo "Created group www-data (GID=33)"
fi

echo "🔧 Creating users & fixing ownership…"
for entry in $USERS; do
  user="${entry%%|*}"
  rest="${entry#*|}"
  pass="${rest%%|*}"
  rest2="${rest#*|}"
  homedir="${rest2%%|*}"
  uid="${rest2##*|}"

  # Kullanıcıya özel grup yoksa oluştur
  if ! getent group "$user" >/dev/null 2>&1; then
    addgroup -g "$uid" "$user"
    echo "  ✚ Created group $user (GID=$uid)"
  fi

  # Kullanıcı yoksa oluştur, primary group olarak kendi grubunu ver
  if ! id -u "$user" >/dev/null 2>&1; then
    adduser -D -u "$uid" -G "$user" -h "$homedir" "$user"
    echo "$user:$pass" | chpasswd
    echo "  ✚ Created user $user (UID=$uid, HOME=$homedir)"
  else
    echo "  • User $user already exists"
  fi

  # www-data grubuna ekle (ORDER: GROUP then USER)
  addgroup www-data "$user" >/dev/null 2>&1 || true
  echo "  → Added $user to www-data group"

  # Home dizinini user:www-data yap
  if [ -d "$homedir" ]; then
    echo "  → chown -R ${user}:www-data ${homedir}"
    chown -R "${user}:www-data" "$homedir"
  else
    echo "  ⚠️  Home directory $homedir not found, skipping chown"
  fi
done

echo "✅ Init done, starting vsftpd…"

# vsftpd'nin ihtiyacı olan dizinleri oluştur
mkdir -p /usr/share/empty
mkdir -p /var/log

# Çalıştırılabilir config kopyala (mount read-only olduğu için)
cp /etc/vsftpd/vsftpd.conf /tmp/vsftpd.conf

# Dinamik ayarları env var'lardan ekle
echo "pasv_address=${ADDRESS}" >> /tmp/vsftpd.conf

# Debug: config'i logla
echo "📋 vsftpd config:"
cat /tmp/vsftpd.conf
echo "---"

# vsftpd'yi tini ile başlat (PID 1 sinyal yönetimi için)
exec /sbin/tini -- /usr/sbin/vsftpd /tmp/vsftpd.conf
