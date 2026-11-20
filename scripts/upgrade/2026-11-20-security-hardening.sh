#!/usr/bin/env bash
#
# One-time upgrade step for installs created before the 2026-11-20 hardening.
#
# php.ini files are copied from their .stub only on first install, so a change
# to a stub never reaches a server that is already running. This applies the
# same changes to the live files:
#
#   1. session.upload_progress.enabled = Off in every php.ini. Upload progress
#      is the demonstrated entry route for phpAfterShell (CVE-2026-126714) and
#      for the PHP2Shell residual. Turning it off does not replace the patched
#      PHP packages; it closes the route until they are installed.
#   2. opcache.revalidate_freq = 2 for FrankenPHP, so a worker restarted after a
#      deploy runs the new code within about 3 seconds instead of about a minute.
#
# It also prints the command that blocks the SCTP kernel module on the Docker
# host (unpatched container-to-host bug disclosed with nginx2root). That one is
# a host setting, so it is left for you to run.
#
# Idempotent: run it as often as you like.
#
#   bash scripts/upgrade/2026-11-20-security-hardening.sh
#
# PHP reads php.ini at start, so restart the PHP containers afterwards:
#
#   docker compose restart php-code-server frankenphp alpha_panel_web
#
set -euo pipefail

cd "$(dirname "$0")/../.."

for ini in frankenphp/php.ini php-code-server/*/php.ini; do
    [ -f "$ini" ] || continue

    if grep -qE '^[[:space:]]*session\.upload_progress\.enabled[[:space:]]*=' "$ini"; then
        sed -i -E 's/^[[:space:]]*session\.upload_progress\.enabled[[:space:]]*=.*/session.upload_progress.enabled = Off/' "$ini"
    elif grep -qE '^;[[:space:]]*session\.upload_progress\.enabled[[:space:]]*=' "$ini"; then
        sed -i -E 's/^;[[:space:]]*session\.upload_progress\.enabled[[:space:]]*=.*/session.upload_progress.enabled = Off/' "$ini"
    else
        printf '\nsession.upload_progress.enabled = Off\n' >> "$ini"
    fi

    echo "upload progress disabled: $ini"
done

if [ -f frankenphp/php.ini ]; then
    sed -i -E 's/^[[:space:]]*opcache\.revalidate_freq[[:space:]]*=.*/opcache.revalidate_freq=2/' frankenphp/php.ini
    echo "opcache.revalidate_freq=2: frankenphp/php.ini"
fi

echo
if grep -q '^sctp ' /proc/modules 2>/dev/null; then
    echo "SCTP: the module is loaded on this host, so something may be using it. Check before blocking it."
else
    echo "SCTP: the module is not loaded. To keep containers from loading it, run as root on the Docker host:"
fi
echo "  echo 'install sctp /bin/false' > /etc/modprobe.d/alphapanel-disable-sctp.conf"
