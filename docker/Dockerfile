# syntax=docker/dockerfile:1

ARG PHP_VERSION=8.5
ARG FRANKENPHP_VERSION=1
ARG COMPOSER_VERSION=2

# -------- composer image (just to grab the binary) --------
FROM composer:${COMPOSER_VERSION} AS composer_bin

# -------- base FrankenPHP image (shared between stages) --------
FROM dunglas/frankenphp:${FRANKENPHP_VERSION}-php${PHP_VERSION}-bookworm AS base

ARG APCU_VERSION=5.1.24

WORKDIR /app

RUN install-php-extensions \
    intl \
    opcache \
    pdo_pgsql \
    zip \
    gd \
    apcu-${APCU_VERSION} \
  && rm -rf /var/cache/apt/* /var/lib/apt/lists/*

COPY --from=composer_bin /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1 \
    APP_ENV=prod \
    APP_DEBUG=0 \
    PHP_INI_SCAN_DIR="$PHP_INI_DIR/conf.d"

COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-orun.ini"
COPY docker/Caddyfile /etc/caddy/Caddyfile

# FrankenPHP ≥ 1.4 runs as the non-root `app` user (UID 33). Caddy still
# needs to provision its local PKI under /data/caddy/pki at boot — even
# when site-level HTTPS is off, the admin endpoint requires it. Pre-create
# the dirs with loose perms so the named volumes mounted on top inherit
# writable bits (Docker copies perms from the image's empty dir on first
# mount). Same logic for /config.
RUN mkdir -p /data/caddy /config/caddy \
    && chmod -R 0777 /data /config

# -------- vendor stage (cached layer) --------
FROM base AS vendor

COPY composer.json composer.lock symfony.lock ./
# `--mount=type=secret,id=composer_auth` injects ~/.composer/auth.json
# (containing a GitHub OAuth token) at build time without persisting it
# in the final image — required to dodge the anonymous codeload rate limit
# that surfaces as "HTTP/2 400" errors on legacy.zip downloads.
# Falls back to anonymous if the secret isn't provided (still works for
# small / lucky builds), and retries twice on the transient network blips.
RUN --mount=type=cache,target=/root/.composer/cache \
    --mount=type=secret,id=composer_auth,target=/root/.composer/auth.json,required=false \
    composer install \
      --no-dev \
      --no-scripts \
      --no-autoloader \
      --prefer-dist \
      --classmap-authoritative \
    || composer install \
      --no-dev --no-scripts --no-autoloader --prefer-dist --classmap-authoritative \
    || composer install \
      --no-dev --no-scripts --no-autoloader --prefer-dist --classmap-authoritative

# -------- final production image --------
FROM base AS prod

COPY --from=vendor /app/vendor ./vendor
COPY . .

RUN --mount=type=cache,target=/root/.composer/cache \
    composer dump-autoload --classmap-authoritative --no-dev \
  && composer run-script post-install-cmd --no-dev

RUN mkdir -p var/cache var/log \
  && chown -R www-data:www-data var \
  && chmod -R u+rwX,g+rwX var

EXPOSE 80 443 443/udp

HEALTHCHECK --interval=10s --timeout=3s --start-period=20s --retries=3 \
  CMD curl -fsS http://localhost:2019/metrics > /dev/null || exit 1

USER www-data
