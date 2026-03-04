#!/usr/bin/env bash
set -e

# Eğer /etc/users.env dosyası varsa, içindeki USERS değişkenini yükle
if [ -f /etc/users.env ]; then
  # shellcheck disable=SC1090
  source /etc/users.env
fi

# USERS değişkeni: her kayıt "user|password|home|uid"
if [ -n "$USERS" ]; then
  # Burası whitespace (space/newline) ayırarak diziye dönüştürür
  read -ra ENTRIES <<< "$USERS"
  for entry in "${ENTRIES[@]}"; do
    IFS='|' read -r user pass home uid <<< "$entry"

    if ! id "$user" &>/dev/null; then
      # Kullanıcı yoksa yarat ve www-data grubuna ekle
      useradd -u "$uid" -d "$home" -m -s /usr/sbin/nologin -G www-data "$user"
      echo "$user:$pass" | chpasswd
      echo "Created PHP-FPM user: $user (UID=$uid, HOME=$home) and added to www-data group"
    else
      # Zaten varsa, sadece www-data grubuna ekle (ek üyelik)
      usermod -aG www-data "$user"
      echo "User $user already exists — ensured membership in www-data group"
    fi

    # Home dizininin sahipliğini user:www-data yap
    if [ -d "$home" ]; then
      chown -R "${user}:www-data" "$home"
      echo "Set ownership of $home to ${user}:www-data"
    fi
  done
fi

# Son olarak supervisord'u başlat
exec "$@"
