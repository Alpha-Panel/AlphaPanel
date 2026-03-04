#!/bin/sh
set -eu

RENEW_INTERVAL_SECONDS="${CERTBOT_RENEW_INTERVAL_SECONDS:-43200}"
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

DEPLOY_HOOK_SCRIPT="${CERTBOT_DEPLOY_HOOK_SCRIPT:-/opt/certbot-scripts/deploy-hook.sh}"
DEPLOY_HOOK_CMD="${CERTBOT_DEPLOY_HOOK_CMD:-}"
DEPLOY_HOOK_ARGS=""
if [ -n "$DEPLOY_HOOK_CMD" ]; then
  DEPLOY_HOOK_ARGS="--deploy-hook $DEPLOY_HOOK_CMD"
elif [ -x "$DEPLOY_HOOK_SCRIPT" ]; then
  DEPLOY_HOOK_ARGS="--deploy-hook $DEPLOY_HOOK_SCRIPT"
fi

QUIET_FLAG=""
if [ "${CERTBOT_RENEW_QUIET:-1}" != "0" ]; then
  QUIET_FLAG="--quiet"
fi

DRY_RUN_FLAG=""
if [ "${CERTBOT_RENEW_DRY_RUN:-0}" != "0" ]; then
  DRY_RUN_FLAG="--dry-run"
fi

RENEW_ARGS="${CERTBOT_RENEW_ARGS:-}"

echo "INFO: Starting certbot renew loop. Interval: ${RENEW_INTERVAL_SECONDS}s"

while true; do
  echo "INFO: Running certbot renew"
  if certbot renew $CERTBOT_SERVER_ARGS $DEPLOY_HOOK_ARGS $QUIET_FLAG $DRY_RUN_FLAG $RENEW_ARGS; then
    echo "INFO: Certbot renew finished"
  else
    echo "ERROR: Certbot renew failed" >&2
  fi

  echo "INFO: Sleeping for ${RENEW_INTERVAL_SECONDS}s"
  sleep "$RENEW_INTERVAL_SECONDS"
done
