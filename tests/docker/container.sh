#!/bin/bash
# Integration test of the image: set-up by variables, warnings, TLS modes, Let's Encrypt (Pebble). Usage: container.sh <image>
# Containers and volumes get a unique suffix and stay after the run (CI machines are thrown away).
set -euo pipefail
IMG="$1"; ID="kt$RANDOM$RANDOM"; NET="$ID-net"; W="$(mktemp -d)"
ok() { echo "ok   $*"; }
fail() { echo "FAIL $*"; docker logs "$ID-$1" 2>&1 | tail -30; exit 1; }
trap 'docker ps -q --filter "name=$ID" | xargs -r docker stop -t 2 >/dev/null 2>&1 || true' EXIT
docker network create "$NET" >/dev/null
run() { local n="$1"; shift; docker run -d --name "$ID-$n" --network "$NET" "$@" "$IMG" >/dev/null; }
up() { for i in $(seq 1 90); do docker exec "$ID-$1" wget -q -O /dev/null http://127.0.0.1:8081/up 2>/dev/null && return; sleep 1; done; fail "$1" "does not start"; }
logs() { docker logs "$ID-$1" 2>&1; }
has() { local l; l="$(logs "$1")"; grep -qF -- "$2" <<< "$l" || fail "$1" "log does not contain: $2"; ok "$1: log contains \"$2\""; }
in_() { local n="$1"; shift; docker exec "$ID-$n" "$@"; }
cert() { openssl req -x509 -newkey rsa:2048 -nodes -days "${3:-90}" -subj "/CN=$1" -addext "subjectAltName=DNS:$1" -keyout "$2.key" -out "$2.crt" 2>/dev/null; }
# grep -q on a variable: with pipefail, an early end of grep would count as a failure of the command before it.
is() { local s; s="$(served "$1" "$2" || true)"; grep -qi -- "$3" <<< "$s"; }
served() { in_ "$1" sh -c "openssl s_client -connect 127.0.0.1:443 -servername $2 </dev/null 2>/dev/null | openssl x509 -noout -issuer -subject -fingerprint -sha256"; }
env_=(-e KASMIN_PUBLIC_URL=https://camp.test -e "KASMIN_ORGANISATION=Test Camp" -e KASMIN_LOCALE=en -e "KASMIN_ADMIN_NAME=Alex Admin" -e KASMIN_ADMIN_EMAIL=admin@example.org -e KASMIN_SCHEDULER=false)

# 1. Set-up by variables, self-signed, redirect
run a "${env_[@]}" -e KASMIN_INVOICE_DUE_DAYS=21 -e KASMIN_MAIL_HOST=mail.example.org -e KASMIN_MAIL_PASSWORD=geheim-1 -v "$ID-storage:/var/www/html/storage" -v "$ID-tls:/var/lib/kasmin"
up a; has a "Installed. The variables were written to the database"; has a "self-signed certificate"
in_ a sh -c "printf 'GET /portal?x=1 HTTP/1.0\r\nHost: camp.test\r\n\r\n' | nc 127.0.0.1 80" > "$W/r"; grep -qi "^Location: https://camp.test/portal?x=1" "$W/r" && grep -q "301" "$W/r" || { cat "$W/r"; fail a "no redirect"; }; ok "port 80 redirects to https"
in_ a sh -c "printf 'GET /.well-known/acme-challenge/x HTTP/1.0\r\nHost: camp.test\r\n\r\n' | nc 127.0.0.1 80" > "$W/r"; grep -q " 404 " "$W/r" || { cat "$W/r"; fail a "challenge path"; }; ok "challenge path is not redirected"
in_ a sh -c "echo hidden > /var/lib/kasmin/tls/probe; printf 'GET /.well-known/acme-challenge/../tls/probe HTTP/1.0\r\nHost: x\r\n\r\n' | nc 127.0.0.1 80" > "$W/r"; ! grep -q hidden "$W/r" || fail a "private files reachable"; ok "private files are not reachable"
is a camp.test "camp.test" || fail a "self-signed certificate"; ok "https answers"
key1="$(in_ a sha256sum storage/app/private/app.key)"
[ "$(in_ a stat -c %a storage/app/private/app.key)" = 600 ] || fail a "key permissions"
docker stop -t 3 "$ID-a" >/dev/null

# 2. New container, same data, changed variables: warnings, nothing overwritten, same key
run b "${env_[@]}" -e KASMIN_INVOICE_DUE_DAYS=30 -e KASMIN_MAIL_HOST=mail.example.org -e KASMIN_MAIL_PASSWORD=geheim-2 -v "$ID-storage:/var/www/html/storage" -v "$ID-tls:/var/lib/kasmin"
up b; has b 'KASMIN_INVOICE_DUE_DAYS is "30", but the database contains "21"'; has b "KASMIN_MAIL_PASSWORD differs"
! grep -q geheim <<< "$(logs b)" || fail b "a secret is in the log"; ok "no secret in the log"
v="$(in_ b kasmin tinker --execute='echo App\Support\Settings::get("invoice_due_days");' | tail -1)"; [ "$v" = 21 ] || { echo "value: $v"; fail b "value was overwritten"; }; ok "the database value is kept (and the command kasmin works)"
[ "$(in_ b sha256sum storage/app/private/app.key)" = "$key1" ] || fail b "new key"; ok "the application key is kept"

# 3. Custom certificate, replaced while running; wrong key is refused
mkdir "$W/certs"; cert camp.test "$W/certs/tls"; chmod 755 "$W" "$W/certs"
run c "${env_[@]}" -e KASMIN_TLS_WATCH_SECONDS=2 -v "$W/certs:/certs:ro"
up c; f1="$(openssl x509 -in "$W/certs/tls.crt" -noout -fingerprint -sha256)"; is c camp.test "$f1" || fail c "custom certificate not used"; ok "custom certificate is used"
cert camp.test "$W/new"; cat "$W/new.key" > "$W/certs/tls.key"; cat "$W/new.crt" > "$W/certs/tls.crt"; sleep 8
f2="$(openssl x509 -in "$W/certs/tls.crt" -noout -fingerprint -sha256)"; is c camp.test "$f2" || fail c "new certificate not active"; ok "renewed certificate is taken over without a restart"
mkdir "$W/bad"; cert camp.test "$W/bad/tls"; cert other.test "$W/x"; cp "$W/x.key" "$W/bad/tls.key"; chmod 755 "$W/bad"
run d "${env_[@]}" -v "$W/bad:/certs:ro"; sleep 12; has d "do not belong together"
[ "$(docker inspect -f '{{.State.Running}}' "$ID-d")" = false ] || fail d "runs with a wrong key"; ok "wrong key stops the container"

# 4. Wrong values stop the set-up; TLS off
run e "${env_[@]}" -e KASMIN_MAIL_ENCRYPTION=none; sleep 8; has e "KASMIN_MAIL_ENCRYPTION: must be ssl or tls"
run f "${env_[@]}" -e KASMIN_TLS=off; up f; in_ f sh -c "printf 'GET /login HTTP/1.0\r\nHost: camp.test\r\n\r\n' | nc 127.0.0.1 80" > "$W/r"; grep -q " 200 " "$W/r" || fail f "TLS off"; ok "TLS off answers on port 80"

# 5. Let's Encrypt against Pebble
cat > "$W/pebble.json" <<JSON
{"pebble":{"listenAddress":"0.0.0.0:14000","managementListenAddress":"0.0.0.0:15000","certificate":"test/certs/localhost/cert.pem","privateKey":"test/certs/localhost/key.pem","httpPort":80,"tlsPort":443,"ocspResponderURL":"","externalAccountBindingRequired":false}}
JSON
chmod 644 "$W/pebble.json"
docker run -d --name "$ID-pebble" --network "$NET" --network-alias pebble -e PEBBLE_VA_NOSLEEP=1 -v "$W/pebble.json:/pebble.json:ro" ghcr.io/letsencrypt/pebble@sha256:0c81f05db371f436c97fb894bd6d2ab8fc9c8e2ce40f8bef8a8e15941bcd75ef -config /pebble.json >/dev/null
docker cp -q "$ID-pebble:/test/certs/pebble.minica.pem" "$W/pebble-ca.crt"; chmod 644 "$W/pebble-ca.crt"
docker run -d --name "$ID-le" --network "$NET" --network-alias camp.test "${env_[@]}" -e KASMIN_TLS=letsencrypt -e KASMIN_LETSENCRYPT_EMAIL=admin@example.org -e KASMIN_ACME_SERVER=https://pebble:14000/dir -v "$W/pebble-ca.crt:/usr/local/share/ca-certificates/pebble.crt:ro" "$IMG" >/dev/null
up le; for i in $(seq 1 60); do grep -q "Certificate from Let's Encrypt active" <<< "$(logs le)" && break; sleep 1; done
has le "Certificate from Let's Encrypt active"; is le camp.test "pebble" || fail le "certificate is not from the ACME server"; ok "https uses the certificate of the ACME server"
[ -z "$(in_ le ls /var/lib/kasmin/challenge)" ] || fail le "challenge file left"; ok "challenge files are removed"
echo "ALL OK"
