# Environment shared by the start of the container and the command "kasmin".
cd /var/www/html
export HOME=/tmp
if [ -z "${APP_KEY:-}" ] && [ -n "${APP_KEY_FILE:-}" ]; then APP_KEY="$(cat "$APP_KEY_FILE")"; fi
if [ -z "${APP_KEY:-}" ] && [ -s storage/app/private/app.key ]; then APP_KEY="$(cat storage/app/private/app.key)"; fi
export APP_KEY="${APP_KEY:-}"
if [ -z "${DB_PASSWORD:-}" ] && [ -n "${DB_PASSWORD_FILE:-}" ]; then DB_PASSWORD="$(cat "$DB_PASSWORD_FILE")"; export DB_PASSWORD; fi
export DB_CONNECTION="${DB_CONNECTION:-sqlite}" SESSION_DRIVER="${SESSION_DRIVER:-file}" CACHE_STORE="${CACHE_STORE:-file}"
if [ "$DB_CONNECTION" = sqlite ]; then export DB_DATABASE="${DB_DATABASE:-/var/www/html/storage/app/private/kasmin.sqlite}"; fi
