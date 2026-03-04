#!/bin/sh
set -e

# /config/users.env içinden USERS’ı al
. /config/users.env

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
exec /sbin/tini -- /bin/start_vsftpd.sh
