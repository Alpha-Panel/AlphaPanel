# Certbot image with Cloudflare DNS plugin for automated issuance/renewal
FROM certbot/dns-cloudflare:latest

# Provide timezone data so the container respects TZ env vars and logs correctly
RUN apk add --no-cache tzdata

WORKDIR /opt/certbot
