# Cronjob und Hosting ohne Kommandozeile

## Der Cronjob (erforderlich)

Kasmin braucht **einen Cronjob, der alle 5 Minuten läuft** (jede Minute geht auch). Er sendet die Mail-Warteschlange, Erinnerungen, Zusammenfassungen und
startet Automatisierungen. Fehlt er, bekommen Administratorinnen und Administratoren die Benachrichtigung „Cronjob nicht eingerichtet“, und die
Seite Systemstatus zeigt einen Fehler.

**Linux-Server mit crontab** (`crontab -e` als der Benutzer, dem die Dateien von Kasmin gehören):

```
*/5 * * * * cd /path/to/kasmin && php artisan schedule:run >> /dev/null 2>&1
```

**Docker:** nicht nötig, der Container führt den Scheduler selbst aus.

**Webhosting, dessen Cronjobs nur Adressen aufrufen können** (z. B. all-inkl.com): Setzen Sie `KASMIN_HOOK_TOKEN` in `.env` (mindestens
32 zufällige Zeichen) und legen Sie einen Cronjob an, der alle 5 Minuten diese Adresse aufruft:

```
https://your-kasmin-domain.example/system/cron?token=YOUR_TOKEN
```

Bei all-inkl.com: KAS → *Tools* → *Cronjobs* → *Neuen Cronjob anlegen*: Protokoll `https`, die Adresse oben (ohne
`https://`), eine Beschreibung wie „Kasmin scheduler“, alle 5 Minuten (`*/5` für die Minute, `*` für Stunde, Tag, Monat
und Wochentag; manche Pakete lehnen „jede Minute“ ab). Die Antwort der Adresse ist ein kurzer JSON-Text mit `"ok":true`.

Tägliche und stündliche Läufe (Zusammenfassung, Erinnerungen, Automatisierungen, Aufbewahrung) laufen einmal beim ersten Aufruf nach ihrer Uhrzeit. Ein
Aufruf, der ein paar Minuten zu spät kommt, schadet also nicht. Die Warnung „Cronjob nicht eingerichtet“ erscheint
erst nach 15 Minuten ohne Aufruf.

## Was der Server bieten muss

*System → Systemstatus* führt jeden Punkt unten mit einem Haken oder einem Kreuz an (*Jeden Punkt anzeigen*). So können Sie Ihr Hosting damit vergleichen.

| Was | Wofür |
|---|---|
| PHP 8.3 oder neuer | die unterstützten Versionen stehen im README |
| MariaDB oder MySQL | die Daten |
| **128 MB** Speicher für PHP (`memory_limit`) | genug für alle Seiten. Nur große PDFs (eine Lagermappe für mehr als etwa 60 Personen) brauchen mehr; Kasmin verlangt dann für diese eine Anfrage 256 MB, was die meisten Hoster erlauben. Eine `.user.ini` ist nicht nötig. |
| ein Cronjob, jede Minute oder alle paar Minuten | Mail-Warteschlange, Erinnerungen, Automatisierungen, Datensicherungen, automatische Updates |

Erforderliche PHP-Erweiterungen:

| Erweiterung | Wofür |
|---|---|
| `pdo_mysql` | Datenbank (MySQL, MariaDB) |
| `intl` | Datumsangaben, Zahlen und Beträge in Deutsch und Englisch |
| `mbstring` | Texte mit Umlauten und anderen Zeichen |
| `openssl` | Verschlüsselung vertraulicher Daten, Mail über TLS, Datensicherungen |
| `zip` | Updates, Export als XLSX, Hochladen mehrerer Dateien |
| `gd` | QR-Code auf Rechnungen, Bilder |
| `bcmath` | genaue Geldbeträge |
| `fileinfo` | prüft, was eine hochgeladene Datei wirklich ist |
| `xml`, `dom` | PDFs (Rechnungen, Listen, Lagermappe), Dateien von Banken |
| `xmlreader` | Import aus Excel-Dateien |
| `iconv` | Mails und Dateien in anderen Zeichensätzen |
| `zlib` | komprimierte Datensicherungen |
| `tokenizer`, `ctype`, `json` | braucht das Framework |

Empfohlen (Kasmin funktioniert ohne sie, mit den genannten Einschränkungen):

| Punkt | Ohne ihn |
|---|---|
| Erweiterung `curl` | die Suche nach neuen Versionen, die Sicherung außerhalb des Servers und Webhooks funktionieren möglicherweise nicht |
| Funktion `proc_open()` | der Cronjob kann seine Aufgaben nicht als Befehl starten – verwenden Sie die Cron-Adresse über HTTP (unten) |
| Funktion `set_time_limit()` | ein langes Upgrade oder ein großer Import kann an die Zeitgrenze des Servers stoßen |
| Funktion `dns_get_record()` | die DNS-Einträge für Mail (SPF, DKIM, DMARC) können nicht geprüft werden |

Ordner, in die Kasmin schreiben können muss: `storage/` (hochgeladene Dateien, Datensicherungen, Protokolle, Sitzungen) und `bootstrap/cache/`.

Wenn Ihr Hoster fragt, was installiert ist: *System → Systemstatus → PHP-Konfiguration (phpinfo)* zeigt die Versionen, Erweiterungen und Grenzen des
Servers. Nur Administratorinnen und Administratoren sehen sie, jede Ansicht wird protokolliert, und die Schlüssel und Passwörter der Installation werden weggelassen.

# Hosting ohne Kommandozeile

Viele Webhosting-Pakete bieten keinen SSH-Zugang und führen Cronjobs nur als HTTP-Aufrufe aus. Kasmin bietet deshalb
geschützte HTTP-Hooks. Sie sind **abgeschaltet**, solange `KASMIN_HOOK_TOKEN` nicht in `.env` gesetzt ist (mindestens 32 zufällige
Zeichen). Das Token wird als `Authorization: Bearer <token>` gesendet; Dienste, die keine Header senden können, dürfen
`?token=<token>` verwenden (nur Cron).

| Hook | Zweck |
|---|---|
| `POST /system/hooks/upgrade` | Führt das Upgrade aus (`kasmin:upgrade`, mit Datensicherung) – rufen Sie ihn auf, nachdem Sie eine neue Version hochgeladen haben |
| `GET or POST /system/cron` | Führt den Scheduler einmal aus (Mail-Warteschlange, Automatisierungen, Erinnerungen) – rufen Sie ihn alle paar Minuten über die Cronjob-Funktion des Hostings auf |
| `POST /system/hooks/demo` | Nur Demo-Instanzen (`APP_ENV=demo`): installiert Kasmin mit erfundenen Demodaten, wenn die Datenbank leer ist |

Verwenden Sie nur HTTPS. Ein falsches oder fehlendes Token ergibt die Antwort „404 Not Found“. So lassen sich die Hooks nicht entdecken.

## Demo-Instanzen

Demo-Instanzen verwenden `APP_ENV=demo` und diese Einstellungen:

| Einstellung | Bedeutung |
|---|---|
| `KASMIN_DEMO_LOCALE` | `de` oder `en`: Sprache der Demodaten und der Standardinhalte |
| `KASMIN_DEMO_ADMIN_EMAIL` | Adresse der ersten Person mit Administrationsrechten |
| `KASMIN_DEMO_EMAIL_DOMAIN` | Domain der E-Mail-Adressen der erfundenen Personen – mit einem Sammelpostfach (Catch-all) für diese Domain lassen sich dort alle Anmeldelinks der Demo lesen |
