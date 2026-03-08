#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CONFIG_FILE="${ROOT_DIR}/crowdsec/bouncers/crowdsec-firewall-bouncer.yaml"
ENV_FILE="${ROOT_DIR}/.env"

if [[ ! -f "${ENV_FILE}" ]]; then
  echo "ERROR: .env dosyasi bulunamadi: ${ENV_FILE}" >&2
  exit 1
fi

if [[ ! -f "${CONFIG_FILE}" ]]; then
  echo "ERROR: bouncer config bulunamadi: ${CONFIG_FILE}" >&2
  exit 1
fi

KEY="$(grep -E '^CROWDSEC_FIREWALL_BOUNCER_KEY=' "${ENV_FILE}" | head -n1 | cut -d= -f2- || true)"
if [[ -z "${KEY}" ]]; then
  echo "ERROR: .env icinde CROWDSEC_FIREWALL_BOUNCER_KEY tanimli degil." >&2
  exit 1
fi

if grep -q '^api_key:' "${CONFIG_FILE}"; then
  sed -i "s|^api_key:.*$|api_key: ${KEY}|" "${CONFIG_FILE}"
else
  echo "api_key: ${KEY}" >> "${CONFIG_FILE}"
fi

if command -v apt-get >/dev/null 2>&1; then
  echo "[1/4] CrowdSec repo ve firewall bouncer paketi kuruluyor..."
  sudo apt-get update
  sudo apt-get install -y curl gnupg
  curl -fsSL https://packagecloud.io/crowdsec/crowdsec/gpgkey | sudo gpg --dearmor -o /usr/share/keyrings/crowdsec-archive-keyring.gpg
  echo "deb [signed-by=/usr/share/keyrings/crowdsec-archive-keyring.gpg] https://packagecloud.io/crowdsec/crowdsec/any/ any main" | sudo tee /etc/apt/sources.list.d/crowdsec.list >/dev/null
  sudo apt-get update
  BOUNCER_PKG=""
  if apt-cache show crowdsec-firewall-bouncer-nftables >/dev/null 2>&1; then
    BOUNCER_PKG="crowdsec-firewall-bouncer-nftables"
  elif apt-cache show crowdsec-firewall-bouncer-iptables >/dev/null 2>&1; then
    BOUNCER_PKG="crowdsec-firewall-bouncer-iptables"
  elif apt-cache show crowdsec-firewall-bouncer >/dev/null 2>&1; then
    BOUNCER_PKG="crowdsec-firewall-bouncer"
  fi

  if [[ -z "${BOUNCER_PKG}" ]]; then
    echo "ERROR: Uygun crowdsec firewall bouncer paketi bulunamadi." >&2
    exit 1
  fi

  sudo apt-get install -y "${BOUNCER_PKG}"
else
  echo "ERROR: apt-get bulunamadi. Bu script Debian/Ubuntu icindir." >&2
  exit 1
fi

echo "[2/4] Bouncer config host path'e kopyalaniyor..."
sudo install -d -m 0755 /etc/crowdsec/bouncers
sudo install -m 0640 "${CONFIG_FILE}" /etc/crowdsec/bouncers/crowdsec-firewall-bouncer.yaml

echo "[3/4] nftables moda geciliyor..."
if grep -q '^mode:' /etc/crowdsec/bouncers/crowdsec-firewall-bouncer.yaml; then
  sudo sed -i 's/^mode:.*/mode: nftables/' /etc/crowdsec/bouncers/crowdsec-firewall-bouncer.yaml
else
  echo 'mode: nftables' | sudo tee -a /etc/crowdsec/bouncers/crowdsec-firewall-bouncer.yaml >/dev/null
fi

echo "[4/4] Servis yeniden baslatiliyor..."
sudo systemctl enable crowdsec-firewall-bouncer
sudo systemctl restart crowdsec-firewall-bouncer
sudo systemctl --no-pager --full status crowdsec-firewall-bouncer || true

echo
echo "Tamamlandi. Sonraki kontrol komutlari:"
echo "  docker compose exec crowdsec cscli metrics"
echo "  sudo cscli decisions list"
