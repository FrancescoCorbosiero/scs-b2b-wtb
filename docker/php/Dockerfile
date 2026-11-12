# syntax=docker/dockerfile:1
# Immagini di produzione: "app" (php-fpm + scheduler) e "web" (nginx con i file statici).

FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json tailwind.config.js ./
RUN npm ci --no-audit --no-fund
COPY assets ./assets
COPY scripts ./scripts
COPY templates ./templates
COPY src ./src
RUN npm run build

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader --no-scripts --ignore-platform-req=ext-*

FROM php:8.3-fpm-alpine AS app
RUN apk add --no-cache icu-libs libzip libpng libjpeg-turbo libwebp freetype sqlite-libs \
 && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libzip-dev libpng-dev libjpeg-turbo-dev libwebp-dev freetype-dev sqlite-dev \
 && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
 && docker-php-ext-install -j"$(nproc)" intl zip gd pdo_sqlite opcache \
 && apk del .build-deps
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/entrypoint.sh /usr/local/bin/app-entrypoint
WORKDIR /var/www
COPY --chown=www-data:www-data . .
COPY --from=vendor --chown=www-data:www-data /app/vendor ./vendor
COPY --from=assets --chown=www-data:www-data /app/public/assets ./public/assets
RUN mkdir -p var && chown -R www-data:www-data var && chmod +x /usr/local/bin/app-entrypoint
USER www-data
ENTRYPOINT ["app-entrypoint"]
CMD ["php-fpm"]

FROM nginx:1.27-alpine AS web
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY public /var/www/public
COPY --from=assets /app/public/assets /var/www/public/assets
