#!/bin/bash
# kasmin <command> …: an artisan command with the environment of the container, as the user of the web server,
# e.g. docker exec kasmin kasmin kasmin:status
set -euo pipefail
. /usr/local/lib/kasmin/env.sh
[ -n "$APP_KEY" ] || { echo "No application key yet: the container has not been set up."; exit 1; }
if [ "$(id -u)" = 0 ]; then exec su -s /bin/bash www-data -c 'export HOME=/tmp; exec php artisan "$@"' -- kasmin "$@"; fi
exec php artisan "$@"
