# Production image of Kasmin: Alpine Linux with Apache + PHP, dependencies and built assets included.

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --optimize --no-dev --no-scripts

FROM node:24-slim AS assets
WORKDIR /app
COPY package.json package-lock.json vite.config.js ./
COPY resources ./resources
# The admin theme builds on Filament's styles and scans our own admin classes.
COPY app ./app
COPY --from=vendor /app/vendor/filament ./vendor/filament
RUN npm ci --ignore-scripts --no-audit --no-fund && npm run build

FROM alpine:3.24
# Small image: Alpine Linux with its PHP 8.5 packages and Apache (mod_php); bash and vim for maintenance.
# apk upgrade pulls all security fixes at build time; --no-cache keeps no package index in the image.
RUN apk upgrade --no-cache \
 && apk add --no-cache bash vim ca-certificates tzdata apache2 php85-apache2 \
    php85 php85-pdo_mysql php85-pdo_sqlite php85-intl php85-zip php85-gd php85-bcmath php85-mbstring \
    php85-xml php85-dom php85-xmlwriter php85-simplexml php85-tokenizer php85-fileinfo php85-curl php85-openssl \
    php85-session php85-ctype php85-iconv php85-phar \
 && ln -sf /usr/bin/php85 /usr/bin/php \
 && mkdir -p /var/www/html /run/apache2 \
 && sed -ri -e 's#^DocumentRoot .*#DocumentRoot "/var/www/html/public"#' \
            -e 's#^<Directory "/var/www/localhost/htdocs">#<Directory "/var/www/html/public">#' \
            -e 's#AllowOverride None#AllowOverride All#' \
            -e 's#^\#(LoadModule rewrite_module .*)#\1#' \
            -e 's#^ErrorLog .*#ErrorLog /dev/stderr#' -e 's#CustomLog .* combined#CustomLog /dev/stdout combined#' \
            -e 's#^User apache#User www-data#' -e 's#^Group apache#Group www-data#' \
            -e 's#^\#ServerName .*#ServerName localhost#' /etc/apache2/httpd.conf \
 && (addgroup -S www-data 2>/dev/null || true) && (adduser -S -G www-data -H -s /sbin/nologin www-data 2>/dev/null || true) \
 && cp /etc/php85/php.ini /etc/php85/php.ini.orig
WORKDIR /var/www/html
COPY --from=vendor /app /var/www/html
COPY --from=assets /app/public/build /var/www/html/public/build
RUN php artisan package:discover --ansi && chown -R www-data:www-data storage bootstrap/cache
ARG KASMIN_VERSION=dev
ENV KASMIN_VERSION=${KASMIN_VERSION} \
    KASMIN_RUNTIME=docker
LABEL org.opencontainers.image.source="https://github.com/kasmin-org/kasmin" \
      org.opencontainers.image.licenses="AGPL-3.0-only" \
      org.opencontainers.image.title="Kasmin"
EXPOSE 80
CMD ["httpd", "-D", "FOREGROUND"]
