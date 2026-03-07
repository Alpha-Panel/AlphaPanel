# syntax=docker/dockerfile:1

FROM dunglas/frankenphp:builder AS builder

# xcaddy
COPY --from=caddy:builder /usr/bin/xcaddy /usr/bin/xcaddy

# git + ca-certs lazım (repo çekeceğiz)
RUN apt-get update && apt-get install -y --no-install-recommends \
      git ca-certificates \
      openssh-client \
      npm \
      vim \
    && rm -rf /var/lib/apt/lists/*

# En güncel FrankenPHP kaynaklarını çek
RUN git clone --depth=1 https://github.com/php/frankenphp.git /src/frankenphp

# Build: Caddy "latest" + FrankenPHP "main" (en güncel uyumlu set)
RUN CGO_ENABLED=1 \
    XCADDY_SETCAP=1 \
    XCADDY_GO_BUILD_FLAGS="-ldflags='-w -s' -tags=nobadger,nomysql,nopgx" \
    CGO_CFLAGS=$(php-config --includes) \
    CGO_LDFLAGS="$(php-config --ldflags) $(php-config --libs)" \
    xcaddy build \
        --output /usr/local/bin/frankenphp \
        --with github.com/dunglas/frankenphp=/src/frankenphp \
        --with github.com/dunglas/frankenphp/caddy=/src/frankenphp/caddy \
        --with github.com/dunglas/caddy-cbrotli \
        --with github.com/dunglas/mercure/caddy \
        --with github.com/dunglas/vulcain/caddy \
        --with github.com/caddy-dns/cloudflare \
        --with github.com/WeidiDeng/caddy-cloudflare-ip \
        --with github.com/Javex/caddy-fail2ban@main \
        --with github.com/lanrat/caddy-dynamic-remoteip \
        --with github.com/corazawaf/coraza-caddy/v2

FROM dunglas/frankenphp

COPY --from=builder /usr/local/bin/frankenphp /usr/local/bin/frankenphp

RUN install-php-extensions \
        pdo_mysql \
        gd \
        intl \
        zip \
        opcache \
        mbstring \
        memcached \
        redis \
        imagick \
        raphf \
        http \
        bcmath \
        mongodb \
        mysqli \
        pcntl \
        sockets \
        xdebug \
        exif \
        ftp \
    && rm -rf /var/lib/apt/lists/*

RUN curl -sS https://getcomposer.org/installer | php -- \
      --install-dir=/usr/local/bin --filename=composer

RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      libgcrypt20 \
      libgnutls30 \
      libnghttp2-14 \
 && rm -rf /var/lib/apt/lists/*

# ---- Supervisor ekle (FrankenPHP başlangıcını bozmadan) ----
RUN apt-get update \
 && apt-get install -y --no-install-recommends supervisor \
 && rm -rf /var/lib/apt/lists/*

# Docker CLI (Docker resmi repo)
RUN apt-get update \
 && apt-get install -y --no-install-recommends ca-certificates curl gnupg \
 && install -m 0755 -d /etc/apt/keyrings \
 && curl -fsSL https://download.docker.com/linux/debian/gpg \
    | gpg --dearmor -o /etc/apt/keyrings/docker.gpg \
 && chmod a+r /etc/apt/keyrings/docker.gpg \
 && echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/debian bookworm stable" \
    > /etc/apt/sources.list.d/docker.list \
 && apt-get update \
 && apt-get install -y --no-install-recommends docker-ce-cli vim npm openssh-client \
 && rm -rf /var/lib/apt/lists/*

RUN mkdir -p /etc/supervisor/conf.d /var/log/supervisor /var/run

RUN cat > /etc/supervisor/supervisord.conf <<'EOF'
[unix_http_server]
file=/var/run/supervisor.sock
chmod=0700

[supervisord]
nodaemon=true
user=root
logfile=/var/log/supervisor/supervisord.log
pidfile=/var/run/supervisord.pid

[rpcinterface:supervisor]
supervisor.rpcinterface_factory = supervisor.rpcinterface:make_main_rpcinterface

[supervisorctl]
serverurl=unix:///var/run/supervisor.sock

[include]
files = /etc/supervisor/conf.d/*.conf
EOF

COPY docker-entrypoint-with-supervisor.sh /usr/local/bin/docker-entrypoint-with-supervisor.sh
RUN chmod +x /usr/local/bin/docker-entrypoint-with-supervisor.sh

ENTRYPOINT ["/usr/local/bin/docker-entrypoint-with-supervisor.sh"]