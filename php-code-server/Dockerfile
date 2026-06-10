FROM debian:bookworm

ENV DEBIAN_FRONTEND=noninteractive
ENV TZ=Europe/Istanbul

# apt retry + sury.org PHP repo (launchpad dependency yok)
RUN echo 'Acquire::Retries "5";' > /etc/apt/apt.conf.d/80-retries \
 && apt-get update \
 && apt-get install -y --no-install-recommends \
      tzdata \
      ca-certificates \
      apt-transport-https \
      gnupg \
      dirmngr \
      curl \
      unzip \
      supervisor \
      pkg-config \
      wget \
      apache2 \
      apache2-utils \
      ssl-cert \
      vim \
      e2fsprogs \
      lsb-release \
      ghostscript \
 && curl -fsSL https://packages.sury.org/php/apt.gpg \
    | gpg --dearmor -o /usr/share/keyrings/sury-php.gpg \
 && echo "deb [signed-by=/usr/share/keyrings/sury-php.gpg] https://packages.sury.org/php/ bookworm main" \
    > /etc/apt/sources.list.d/sury-php.list \
 && apt-get update \
 && rm -rf /var/lib/apt/lists/*

# 2) PHP 7.0–7.4 + core extensions
RUN apt-get update && apt-get install -y --no-install-recommends \
      php7.0-fpm php7.0-cli php7.0-common php7.0-json php7.0-zip php7.0-mysql php7.0-mbstring php7.0-tokenizer php7.0-xml php7.0-gd php7.0-curl php7.0-intl php7.0-bcmath php7.0-gmp php7.0-memcached php7.0-redis php7.0-imagick \
      php7.1-fpm php7.1-cli php7.1-common php7.1-json php7.1-zip php7.1-mysql php7.1-mbstring php7.1-tokenizer php7.1-xml php7.1-gd php7.1-curl php7.1-intl php7.1-bcmath php7.1-gmp php7.1-memcached php7.1-redis php7.1-imagick \
      php7.2-fpm php7.2-cli php7.2-common php7.2-json php7.2-zip php7.2-mysql php7.2-mbstring php7.2-tokenizer php7.2-xml php7.2-gd php7.2-curl php7.2-intl php7.2-bcmath php7.2-gmp php7.2-memcached php7.2-redis php7.2-imagick \
      php7.3-fpm php7.3-cli php7.3-common php7.3-json php7.3-zip php7.3-mysql php7.3-mbstring php7.3-tokenizer php7.3-xml php7.3-gd php7.3-curl php7.3-intl php7.3-bcmath php7.3-gmp php7.3-memcached php7.3-redis php7.3-imagick \
      php7.4-fpm php7.4-cli php7.4-common php7.4-zip php7.4-mysql php7.4-mbstring php7.4-tokenizer php7.4-xml php7.4-gd php7.4-curl php7.4-intl php7.4-bcmath php7.4-gmp php7.4-memcached php7.4-redis php7.4-imagick \
    && rm -rf /var/lib/apt/lists/*

# Optional 7.x extensions (some may not exist for all versions — tolerate failure)
RUN apt-get update && apt-get install -y --no-install-recommends \
      php7.0-mongodb php7.0-raphf php7.0-http php7.0-ftp \
      php7.1-mongodb php7.1-raphf php7.1-http php7.1-ftp \
      php7.2-mongodb php7.2-raphf php7.2-http php7.2-ftp \
      php7.3-mongodb php7.3-raphf php7.3-http php7.3-ftp \
      php7.4-mongodb php7.4-raphf php7.4-http php7.4-ftp \
    ; rm -rf /var/lib/apt/lists/*

# 3) PHP 8.0–8.5
RUN apt-get update && apt-get install -y --no-install-recommends \
      php8.0-fpm php8.0-cli php8.0-common php8.0-zip php8.0-mysql php8.0-mbstring php8.0-tokenizer php8.0-xml php8.0-gd php8.0-curl php8.0-intl php8.0-bcmath php8.0-gmp php8.0-memcached php8.0-redis php8.0-imagick php8.0-mongodb php8.0-raphf php8.0-http php8.0-ftp \
      php8.1-fpm php8.1-cli php8.1-common php8.1-zip php8.1-mysql php8.1-mbstring php8.1-tokenizer php8.1-xml php8.1-gd php8.1-curl php8.1-intl php8.1-bcmath php8.1-gmp php8.1-memcached php8.1-redis php8.1-imagick php8.1-mongodb php8.1-raphf php8.1-http php8.1-ftp \
      php8.2-fpm php8.2-cli php8.2-common php8.2-zip php8.2-mysql php8.2-mbstring php8.2-tokenizer php8.2-xml php8.2-gd php8.2-curl php8.2-intl php8.2-bcmath php8.2-gmp php8.2-memcached php8.2-redis php8.2-imagick php8.2-mongodb php8.2-raphf php8.2-http php8.2-ftp \
      php8.3-fpm php8.3-cli php8.3-common php8.3-zip php8.3-mysql php8.3-mbstring php8.3-tokenizer php8.3-xml php8.3-gd php8.3-curl php8.3-intl php8.3-bcmath php8.3-gmp php8.3-memcached php8.3-redis php8.3-imagick php8.3-mongodb php8.3-raphf php8.3-http php8.3-ftp \
      php8.4-fpm php8.4-cli php8.4-common php8.4-zip php8.4-mysql php8.4-mbstring php8.4-tokenizer php8.4-xml php8.4-gd php8.4-curl php8.4-intl php8.4-bcmath php8.4-gmp php8.4-memcached php8.4-redis php8.4-imagick php8.4-mongodb php8.4-raphf php8.4-http php8.4-ftp \
      php8.5-fpm php8.5-cli php8.5-common php8.5-zip php8.5-mysql php8.5-mbstring php8.5-tokenizer php8.5-xml php8.5-gd php8.5-curl php8.5-intl php8.5-bcmath php8.5-gmp php8.5-memcached php8.5-redis php8.5-imagick php8.5-mongodb php8.5-raphf php8.5-http php8.5-ftp \
    python3 \
    default-mysql-client \
    rsync \
    && rm -rf /var/lib/apt/lists/*

# Supervisor konfigürasyonu ve Composer
COPY supervisor.d/ /etc/supervisor/conf.d/
COPY --from=composer:2.9.8 /usr/bin/composer /usr/local/bin/composer

RUN ln -sf /usr/bin/python3 /usr/bin/python

# Install Go from official binary
ARG GO_VERSION=1.26.3
RUN curl -fsSL https://go.dev/dl/go${GO_VERSION}.linux-amd64.tar.gz | tar -C /usr/local -xz \
    && ln -s /usr/local/go/bin/go /usr/local/bin/go \
    && ln -s /usr/local/go/bin/gofmt /usr/local/bin/gofmt

ENV PATH=$PATH:/usr/local/go/bin

RUN curl -fsSL https://deb.nodesource.com/setup_24.x | bash - \
 && apt-get install -y nodejs \
 && rm -rf /var/lib/apt/lists/*

# Install code-server
ARG CODE_SERVER_VERSION=4.123.0
RUN curl -fsSL https://github.com/coder/code-server/releases/download/v${CODE_SERVER_VERSION}/code-server_${CODE_SERVER_VERSION}_amd64.deb -o code-server.deb \
 && dpkg -i code-server.deb \
 && rm code-server.deb

RUN sed -i '/^\[supervisord\]/a user=root' /etc/supervisor/supervisord.conf

ENV APACHE_RUN_DIR=/run/apache2 \
    APACHE_LOCK_DIR=/var/lock/apache2 \
    APACHE_LOG_DIR=/var/log/apache2 \
    APACHE_PID_FILE=/run/apache2/apache2.pid

RUN npm install -g @anthropic-ai/claude-code @openai/codex

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
