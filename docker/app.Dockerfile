# PHP-FPM untuk Laravel. Image yang sama dipakai service app, worker, dan scheduler.
FROM php:8.4-fpm-alpine AS base

RUN apk add --no-cache icu-libs libzip \
 && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libzip-dev linux-headers \
 && docker-php-ext-install -j"$(nproc)" pdo_mysql bcmath intl zip pcntl opcache \
 && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /var/www/api

# ---- dev: kode di-mount dari host (lihat docker-compose.yml) ----
FROM base AS dev
ENV APP_ENV=local

# ---- prod: kode disalin ke image ----
FROM base AS prod
ENV APP_ENV=production
COPY apps/api/composer.json apps/api/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY apps/api/ ./
RUN composer dump-autoload --optimize --no-dev \
 && chown -R www-data:www-data storage bootstrap/cache
USER www-data
