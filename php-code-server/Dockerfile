FROM ubuntu:24.04

ENV PATH="/opt/venv/bin:$PATH"

# 1) Tüm kurulumları interaktif olmayan moda al, timezone'u ayarla
ENV DEBIAN_FRONTEND=noninteractive
ENV TZ=Europe/Istanbul

RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      tzdata \
      software-properties-common \
      ca-certificates \
      apt-transport-https \
      gnupg \
      dirmngr \
      curl \
      unzip \
      supervisor \
      pkg-config \
      curl \
      wget \
      apache2 \
      apache2-utils \
      ssl-cert \
      vim \
      e2fsprogs \
 && add-apt-repository ppa:ondrej/php -y \
 && apt-get update

# 2) PHP 8.0–8.4 ve modüller (özet)
RUN apt-get install -y --no-install-recommends \
      php8.0-fpm php8.0-cli php8.0-common php8.0-zip php8.0-mysql php8.0-mbstring php8.0-tokenizer php8.0-xml php8.0-gd php8.0-curl php8.0-intl php8.0-bcmath php8.0-gmp php8.0-memcached php8.0-redis php8.0-imagick php8.0-mongodb php8.0-raphf php8.0-http php8.0-ftp \
      php8.1-fpm php8.1-cli php8.1-common php8.1-zip php8.1-mysql php8.1-mbstring php8.1-tokenizer php8.1-xml php8.1-gd php8.1-curl php8.1-intl php8.1-bcmath php8.1-gmp php8.1-memcached php8.1-redis php8.1-imagick php8.1-mongodb php8.1-raphf php8.1-http php8.1-ftp \
      php8.2-fpm php8.2-cli php8.2-common php8.2-zip php8.2-mysql php8.2-mbstring php8.2-tokenizer php8.2-xml php8.2-gd php8.2-curl php8.2-intl php8.2-bcmath php8.2-gmp php8.2-memcached php8.2-redis php8.2-imagick php8.2-mongodb php8.2-raphf php8.2-http php8.2-ftp \
      php8.3-fpm php8.3-cli php8.3-common php8.3-zip php8.3-mysql php8.3-mbstring php8.3-tokenizer php8.3-xml php8.3-gd php8.3-curl php8.3-intl php8.3-bcmath php8.3-gmp php8.3-memcached php8.3-redis php8.3-imagick php8.3-mongodb php8.3-raphf php8.3-http php8.3-ftp \
      php8.4-fpm php8.4-cli php8.4-common php8.4-zip php8.4-mysql php8.4-mbstring php8.4-tokenizer php8.4-xml php8.4-gd php8.4-curl php8.4-intl php8.4-bcmath php8.4-gmp php8.4-memcached php8.4-redis php8.4-imagick php8.4-mongodb php8.4-raphf php8.4-http php8.4-ftp \
      php8.5-fpm php8.5-cli php8.4-common php8.5-zip php8.5-mysql php8.5-mbstring php8.5-tokenizer php8.5-xml php8.5-gd php8.5-curl php8.5-intl php8.5-bcmath php8.5-gmp php8.5-memcached php8.5-redis php8.5-imagick php8.5-mongodb php8.5-raphf php8.5-http php8.5-ftp \
    python3 python3-pip \
    mysql-client \
    rsync \
    && rm -rf /var/lib/apt/lists/*

# 3) Supervisor konfigürasyonu ve Composer
COPY supervisor.d/php-fpm.conf /etc/supervisor/conf.d/php-fpm.conf
RUN curl -sS https://getcomposer.org/installer | php -- \
      --install-dir=/usr/local/bin --filename=composer

# Provide 'python' alias for python3
RUN ln -sf /usr/bin/python3 /usr/bin/python

# Install Go from official binary
ARG GO_VERSION=1.24.4
RUN curl -fsSL https://go.dev/dl/go${GO_VERSION}.linux-amd64.tar.gz | tar -C /usr/local -xz \
    && ln -s /usr/local/go/bin/go /usr/local/bin/go \
    && ln -s /usr/local/go/bin/gofmt /usr/local/bin/gofmt

ENV PATH=$PATH:/usr/local/go/bin


RUN curl -fsSL https://deb.nodesource.com/setup_24.x | bash - \
 && apt-get install -y nodejs


# Install code-server
ARG CODE_SERVER_VERSION=4.108.1
RUN curl -fsSL https://github.com/coder/code-server/releases/download/v${CODE_SERVER_VERSION}/code-server_${CODE_SERVER_VERSION}_amd64.deb -o code-server.deb \
 && dpkg -i code-server.deb \
 && rm code-server.deb

# --- suppress Supervisor root warning by specifying a user ---
RUN sed -i '/^\[supervisord\]/a user=root' /etc/supervisor/supervisord.conf

ENV APACHE_RUN_DIR=/run/apache2 \
    APACHE_LOCK_DIR=/var/lock/apache2 \
    APACHE_LOG_DIR=/var/log/apache2 \
    APACHE_PID_FILE=/run/apache2/apache2.pid

# --- mongosh (MongoDB Shell) via official .deb (GitHub release) ---
ARG MONGOSH_VER=2.5.10
RUN set -eux; \
    arch="$(dpkg --print-architecture)"; \
    case "$arch" in \
      amd64) deb_arch="amd64" ;; \
      arm64) deb_arch="arm64" ;; \
      *) echo "Unsupported arch for mongosh: $arch" && exit 1 ;; \
    esac; \
    url="https://github.com/mongodb-js/mongosh/releases/download/v${MONGOSH_VER}/mongodb-mongosh_${MONGOSH_VER}_${deb_arch}.deb"; \
    echo "Downloading: $url"; \
    curl -fL "$url" -o /tmp/mongosh.deb; \
    apt-get update; \
    apt-get install -y --no-install-recommends /tmp/mongosh.deb; \
    rm -f /tmp/mongosh.deb; \
    rm -rf /var/lib/apt/lists/*; \
    mongosh --version


# MongoDB Database Tools (mongodump/mongorestore) - OS codename'e göre indir
ARG MONGO_TOOLS_VER=100.10.0
RUN set -eux; \
    . /etc/os-release; \
    arch="$(dpkg --print-architecture)"; \
    case "$arch" in \
      amd64) mongo_arch="x86_64" ;; \
      arm64) mongo_arch="arm64" ;; \
      *) echo "Unsupported arch: $arch" && exit 1 ;; \
    esac; \
    # Ubuntu codename -> tools paketi
    case "${VERSION_CODENAME:-}" in \
      jammy) tool_os="ubuntu2204" ;; \
      noble) tool_os="ubuntu2404" ;; \
      *) tool_os="ubuntu2204" ;; \
    esac; \
    url="https://fastdl.mongodb.org/tools/db/mongodb-database-tools-${tool_os}-${mongo_arch}-${MONGO_TOOLS_VER}.tgz"; \
    echo "Downloading: $url"; \
    curl -fsSL "$url" -o /tmp/mongo-tools.tgz; \
    tar -xzf /tmp/mongo-tools.tgz -C /tmp; \
    cp /tmp/mongodb-database-tools-*/bin/mongodump /usr/local/bin/; \
    cp /tmp/mongodb-database-tools-*/bin/mongorestore /usr/local/bin/; \
    chmod +x /usr/local/bin/mongodump /usr/local/bin/mongorestore; \
    rm -rf /tmp/mongo-tools.tgz /tmp/mongodb-database-tools-*; \
    # Fail-fast test \
    mongosh --version; \
    mongodump --version; \
    mongorestore --version


COPY requirements.txt /tmp/requirements.txt
RUN pip3 install --no-cache-dir --break-system-packages -r /tmp/requirements.txt


# Apache runtime dirs (systemd yok, elle oluştur)
RUN mkdir -p /run/apache2 /var/lock/apache2 /var/log/apache2 \
 && chown -R root:root /run/apache2 /var/lock/apache2 \
 && chmod 755 /run/apache2 /var/lock/apache2

RUN a2enmod ssl socache_shmcb proxy proxy_fcgi setenvif headers remoteip rewrite

COPY user-entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/vhosts
EXPOSE 9000 80 443 8080
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["supervisord", "-n"]
