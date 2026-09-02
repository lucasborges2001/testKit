# test/docker/Dockerfile
# TestKit Runner: PHP + Xdebug + Node (pinned)

ARG NODE_VERSION=24
ARG PHP_VERSION=8.4
ARG PLAYWRIGHT_VERSION=1.61.0

FROM node:${NODE_VERSION}-bookworm-slim AS node
FROM php:${PHP_VERSION}-cli-bookworm AS core

# --- system deps
RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip zip curl ca-certificates \
    default-mysql-client postgresql-client \
    libzip-dev libicu-dev libpq-dev \
    python3 python3-pip python3-venv \
  && docker-php-ext-install intl zip mysqli pdo pdo_mysql pdo_pgsql \
  && pecl install xdebug \
  && docker-php-ext-enable xdebug \
  && rm -rf /var/lib/apt/lists/*

# Composer belongs to the PHP runner.
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# PHPMailer must be installed against the official PHP runtime, not Debian's
# distro PHP packages (libphp-phpmailer pulls php-common, which is incompatible
# with this image contract). Keep the historical autoload path as a compatibility
# bridge for host projects while exposing the canonical TestKit autoload path.
RUN mkdir -p /opt/testkit \
  && composer require \
      --working-dir=/opt/testkit \
      --no-interaction \
      --prefer-dist \
      --no-progress \
      --no-plugins \
      --no-scripts \
      phpmailer/phpmailer:6.10.0 \
  && mkdir -p /usr/share/php/PHPMailer \
  && ln -sf /opt/testkit/vendor/autoload.php /usr/share/php/PHPMailer/autoload.php
ENV TESTKIT_PHPMAILER_AUTOLOAD=/opt/testkit/vendor/autoload.php

COPY docker/php.ini /usr/local/etc/php/conf.d/99-testkit.ini
COPY docker/xdebug.ini /usr/local/etc/php/conf.d/99-xdebug.ini

WORKDIR /app

FROM core AS browser

# Node and Chromium belong only to browser suites.
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
ENV NODE_PATH=/usr/local/lib/node_modules
ENV PLAYWRIGHT_BROWSERS_PATH=/ms-playwright

RUN ln -sf ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
  && ln -sf ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx \
  && npm install -g @playwright/test@${PLAYWRIGHT_VERSION} \
  && npx playwright install --with-deps chromium \
  && chmod -R a+rX /ms-playwright

WORKDIR /app
