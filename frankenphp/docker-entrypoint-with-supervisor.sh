#!/bin/sh
set -eu

rm -f /var/run/supervisor.sock || true

# ---- FTP/domain kullanıcılarını senkronize et (cron exec için gerekli) ----
if [ -f /etc/users.env ]; then
  . /etc/users.env
  if [ -n "${USERS:-}" ]; then
    for entry in $USERS; do
      user="${entry%%|*}"
      rest="${entry#*|}"
      pass="${rest%%|*}"
      rest2="${rest#*|}"
      home="${rest2%%|*}"
      uid="${rest2##*|}"

      if ! id "$user" >/dev/null 2>&1; then
        groupadd -g "$uid" "$user" 2>/dev/null || true
        useradd -u "$uid" -g "$user" -d "$home" -M -s /usr/sbin/nologin "$user" 2>/dev/null || true
        usermod -aG www-data "$user" 2>/dev/null || true
        echo "FrankenPHP: created user $user (UID=$uid)"
      fi
    done
  fi
fi

# conf.d varsa supervisor'ı arkada başlat
if ls /etc/supervisor/conf.d/*.conf >/dev/null 2>&1; then
  /usr/bin/supervisord -c /etc/supervisor/supervisord.conf >/dev/null 2>&1 &
fi

# FrankenPHP'yi PID1 olarak başlat (asıl servis)
exec /usr/local/bin/frankenphp run --config /etc/frankenphp/Caddyfile
