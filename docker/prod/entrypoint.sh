#!/bin/bash
# Start of the Kasmin container: application key, set-up/upgrade, certificate, scheduler, web server.
set -euo pipefail
cd /var/www/html
log() { echo "[kasmin] $*"; }
as_www() { su -s /bin/bash www-data -c "$*"; }

# One-off commands (docker run … php artisan …) without the set-up.
if [ $# -gt 0 ] && [ "$1" != "kasmin" ]; then exec "$@"; fi

mkdir -p storage/app/private storage/app/public storage/framework/{cache/data,sessions,views} storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

# Application key: from APP_KEY or APP_KEY_FILE, otherwise created once and kept in the storage volume.
if [ -z "${APP_KEY:-}" ] && [ -n "${APP_KEY_FILE:-}" ]; then APP_KEY="$(cat "$APP_KEY_FILE")"; fi
if [ -z "${APP_KEY:-}" ]; then
  key=storage/app/private/app.key
  if [ ! -s "$key" ]; then
    (umask 077; echo "base64:$(head -c 32 /dev/urandom | base64 | tr -d '\n')" > "$key")
    log "Created the application key in $key. Keep the storage volume: without this key, encrypted data cannot be read."
  fi
  APP_KEY="$(cat "$key")"
fi
export APP_KEY
if [ -z "${DB_PASSWORD:-}" ] && [ -n "${DB_PASSWORD_FILE:-}" ]; then DB_PASSWORD="$(cat "$DB_PASSWORD_FILE")"; export DB_PASSWORD; fi
# Additional certificate authorities (e.g. of an internal mail server), mounted to /usr/local/share/ca-certificates.
if ls /usr/local/share/ca-certificates/*.crt >/dev/null 2>&1; then update-ca-certificates >/dev/null 2>&1 || log "WARNING: a CA certificate could not be added."; fi
export HOME=/tmp
export SESSION_DRIVER="${SESSION_DRIVER:-file}" CACHE_STORE="${CACHE_STORE:-file}"

# Wait for the database server (not for SQLite).
if [ "${DB_CONNECTION:-sqlite}" != sqlite ]; then
  for i in $(seq 1 60); do
    php -r 'try { new PDO("mysql:host=".getenv("DB_HOST").";port=".(getenv("DB_PORT") ?: 3306).";dbname=".getenv("DB_DATABASE"), getenv("DB_USERNAME"), (string) getenv("DB_PASSWORD")); } catch (Throwable $e) { exit(1); }' && break
    [ "$i" = 60 ] && { log "ERROR: the database is not reachable."; exit 1; }
    [ "$i" = 1 ] && log "Waiting for the database …"
    sleep 2
  done
else
  db="${DB_DATABASE:-/var/www/html/storage/app/private/kasmin.sqlite}"; export DB_CONNECTION=sqlite DB_DATABASE="$db"
  [ -e "$db" ] || { touch "$db"; chown www-data:www-data "$db"; }
fi

as_www "php artisan kasmin:container:setup" || { log "ERROR: the set-up failed, the container stops."; exit 1; }
KASMIN_URL="$(as_www "php artisan kasmin:container:setup --public-url" | tail -1)"
export KASMIN_URL
[ -n "$KASMIN_URL" ] && export APP_URL="$KASMIN_URL"

. /usr/local/lib/kasmin/tls.sh
tls_setup

if [ "${KASMIN_SCHEDULER:-true}" = true ]; then
  # Mails, reminders and automations.
  ( while true; do as_www "php artisan schedule:run" > /dev/null 2>&1 || log "WARNING: the scheduler failed."; sleep $((60 - $(date +%s) % 60)); done ) &
fi

log "Kasmin $(cat VERSION) is starting (TLS: $TLS_MODE)."
exec httpd -D FOREGROUND
