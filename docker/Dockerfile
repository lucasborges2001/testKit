# TestKit Runner: PHP + Xdebug + Node (pinned)

ARG NODE_VERSION=20
FROM node:${NODE_VERSION}-bookworm-slim AS node

ARG PHP_VERSION=8.4
FROM php:${PHP_VERSION}-cli-bookworm

# --- system deps
RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip zip curl ca-certificates \
    default-mysql-client postgresql-client \
    libzip-dev libicu-dev libpq-dev \
  && docker-php-ext-install intl zip pdo pdo_mysql pdo_pgsql \
  && pecl install xdebug \
  && docker-php-ext-enable xdebug \
  && rm -rf /var/lib/apt/lists/*

# --- Node (copiado desde imagen oficial para tener versión fija)
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/bin/npm  /usr/local/bin/npm
COPY --from=node /usr/local/bin/npx  /usr/local/bin/npx
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
ENV NODE_PATH=/usr/local/lib/node_modules

# --- Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

COPY docker/php.ini /usr/local/etc/php/conf.d/99-testkit.ini
COPY docker/xdebug.ini /usr/local/etc/php/conf.d/99-xdebug.ini

WORKDIR /app
