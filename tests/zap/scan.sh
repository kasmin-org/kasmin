#!/bin/sh
# Dynamic security scan with OWASP ZAP (baseline scan: spider and passive rules, no attacks), used by the CI job "dast".
# Usage: tests/zap/scan.sh <address of a running demo instance> <folder for the reports>
# 1. public pages without a login, 2. administration and portal with the session of the administrator.
# ARTISAN is the command that reaches the instance (default "php artisan"), ZAP_IMAGE the docker image.
set -eu
B=${1:?address of the instance, e.g. http://127.0.0.1:8000}
OUT=${2:?folder for the reports}
ARTISAN=${ARTISAN:-php artisan}
IMAGE=${ZAP_IMAGE:-ghcr.io/zaproxy/zaproxy:stable}
HERE=$(cd "$(dirname "$0")" && pwd)
mkdir -p "$OUT" && chmod 777 "$OUT" && OUT=$(cd "$OUT" && pwd)
cp "$HERE/rules.tsv" "$HERE/hook.py" "$OUT/"

# shellcheck disable=SC2086
SEEDS=$($ARTISAN tinker --execute='require base_path("tests/zap/prepare.php");' | grep '^/' | tr '\n' ' ')
[ -n "$SEEDS" ] || { echo "FAIL: no public pages prepared"; exit 1; }

# The administrator logs in with a one-time link, like in tests/upgrade-web.sh.
J=$(mktemp)
# shellcheck disable=SC2086
LINK=$($ARTISAN kasmin:login-link admin@example.org | tail -1)
T=$(curl -s -c "$J" -b "$J" "$LINK" | grep -o 'name="_token" value="[^"]*"' | head -1 | cut -d'"' -f4)
curl -s -o /dev/null -c "$J" -b "$J" --data-urlencode "_token=$T" "$LINK"
SESSION=$(awk '$6 ~ /session$/ {print $6"="$7}' "$J")
[ -n "$SESSION" ] || { echo "FAIL: no session cookie after the login"; exit 1; }
curl -s -H "Cookie: $SESSION" "$B/admin" | grep -q '/admin/logout' || { echo "FAIL: the login with the link did not work"; exit 1; }

zap() { # name, target, minutes for each start address of the spider, start paths, cookie, passive rules that do not run
    echo "== ZAP baseline scan: $1 =="
    # -I: the exit status does not depend on warnings; check.py decides by risk and by rules.tsv.
    docker run --rm --network host -v "$OUT:/zap/wrk:rw" -e ZAP_SEEDS="$4" -e ZAP_COOKIE="$5" -e ZAP_MARKER=/admin/logout -e ZAP_SKIP="$6" "$IMAGE" \
        zap-baseline.py -t "$2" -m "$3" -T 15 -c rules.tsv --hook=hook.py -I -s -r "$1.html" -J "$1.json" || { echo "FAIL: the scan '$1' did not finish (status $?)"; exit 1; }
    mv "$OUT/urls.txt" "$OUT/$1-urls.txt"
}
zap public "$B" 2 "$SEEDS" "" ""
# Rule 10099 (source code disclosure) needs minutes for one page of the admin interface (up to 600 kB of HTML with the
# state of the forms) and does not finish on some; it runs on the public pages only.
zap authenticated "$B/admin" 3 "/admin /portal /portal/profile" "$SESSION" 10099
rm -f "$OUT/hook.py" "$OUT/zap.yaml"
python3 "$HERE/check.py" "$HERE/rules.tsv" "$OUT/public.json" "$OUT/authenticated.json"
