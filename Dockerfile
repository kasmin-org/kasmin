# Production image of Kasmin: Apache + PHP, dependencies and built assets included.
ARG PHP_VERSION=8.5

FROM node:24-slim AS assets
WORKDIR /app
COPY package.json package-lock.json vite.config.js ./
COPY resources ./resources
RUN npm ci --ignore-scripts --no-audit --no-fund && npm run build

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --optimize --no-dev --no-scripts

FROM php:${PHP_VERSION}-apache
# Build the PHP extensions, then remove the build packages (headers, compilers) so the image only contains runtime
# libraries; apt-get upgrade pulls the latest security fixes of the base image.
RUN apt-get update \
 && apt-get upgrade -y \
 && savedAptMark="$(apt-mark showmanual)" \
 && apt-get install -y --no-install-recommends libicu-dev libzip-dev libpng-dev libjpeg-dev libfreetype6-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" pdo_mysql intl zip gd bcmath exif \
 && apt-mark auto '.*' > /dev/null && apt-mark manual $savedAptMark > /dev/null \
 && find /usr/local -type f -executable -exec ldd '{}' ';' 2>/dev/null | awk '/=>/ { so = $(NF-1); if (index(so, "/usr/local/") == 1) next; gsub("^/(usr/)?", "", so); print so }' \
    | sort -u | xargs -r -n1 sh -c 'dpkg-query --search "$0" 2>/dev/null || true' | grep -v '^diversion' \
    | cut -d: -f1 | tr ',' '\n' | tr -d ' ' | grep -v '^$' | sort -u | xargs -r apt-mark manual > /dev/null \
 && apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false \
 && rm -rf /var/lib/apt/lists/* \
 && a2enmod rewrite headers \
 && sed -ri 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf \
 && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
WORKDIR /var/www/html
COPY --from=vendor /app /var/www/html
COPY --from=assets /app/public/build /var/www/html/public/build
RUN php artisan package:discover --ansi && chown -R www-data:www-data storage bootstrap/cache
ARG KASMIN_VERSION=dev
ENV KASMIN_VERSION=${KASMIN_VERSION}
LABEL org.opencontainers.image.source="https://github.com/kasmin-org/kasmin" \
      org.opencontainers.image.licenses="AGPL-3.0-only" \
      org.opencontainers.image.title="Kasmin"
EXPOSE 80
