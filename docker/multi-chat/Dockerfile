# syntax=docker/dockerfile:1
FROM --platform=$BUILDPLATFORM php:8.5.12-cli AS build-base

RUN apt-get update && \
    apt-get install -y --no-install-recommends git unzip libldap-dev libzip-dev && \
    curl -fsSL https://deb.nodesource.com/setup_lts.x | bash - && \
    apt-get install -y --no-install-recommends nodejs && \
    npm install -g pnpm@10.30.3 --no-audit --no-fund && \
    docker-php-ext-install ldap zip && \
    curl -sS https://getcomposer.org/installer | php -- \
        --install-dir=/usr/local/bin --filename=composer

WORKDIR /app
ENV HTTP_PROXY_REQUEST_FULLURI=0
ENV HTTPS_PROXY_REQUEST_FULLURI=0
ENV COMPOSER_ALLOW_SUPERUSER=1
ENV CI=true

COPY src/multi-chat/composer.json src/multi-chat/composer.lock ./
RUN --mount=type=cache,target=/tmp/composer/cache \
    composer install --no-dev --no-scripts --no-autoloader --prefer-dist

COPY src/multi-chat/package.json src/multi-chat/pnpm-lock.yaml ./
RUN --mount=type=cache,target=/root/.local/share/pnpm/store \
    pnpm install --frozen-lockfile

FROM build-base AS build
COPY src/multi-chat /app
RUN --mount=type=cache,target=/tmp/composer/cache \
    mkdir -p storage/framework/views storage/framework/cache storage/framework/sessions \
        storage/logs bootstrap/cache && \
    composer install --no-interaction --optimize-autoloader --no-dev && \
    php artisan clear-compiled && \
    composer dump-autoload --optimize && \
    pnpm install --frozen-lockfile && \
    pnpm run build

WORKDIR /app/public
RUN rm -f storage && ln -s ../storage/app/public storage

WORKDIR /app
RUN rm -rf node_modules storage && \
    mkdir -p storage

#============================================
FROM php:8.5.12-fpm
ARG PHPREDIS_VERSION=6.3.0
ARG PHPREDIS_SHA256=cb8f81df1a275599e4f8ddcfec7e1f65ed1953e6f5673649149fd680ebff4cad

# Align the UID and PID with Nginx
RUN usermod -u 101 www-data &&\
    groupmod -g 101 www-data

# Configure PHP
COPY docker/multi-chat/conf/fpm-app-gateway-pool.conf /usr/local/etc/php-fpm.d/app-gateway-pool.conf
COPY docker/multi-chat/conf/php-override.ini ${PHP_INI_DIR}/conf.d/00-override.ini
RUN mv "$PHP_INI_DIR/php.ini-production" "${PHP_INI_DIR}/php.ini"

# Install required PHP extensions
RUN apt-get update && \
    apt-get install -y --no-install-recommends \
        libldap-dev libzip-dev libcurl4-openssl-dev libonig-dev \
        libxml2-dev libpq-dev libsqlite3-dev libpng-dev \
        procps netcat-openbsd wget \
        linux-libc-dev apt zlib1g zlib1g-dev && \
    apt-get install -y --only-upgrade zlib1g libpq5 linux-libc-dev && \
    docker-php-ext-install curl mbstring xml zip ldap mysqli pdo_mysql pgsql pdo_pgsql pdo_sqlite gd && \
    curl -fsSL --retry 3 -o /tmp/phpredis.tar.gz \
        "https://github.com/phpredis/phpredis/archive/refs/tags/${PHPREDIS_VERSION}.tar.gz" && \
    echo "${PHPREDIS_SHA256}  /tmp/phpredis.tar.gz" | sha256sum -c - && \
    docker-php-source extract && \
    mkdir -p /usr/src/php/ext/redis && \
    tar -xzf /tmp/phpredis.tar.gz -C /usr/src/php/ext/redis --strip-components=1 && \
    docker-php-ext-install redis && \
    docker-php-source delete && \
    rm -f /tmp/phpredis.tar.gz && \
    wget -O /usr/local/bin/php-fpm-healthcheck \
      https://raw.githubusercontent.com/renatomefi/php-fpm-healthcheck/master/php-fpm-healthcheck &&\
    chmod +x /usr/local/bin/php-fpm-healthcheck && \
    apt-get purge -y \
        libldap-dev libzip-dev libcurl4-openssl-dev libonig-dev \
        libxml2-dev libpq-dev libsqlite3-dev libpng-dev zlib1g-dev && \
    rm -rf /var/lib/apt/lists/*

COPY --from=build /app /app
COPY docker/multi-chat/conf/app.env /app/.env
COPY docker/multi-chat/src/*.php /app/app/Console/

# Copy the scripts
COPY docker/multi-chat/bin/* /usr/local/bin/
WORKDIR /usr/local/bin
RUN chmod +x docker-entrypoint multi-chat import-bots prune-executor upgrade

# Copy the bootstrap bots
ENV KUWA_ROOT="/var/kuwa/docker/root"
ENV KUWA_BOT_DIR="${KUWA_ROOT}/bootstrap/bot"
VOLUME ${KUWA_ROOT}
COPY src/bot/init/*.bot ${KUWA_BOT_DIR}

# Setup the entry point
ENV KUWA_MULTI_CHAT_VERSION="0.4.1-docker1"

ENV no_proxy="kernel,web,localhost,127.0.0.0/8,::1,${no_proxy}"
ENV NO_PROXY="kernel,web,localhost,127.0.0.0/8,::1,${NO_PROXY}"
ENV KUWA_KERNEL_URL="http://kernel:9000"
ENV APP_URL="http://localhost/"
ENV APP_ENV="production"
ENV APP_DEBUG="false"
ENV KUWA_API_BASE_URLS="http://web/v1.0/;http://localhost/"

ENV DB_CONNECTION="pgsql"
ENV DB_HOST="db"
ENV DB_PORT="5432"

ENV LOGIN_WITH_EMAIL="true"
ENV INFORMATION_URL="https://kuwaai.tw/"
ENV ALLOWED_IPS="0.0.0.0/0"
ENV DEFAULT_LOCALE="en_us"
ENV FALLBACK_LOCALE="en_us"

ENV ADMIN_NAME="Kuwa Admin"
ENV ADMIN_EMAIL="admin@localhost"
ENV ADMIN_PASSWORD=""
ENV ADMIN_PASSWORD_FILE=""

WORKDIR /app
ENTRYPOINT ["docker-entrypoint"]
CMD ["multi-chat", "--num-worker", "10", "--executor-warm-up-time", "30"]
