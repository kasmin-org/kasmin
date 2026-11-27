# Docker: Konfiguration, Zertifikate, Let's Encrypt

Das Image (`ghcr.io/kasmin-org/kasmin`) beruht auf Alpine Linux mit Apache und PHP 8.5. `latest` ist die neueste Version,
`<version>` eine bestimmte. `bash` und `vim` sind für die Wartung enthalten.

## Schnellstart mit MariaDB und Let's Encrypt

```yaml
services:
  kasmin:
    image: ghcr.io/kasmin-org/kasmin:latest
    container_name: kasmin
    restart: unless-stopped
    ports: ["80:80", "443:443"]
    environment:
      KASMIN_PUBLIC_URL: https://camp.example.org
      KASMIN_TLS: letsencrypt
      KASMIN_LETSENCRYPT_EMAIL: office@example.org
      KASMIN_ORGANISATION: Example Camp
      KASMIN_LOCALE: de
      KASMIN_ADMIN_NAME: Alex Example
      KASMIN_ADMIN_EMAIL: alex@example.org
      KASMIN_MAIL_HOST: mail.example.org
      KASMIN_MAIL_USERNAME: office@example.org
      KASMIN_MAIL_PASSWORD_FILE: /run/secrets/mail_password
      KASMIN_MAIL_FROM_ADDRESS: office@example.org
      DB_CONNECTION: mariadb
      DB_HOST: db
      DB_DATABASE: kasmin
      DB_USERNAME: kasmin
      DB_PASSWORD_FILE: /run/secrets/db_password
    secrets: [mail_password, db_password]
    volumes:
      - storage:/var/www/html/storage     # Uploads, Datensicherungen und der Schlüssel der Installation: bitte sichern
      - certificates:/var/lib/kasmin
    depends_on: [db]
  db:
    image: mariadb:11
    restart: unless-stopped
    environment:
      MARIADB_DATABASE: kasmin
      MARIADB_USER: kasmin
      MARIADB_PASSWORD_FILE: /run/secrets/db_password
      MARIADB_RANDOM_ROOT_PASSWORD: "yes"
    secrets: [db_password]
    volumes: ["database:/var/lib/mysql"]
secrets:
  mail_password: { file: ./secrets/mail_password }
  db_password: { file: ./secrets/db_password }
volumes: { storage: {}, certificates: {}, database: {} }
```

Mails, Erinnerungen und Automatisierungen laufen im Container; ein Cronjob ist nicht nötig. Ein neues Image aktualisiert die Datenbank bei seinem
ersten Start (mit einer Datensicherung im Volume `storage`; `KASMIN_AUTO_UPGRADE=false` schaltet das ab). Befehle:
`docker exec kasmin kasmin kasmin:status` (`kasmin <artisan command>`).

## Wie die Variablen wirken

- **Bei der Einrichtung** (leere Datenbank) werden die mit *Datenbank* gekennzeichneten Variablen in die Datenbank geschrieben – mit
  `KASMIN_ORGANISATION` und `KASMIN_ADMIN_EMAIL` automatisch, sonst wenn Sie `/install` im Browser abschließen.
- **Danach zählt nur die Datenbank.** Ein neuer Container mit einer bestehenden Datenbank überschreibt nie etwas.
- Wenn eine Variable **von der Datenbank abweicht**, schreibt jeder Start eine Warnung ins Protokoll (`docker logs kasmin`), z. B.
  `WARNING: KASMIN_INVOICE_DUE_DAYS is "30", but the database contains "21". The variable is ignored …`.
  Passwörter werden nur genannt, nie ausgegeben. Ändern Sie den Wert in der Verwaltung oder korrigieren Sie die Variable.
- Jede mit *Datenbank* gekennzeichnete Variable sowie `APP_KEY` und `DB_PASSWORD` können als Datei angegeben werden: `NAME_FILE=/run/secrets/…`.

### Einrichtung und Organisation (Datenbank)

| Variable | Bedeutung |
|---|---|
| `KASMIN_PUBLIC_URL` | Adresse der Installation, z. B. `https://camp.example.org`: Links in Mails, Weiterleitung, Zertifikat |
| `KASMIN_ORGANISATION`, `KASMIN_ADMIN_EMAIL`, `KASMIN_ADMIN_NAME` | Name der Organisation, erste Person mit Administrationsrechten |
| `KASMIN_LOCALE` (`de`/`en`), `KASMIN_DEFAULTS` | die Sprache der Installation; `KASMIN_DEFAULTS=none` legt keine Standardinhalte an, jeder andere Wert legt sie in dieser Sprache an |
| `KASMIN_ORGANISATION_ADDRESS`, `_EMAIL`, `_WEBSITE`, `_IBAN`, `_BIC`, `_BANK`, `_LEGAL_FOOTER` | Daten auf Rechnungen |
| `KASMIN_INVOICE_NUMBER_FORMAT`, `KASMIN_INVOICE_DUE_DAYS`, `KASMIN_INVOICE_ATTACH_PAID`, `KASMIN_INVOICE_REMINDER_DAYS_BEFORE`, `KASMIN_INVOICE_OVERDUE_DAYS` | [Rechnungen](invoices.md) |
| `KASMIN_RETENTION_SAFETY_YEARS`, `KASMIN_RETENTION_INVOICE_YEARS` | [Aufbewahrung](privacy.md) |
| `KASMIN_AI_PROCESSING` (`none`/`non_confidential`/`all`) | [KI-Assistenten](api.md) |
| `KASMIN_TIMEZONE` (z. B. `Europe/Vienna`) | Zeitzone aller Uhrzeiten und täglichen Läufe |
| `KASMIN_DIGEST_TIME` (`07:00`), `KASMIN_APPROVAL_DAYS` (`7`) | Zusammenfassung, Freigabe-Anfragen |

### Mail (Datenbank)

| Variable | Bedeutung |
|---|---|
| `KASMIN_MAIL_HOST`, `KASMIN_MAIL_PORT`, `KASMIN_MAIL_ENCRYPTION` (`ssl`, `tls`) | Mailserver; standardmäßig Port 465 für `ssl`, 587 für `tls`. Unverschlüsselter Versand ist nicht möglich |
| `KASMIN_MAIL_USERNAME`, `KASMIN_MAIL_PASSWORD` | Zugangsdaten |
| `KASMIN_MAIL_FROM_ADDRESS`, `KASMIN_MAIL_FROM_NAME`, `KASMIN_MAIL_REPLY_TO` | Absender |
| `KASMIN_MAIL_RATE_PER_MINUTE`, `_PER_HOUR`, `_PER_DAY` | Grenzen des Anbieters |
| `KASMIN_IMAP_HOST`, `_PORT`, `_USERNAME`, `_PASSWORD`, `_SENT_FOLDER` | Antworten und Kopien gesendeter Mails |

Das Standardkonto für Mail in der Datenbank wird auch für die Mails zur Anmeldung verwendet.

### Container (immer aus der Umgebung gelesen)

| Variable | Bedeutung |
|---|---|
| `DB_CONNECTION` (`mariadb`, `mysql`, `sqlite`), `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Datenbank; ohne sie SQLite im Volume `storage` |
| `APP_KEY` | Schlüssel für verschlüsselte Daten; wird im Volume `storage` angelegt, wenn er fehlt. **Ohne ihn können Sicherheitsblätter und Passwörter nicht gelesen werden** |
| `KASMIN_TLS` | `auto` (Voreinstellung), `custom`, `letsencrypt`, `selfsigned`, `off` |
| `KASMIN_TLS_CERT`, `KASMIN_TLS_KEY`, `KASMIN_TLS_WATCH_SECONDS` | eigenes Zertifikat, siehe unten |
| `KASMIN_LETSENCRYPT_EMAIL`, `KASMIN_ACME_SERVER` | Let's Encrypt, siehe unten |
| `KASMIN_TRUSTED_PROXIES` | Adressen Ihres Reverse Proxys (durch Beistriche getrennt) |
| `KASMIN_SCHEDULER`, `KASMIN_AUTO_UPGRADE` | standardmäßig `true` |

Diese können nicht aus der Datenbank kommen, weil sie gebraucht werden, um sie zu erreichen oder zu schützen.

## HTTPS

Port 80 antwortet nur Let's Encrypt und **leitet alles andere auf HTTPS weiter** (Port 443, TLS 1.2 und 1.3).

### Ihr eigenes Zertifikat

Binden Sie das Zertifikat (mit den Zwischenzertifikaten) und den privaten Schlüssel ein:

```yaml
    volumes:
      - ./certs/fullchain.pem:/certs/tls.crt:ro
      - ./certs/privkey.pem:/certs/tls.key:ro
```

oder setzen Sie andere Pfade mit `KASMIN_TLS_CERT` und `KASMIN_TLS_KEY`. Binden Sie das **Verzeichnis** statt einzelner Dateien ein, wenn ein anderes
Programm das Zertifikat erneuert, indem es die Dateien ersetzt; Kasmin übernimmt ein neues Zertifikat innerhalb von 5 Minuten ohne
Neustart. Ein Schlüssel, der nicht zum Zertifikat gehört, hält den Container mit einer Fehlermeldung an.

### Let's Encrypt

`KASMIN_TLS=letsencrypt` mit `KASMIN_PUBLIC_URL`: Der Container holt das Zertifikat und erneuert es automatisch (Prüfung zweimal
am Tag, Erneuerung 30 Tage vor dem Ablauf). Mit dieser Einstellung nehmen Sie die
[Bedingungen von Let's Encrypt](https://letsencrypt.org/repository/) an. Der Name muss auf diesen Server zeigen, und **Port 80 muss
aus dem Internet erreichbar sein**. Bis das erste Zertifikat da ist, wird ein selbst signiertes verwendet. Behalten Sie das Volume
`/var/lib/kasmin`, weil Let's Encrypt die Zahl der Zertifikate pro Woche begrenzt. Für Tests:
`KASMIN_ACME_SERVER=https://acme-staging-v02.api.letsencrypt.org/directory`.

Der Name kommt aus der öffentlichen Adresse **in der Datenbank**; wenn Sie auf eine andere Adresse umziehen, ändern Sie sie unter *Administration → Globale Einstellungen →
System* und starten Sie den Container neu.

### Hinter einem Reverse Proxy

`KASMIN_TLS=off` und `KASMIN_TRUSTED_PROXIES=<address of the proxy>`: Der Proxy stellt HTTPS bereit und leitet an Port 80 weiter. Machen Sie
Port 80 eines solchen Containers nie aus dem Internet erreichbar.

### Weitere Zertifizierungsstellen

Zertifikate einer internen Zertifizierungsstelle (z. B. für Ihren Mailserver): Binden Sie `*.crt`-Dateien unter
`/usr/local/share/ca-certificates` ein.
