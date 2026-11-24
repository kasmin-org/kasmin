# TLS of the container: custom certificate, Let's Encrypt, self-signed, or off (behind a reverse proxy).
TLS_DIR=/var/lib/kasmin/tls
ACME_DIR=/var/lib/kasmin/acme
CHALLENGE_DIR=/var/lib/kasmin/challenge
CONF=/etc/apache2/conf.d/kasmin.conf

tls_host() { echo "${KASMIN_URL:-}" | sed -E 's#^https?://##; s#[:/].*$##'; }

write_conf() {
  local host; host="$(tls_host)"; host="${host:-localhost}"
  {
    echo "ServerName $host"
    # Health check, only from inside the container.
    echo "Listen 127.0.0.1:8081"
    echo "<VirtualHost 127.0.0.1:8081>"; echo "  DocumentRoot /var/www/html/public"; echo "</VirtualHost>"
    if [ "$TLS_MODE" = off ]; then
      echo "<VirtualHost *:80>"; echo "  DocumentRoot /var/www/html/public"; echo "</VirtualHost>"
      return
    fi
    cat <<VHOSTS
<VirtualHost *:80>
  Alias /.well-known/acme-challenge/ $CHALLENGE_DIR/
  <Directory $CHALLENGE_DIR>
    Require all granted
    AllowOverride None
  </Directory>
  RewriteEngine On
  RewriteCond %{REQUEST_URI} !^/\.well-known/acme-challenge/
  RewriteRule ^ https://$([ -n "$(tls_host)" ] && tls_host || echo '%{SERVER_NAME}')%{REQUEST_URI} [R=301,L]
</VirtualHost>
Listen 443
SSLSessionCache none
<VirtualHost *:443>
  DocumentRoot /var/www/html/public
  SSLEngine on
  SSLProtocol -all +TLSv1.2 +TLSv1.3
  SSLCertificateFile $CERT
  SSLCertificateKeyFile $CERT_KEY
  RequestHeader set X-Forwarded-Proto https
</VirtualHost>
VHOSTS
  } > "$CONF"
}

self_signed() {
  local host; host="$(tls_host)"; host="${host:-localhost}"
  CERT="$TLS_DIR/self-signed.crt"; CERT_KEY="$TLS_DIR/self-signed.key"
  if ! { [ -s "$CERT" ] && openssl x509 -in "$CERT" -noout -checkend 2592000 -checkhost "$host" 2>/dev/null | grep -q "does match"; }; then
    (umask 077; openssl req -x509 -newkey rsa:2048 -nodes -days 365 -subj "/CN=$host" -addext "subjectAltName=DNS:$host" -keyout "$CERT_KEY" -out "$CERT" 2>/dev/null)
  fi
}

# Returns 0 if there is a (new or still valid) certificate from Let's Encrypt.
acme_issue() {
  local host; host="$(tls_host)"
  local args=(-c "$ACME_DIR" -h /usr/local/lib/kasmin/acme-hook.sh)
  [ -n "${KASMIN_ACME_SERVER:-}" ] && args+=(-a "$KASMIN_ACME_SERVER")
  if [ ! -s "$ACME_DIR/private/key.pem" ]; then
    uacme "${args[@]}" -y new ${KASMIN_LETSENCRYPT_EMAIL:+"$KASMIN_LETSENCRYPT_EMAIL"} || return 1
  fi
  # uacme renews only if the certificate expires in less than 30 days (exit code 1 = nothing to do).
  local code=0; uacme "${args[@]}" issue "$host" || code=$?
  [ "$code" -le 1 ] && [ -s "$ACME_DIR/$host/cert.pem" ]
}

tls_setup() {
  mkdir -p "$TLS_DIR" "$ACME_DIR" "$CHALLENGE_DIR"; chmod 700 "$TLS_DIR" "$ACME_DIR"
  TLS_MODE="${KASMIN_TLS:-auto}"
  CERT="${KASMIN_TLS_CERT:-/certs/tls.crt}"; CERT_KEY="${KASMIN_TLS_KEY:-/certs/tls.key}"
  if [ "$TLS_MODE" = auto ]; then
    if [ -s "$CERT" ] && [ -s "$CERT_KEY" ]; then TLS_MODE=custom; else TLS_MODE=selfsigned; fi
  fi
  case "$TLS_MODE" in
    off) log "TLS is off: use this only behind a reverse proxy that provides HTTPS." ;;
    custom)
      [ -s "$CERT" ] && [ -s "$CERT_KEY" ] || { log "ERROR: KASMIN_TLS=custom, but $CERT or $CERT_KEY is missing."; exit 1; }
      [ "$(openssl x509 -in "$CERT" -noout -pubkey)" = "$(openssl pkey -in "$CERT_KEY" -pubout 2>/dev/null)" ] || { log "ERROR: certificate and private key do not belong together."; exit 1; }
      openssl x509 -in "$CERT" -noout -checkend 1209600 >/dev/null || log "WARNING: the certificate expires in less than 14 days: $(openssl x509 -in "$CERT" -noout -enddate)"
      watch_custom & ;;
    selfsigned) self_signed; log "WARNING: self-signed certificate. Mount your own certificate to /certs or set KASMIN_TLS=letsencrypt." ;;
    letsencrypt)
      [ -n "$(tls_host)" ] || { log "ERROR: KASMIN_TLS=letsencrypt needs KASMIN_PUBLIC_URL."; exit 1; }
      local host; host="$(tls_host)"
      if [ -s "$ACME_DIR/$host/cert.pem" ]; then CERT="$ACME_DIR/$host/cert.pem"; CERT_KEY="$ACME_DIR/private/$host/key.pem"; else self_signed; fi
      renew_loop & ;;
    *) log "ERROR: KASMIN_TLS must be auto, custom, letsencrypt, selfsigned or off."; exit 1 ;;
  esac
  if [ "$TLS_MODE" != off ]; then export SESSION_SECURE_COOKIE=true; fi
  write_conf
}

# First certificate right after the start (the web server answers the challenge), then twice a day.
renew_loop() {
  local host; host="$(tls_host)"; local before after wait
  sleep 5
  while true; do
    before="$(sha256sum "$ACME_DIR/$host/cert.pem" 2>/dev/null || true)"
    if acme_issue; then
      after="$(sha256sum "$ACME_DIR/$host/cert.pem")"; wait=43200
      if [ "$before" != "$after" ] || [ "$CERT" != "$ACME_DIR/$host/cert.pem" ]; then
        CERT="$ACME_DIR/$host/cert.pem"; CERT_KEY="$ACME_DIR/private/$host/key.pem"; write_conf
        httpd -t 2>/dev/null && httpd -k graceful && log "Certificate from Let's Encrypt active: $(openssl x509 -in "$CERT" -noout -enddate)"
      fi
    else
      log "WARNING: no certificate from Let's Encrypt for $host. Port 80 must be reachable from the internet under this name. Next attempt in one hour."
      wait=3600
    fi
    sleep "$wait"
  done
}

# A renewed custom certificate is taken over without a restart.
watch_custom() {
  local last now; last="$(cat "$CERT" "$CERT_KEY" | sha256sum)"
  while sleep "${KASMIN_TLS_WATCH_SECONDS:-300}"; do
    now="$(cat "$CERT" "$CERT_KEY" 2>/dev/null | sha256sum)"
    if [ "$now" != "$last" ] && [ "$(openssl x509 -in "$CERT" -noout -pubkey 2>/dev/null)" = "$(openssl pkey -in "$CERT_KEY" -pubout 2>/dev/null)" ]; then
      last="$now"; httpd -k graceful && log "New certificate active: $(openssl x509 -in "$CERT" -noout -enddate)"
    fi
  done
}
