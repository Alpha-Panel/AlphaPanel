FROM n8nio/n8n:latest

USER root

# n8n image'in bazen distroless geldiği için paket yöneticisi olmayabiliyor.
# O yüzden docker-cli'yi "static binary" olarak ekleyeceğiz.

ARG DOCKER_CLI_VERSION=27.5.1
RUN set -eux; \
  arch="$(uname -m)"; \
  case "$arch" in \
    x86_64) docker_arch="x86_64" ;; \
    aarch64|arm64) docker_arch="aarch64" ;; \
    *) echo "Unsupported arch: $arch" && exit 1 ;; \
  esac; \
  mkdir -p /tmp/dockercli; \
  wget -qO- "https://download.docker.com/linux/static/stable/${docker_arch}/docker-${DOCKER_CLI_VERSION}.tgz" \
    | tar -xz -C /tmp/dockercli; \
  mv /tmp/dockercli/docker/docker /usr/local/bin/docker; \
  chmod +x /usr/local/bin/docker; \
  rm -rf /tmp/dockercli

USER node
