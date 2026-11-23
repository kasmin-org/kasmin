# Production image of Kasmin: Ubuntu LTS with Apache + PHP, dependencies and built assets included.

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

FROM ubuntu:26.04
# Ubuntu LTS with its own PHP packages (PHP 8.5 in 26.04; OPcache is built in). dist-upgrade pulls all security fixes at build time;
# apt caches and package lists are removed afterwards to keep the image small.
ENV DEBIAN_FRONTEND=noninteractive
RUN apt-get update \
 && apt-get dist-upgrade -y \
 && apt-get install -y --no-install-recommends ca-certificates apache2 libapache2-mod-php8.5 \
    php8.5-cli php8.5-mysql php8.5-sqlite3 php8.5-intl php8.5-zip php8.5-gd php8.5-bcmath php8.5-mbstring \
    php8.5-xml php8.5-curl \
 && a2enmod rewrite headers \
 && sed -ri 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf \
 && printf '<Directory /var/www/html/public>\n    AllowOverride All\n</Directory>\n' > /etc/apache2/conf-available/kasmin.conf && a2enconf kasmin \
 && ln -sf /dev/stdout /var/log/apache2/access.log && ln -sf /dev/stderr /var/log/apache2/error.log \
 && rm -rf /var/www/html/* \
 && apt-get clean && rm -rf /var/lib/apt/lists/* /var/cache/apt/* /tmp/* /var/tmp/*
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
CMD ["apachectl", "-D", "FOREGROUND"]
