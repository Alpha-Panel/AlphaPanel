#!/bin/sh
set -eu

rm -f /var/run/supervisor.sock || true

# conf.d varsa supervisor'ı arkada başlat
if ls /etc/supervisor/conf.d/*.conf >/dev/null 2>&1; then
  /usr/bin/supervisord -c /etc/supervisor/supervisord.conf >/dev/null 2>&1 &
fi

# FrankenPHP'yi PID1 olarak başlat (asıl servis)
exec /usr/local/bin/frankenphp run --config /etc/frankenphp/Caddyfile
