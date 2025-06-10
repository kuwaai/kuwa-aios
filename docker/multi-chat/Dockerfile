FROM php:8.1-cli AS build

RUN apt-get update

# Install nodejs and composer
RUN curl -fsSL https://deb.nodesource.com/setup_lts.x | bash - &&\
    apt-get install -y nodejs &&\
    echo Version of Node.js: $(node -v) &&\
    echo Version of NPM: $(npm -v)
RUN curl -s https://getcomposer.org/installer | php &&\
    mv composer.phar /usr/local/bin/composer &&\
    echo Version of Composer: $(composer --version)

# Install dependency of PHP extension for building
RUN apt-get install -y libldap-dev libzip-dev &&\
    docker-php-ext-install ldap zip

# Build the multi-chat application
COPY src/multi-chat /app
WORKDIR /app
## Install dependencies using npm and composer
ENV HTTP_PROXY_REQUEST_FULLURI=0
ENV HTTPS_PROXY_REQUEST_FULLURI=0
RUN composer update --no-interaction &&\
    composer install --no-interaction --optimize-autoloader --no-dev &&\
    npm install
## Optimize composer dependency
RUN php artisan clear-compiled &&\
    composer dump-autoload --optimize
## Build the front-end assets
RUN npm run build
## Reconstruct the symbolic link
WORKDIR /app/public
RUN rm -f storage &&\
    ln -s ../storage/app/public storage

# Cleanup
WORKDIR /app
RUN rm -rf node_modules storage &&\
    mkdir -p storage

#============================================
FROM php:8.1-fpm

# Align the UID and PID with Nginx
RUN usermod -u 101 www-data &&\
    groupmod -g 101 www-data

# Configure PHP
COPY docker/multi-chat/conf/fpm-app-gateway-pool.conf /usr/local/etc/php-fpm.d/app-gateway-pool.conf
COPY docker/multi-chat/conf/php-override.ini ${PHP_INI_DIR}/conf.d/00-override.ini
RUN mv "$PHP_INI_DIR/php.ini-production" "${PHP_INI_DIR}/php.ini"

# Install required PHP extensions
RUN apt-get update &&\
    apt-get install -y libldap-dev libzip-dev libcurl4-openssl-dev libonig-dev \
                       libxml2-dev libpq-dev libpng-dev &&\
    docker-php-ext-install curl mbstring xml zip ldap mysqli pdo_mysql pgsql pdo_pgsql gd
RUN pear config-set http_proxy "${https_proxy:-${HTTPS_PROXY}}" &&\
    pecl install redis-6.0.2 &&\
    docker-php-ext-enable redis

# Install tools to check service status
RUN apt-get install -y procps netcat-openbsd wget &&\
    wget -O /usr/local/bin/php-fpm-healthcheck \
      https://raw.githubusercontent.com/renatomefi/php-fpm-healthcheck/master/php-fpm-healthcheck &&\
    chmod +x /usr/local/bin/php-fpm-healthcheck

COPY --from=build /app /app
COPY docker/multi-chat/conf/app.env /app/.env
COPY docker/multi-chat/src/*.php /app/app/Console/

# Copy the scripts
COPY docker/multi-chat/bin/* /usr/local/bin/
WORKDIR /usr/local/bin
RUN chmod +x docker-entrypoint multi-chat import-bots prune-executor upgrade

# Copy the bootstrap bots
ENV SKYSCOPE_ROOT="/var/skyscope/docker/root"
ENV SKYSCOPE_BOT_DIR="${SKYSCOPE_ROOT}/bootstrap/bot"
VOLUME ${SKYSCOPE_ROOT}
COPY src/bot/init/*.bot ${SKYSCOPE_BOT_DIR}

# Setup the entry point
ENV SKYSCOPE_MULTI_CHAT_VERSION="0.4.0-docker1"

ENV no_proxy="kernel,web,localhost,127.0.0.0/8,::1,${no_proxy}"
ENV NO_PROXY="kernel,web,localhost,127.0.0.0/8,::1,${NO_PROXY}"
ENV SKYSCOPE_KERNEL_URL="http://kernel:9000"
ENV APP_URL="http://localhost/"
ENV APP_ENV="production"
ENV APP_DEBUG="false"
ENV SKYSCOPE_API_BASE_URLS="http://web/v1.0/;http://localhost/"

ENV DB_CONNECTION="pgsql"
ENV DB_HOST="db"
ENV DB_PORT="5432"

ENV LOGIN_WITH_EMAIL="true"
ENV INFORMATION_URL="https://skyscope.ai/"
ENV ALLOWED_IPS="0.0.0.0/0"
ENV DEFAULT_LOCALE="en_us"
ENV FALLBACK_LOCALE="en_us"

ENV ADMIN_NAME="Skyscope Admin"
ENV ADMIN_EMAIL="admin@localhost"
ENV ADMIN_PASSWORD=""
ENV ADMIN_PASSWORD_FILE=""

WORKDIR /app
ENTRYPOINT ["docker-entrypoint"]
CMD ["multi-chat", "--num-worker", "10", "--executor-warm-up-time", "30"]
