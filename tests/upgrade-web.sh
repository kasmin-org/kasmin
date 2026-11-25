#!/bin/sh
# Used by the CI upgrade job: the files of the new version are installed over an old database. An administrator
# asks for a login link (read from the mail log), logs in and starts the upgrade with the button, like in a browser.
set -eu
B=http://127.0.0.1:8000
J=$(mktemp)
: > storage/logs/laravel.log
php artisan serve --no-reload --host=127.0.0.1 --port=8000 > /tmp/kasmin-serve.log 2>&1 &
SERVER=$!
trap 'kill $SERVER 2>/dev/null || true' EXIT
for i in $(seq 1 30); do curl -sf "$B/up" > /dev/null && break; sleep 1; done
token() { grep -o 'name="_token" value="[^"]*"' | head -1 | cut -d'"' -f4; }
fail() { echo "FAIL: $1"; tail -20 storage/logs/laravel.log | cut -c1-300; exit 1; }

curl -s -L -c "$J" -b "$J" "$B/" > /tmp/start.html
grep -q 'data-upgrade-banner' /tmp/start.html || fail "no notice on the login page"
grep -q 'name="email"' /tmp/start.html || fail "no login form"
[ "$(curl -s -o /dev/null -w '%{http_code}' "$B/api/v1/public/events")" = 503 ] || fail "the API answers during the upgrade"

T=$(curl -s -c "$J" -b "$J" "$B/login" | token)
curl -s -o /dev/null -c "$J" -b "$J" --data-urlencode "_token=$T" --data-urlencode "email=admin@example.org" "$B/login"
LINK=$(php -r 'echo quoted_printable_decode((string) file_get_contents("storage/logs/laravel.log"));' | grep -o "$B/login/link/[A-Za-z0-9]*" | tail -1)
[ -n "$LINK" ] || fail "no login link in the mail"
T=$(curl -s -c "$J" -b "$J" "$LINK" | token)
curl -s -o /dev/null -c "$J" -b "$J" --data-urlencode "_token=$T" "$LINK"
curl -s -c "$J" -b "$J" "$B/system/upgrade" > /tmp/upgrade.html
T=$(token < /tmp/upgrade.html)
[ -n "$T" ] || fail "the upgrade page is not shown to the administrator"
curl -s -c "$J" -b "$J" --max-time 600 --data-urlencode "_token=$T" "$B/system/upgrade" > /tmp/done.html
grep -q 'data-upgrade-done' /tmp/done.html || { sed -e 's/<[^>]*>//g' /tmp/done.html | grep -v '^\s*$' | tail -30; fail "the upgrade did not finish"; }
[ "$(curl -s -o /dev/null -w '%{http_code}' -c "$J" -b "$J" "$B/admin")" = 200 ] || fail "the administration does not open after the upgrade"
curl -s "$B/login" | grep -q 'data-upgrade-banner' && fail "the notice is still shown"
echo "ok   upgrade in the browser"
