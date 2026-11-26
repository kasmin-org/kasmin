# Kasmin aktualisieren

Kasmin geht nur auf neuere Versionen; ein Zurück auf ältere gibt es nicht.

1. Installieren Sie die neue Version (*System → Systemstatus → Aktualisierung*, das ZIP der Version über den Installer oder ein neues Tag des Docker-Images).
2. Bis die Datenbank aktualisiert ist, zeigt die Anmeldeseite einen Hinweis, und **nur Administratorinnen und Administratoren können sich anmelden**. Alles andere wartet.
3. Melden Sie sich mit einem neuen Anmeldelink als Administratorin oder Administrator an und drücken Sie **Jetzt aktualisieren**. Kasmin schreibt zuerst eine komprimierte Sicherung der Datenbank nach
   `storage/app/private/backups`, aktualisiert dann die Datenbank und hält die neue Version fest. Das kann einige Minuten dauern; es läuft immer nur ein
   Upgrade. Wird es nicht fertig, zeigt die Seite die Details, und Sie können es erneut versuchen.

Während die Dateien einer neuen Version eingespielt werden, sehen Besucherinnen und Besucher eine kurze Seite „Kasmin wird aktualisiert“ (HTTP 503) statt Fehlern aus
halb ersetzten Dateien; sie verschwindet von selbst.

Eine Kommandozeile ist nicht nötig. Wo es eine gibt, macht `php artisan kasmin:upgrade` dasselbe, `php artisan kasmin:status` zeigt die
Versionen, und Skripte für die Bereitstellung können `POST /system/hooks/upgrade` aufrufen (siehe [Hosting](hosting.md)). Docker aktualisiert beim Start von selbst.

## Automatische Updates

*Einstellungen → Organisation und Rechnungen → Automatische Updates* (Administratorinnen und Administratoren, nicht in Docker; **standardmäßig ein**, kann abgeschaltet werden): Eine neue Version wird vom
Cronjob installiert, sobald sie die eingestellte Zahl von Tagen veröffentlicht ist (Voreinstellung 10), zur eingestellten Uhrzeit (Voreinstellung 04:00, in der Zeitzone
der Einstellungen). Zuerst wird eine Sicherung der Datenbank und der Programmdateien erstellt.

- Zwei Tage davor bekommen alle Administratorinnen und Administratoren einen Hinweis mit dem Datum.
- Erscheint dazwischen eine neuere Version, wird das geplante Update abgesagt, die Administratorinnen und Administratoren werden informiert, und die Wartezeit beginnt
  für die neuere Version von vorn. So wird eine Version, die kurz danach korrigiert wird, nie installiert.
- Danach bekommen die Administratorinnen und Administratoren eine Mail mit den Änderungen. Hat das Update nicht funktioniert, läuft die bisherige Version weiter, die
  Administratorinnen und Administratoren werden informiert, und es wird in der nächsten Nacht erneut versucht.

## Warum Kasmin nach einem Zurück auf eine ältere Version nicht startet

Wurde die Datenbank von einer neueren Version von Kasmin aktualisiert, darf älterer Code nicht mit ihr arbeiten: Er könnte Daten falsch lesen oder
beschädigen. In diesem Fall hält Kasmin alle Webanfragen und die meisten Befehle an und zeigt „Die Datenbank ist neuer als diese
Version“. Installieren Sie die neuere Version wieder. Befehle, die das Schema zurücksetzen würden (`migrate:rollback`,
`migrate:fresh`, …), sind außerhalb von Entwicklungs- und Testumgebungen abgeschaltet.

## Datensicherungen

Kasmin schreibt jede Nacht (02:30, braucht den Cronjob) eine Sicherung der Datenbank nach `storage/app/private/backups` und behält die letzten 7 täglichen
(*Einstellungen → Tägliche Sicherungen behalten (Tage)*); Sicherungen vor Upgrades bleiben erhalten. *System → Systemstatus* zeigt die letzte. `php artisan kasmin:backup`
erstellt sofort eine. **Kopieren Sie die Sicherungen auch an einen anderen Ort**: Sie liegen auf demselben Server, und sie enthalten personenbezogene Daten. Hochgeladene Dateien
und die Datei `.env` mit dem `APP_KEY` sind nicht enthalten; ohne diesen Schlüssel können verschlüsselte Daten wie Sicherheitsblätter nicht gelesen werden.

### Datensicherungen im Browser

*System → Systemstatus → Datensicherungen* (Administratorinnen und Administratoren): **Jetzt sichern**, eine Sicherung **Herunterladen** und – sobald eine Passphrase gesetzt ist –
**Verschlüsselt herunterladen**. Jeder Download steht im Audit-Protokoll.

### Eine Kopie außerhalb des Servers

Eine Sicherung auf demselben Server hilft nicht, wenn der Server verloren geht. *Einstellungen → Organisation und Rechnungen → Sicherung außerhalb des Servers*:

| Einstellung | Bedeutung |
|---|---|
| Adresse des Ordners | ein Ordner eines Speicherdienstes, der **WebDAV** spricht, nur `https://` – z. B. Nextcloud (`https://cloud.example.org/remote.php/dav/files/NAME/backups`) oder eine Storage-Box |
| Benutzername, Passwort | des Speichers; verwenden Sie ein App-Passwort, wenn der Dienst eines anbietet |
| Passphrase | mindestens 12 Zeichen; **notieren Sie sie außerhalb von Kasmin** – ohne sie lassen sich die Kopien nicht öffnen |

Jede Nacht (und mit *Jetzt sichern*) wird die Sicherung mit der Passphrase verschlüsselt und dorthin gesendet; es bleiben so viele Kopien erhalten wie tägliche Sicherungen.
Der Systemstatus zeigt, wann die letzte Kopie gesendet wurde, und einen Fehler, wenn es fehlgeschlagen ist. Passwort und Passphrase werden verschlüsselt gespeichert und nie
wieder angezeigt.

**Eine Kopie wieder öffnen**: *Systemstatus → Datensicherungen → Verschlüsselte Sicherung öffnen*: Laden Sie die `.enc`-Datei hoch, Sie bekommen die `.sql.gz` zurück. Ohne Kasmin:
`openssl enc -d -aes-256-cbc -pbkdf2 -iter 100000 -md sha256 -in FILE.sql.gz.enc -out FILE.sql.gz`.

### Eine Sicherung wiederherstellen

**Im Browser** (Administratorinnen und Administratoren, MySQL und MariaDB): *System → Systemstatus → Datensicherungen → Sicherung wiederherstellen*. Wählen Sie eine Sicherung des
Servers oder laden Sie eine Datei hoch (`.sql.gz` oder eine verschlüsselte `.enc` mit ihrer Passphrase), tippen Sie das angezeigte Wort und bestätigen Sie. Kasmin prüft, ob die
Datei eine vollständige Sicherung derselben Art von Datenbank ist, speichert den aktuellen Stand als Sicherung mit dem Namen `before-restore`, ersetzt alles in
der Datenbank und bittet alle, sich neu anzumelden. Ist die Sicherung älter als die installierte Version, erscheint der Hinweis auf das Upgrade, und eine
Administratorin oder ein Administrator startet das Upgrade wie gewohnt. Hochgeladene Dateien und die Datei `.env` mit dem `APP_KEY` sind nicht Teil einer Sicherung: Das Wiederherstellen auf
einem anderen Server braucht denselben Schlüssel, sonst können verschlüsselte Daten wie Sicherheitsblätter nicht gelesen werden.

**Ohne Kasmin** (z. B. wenn die Installation nicht startet):

Das Wiederherstellen ersetzt **alle** Daten der Zieldatenbank. Halten Sie Kasmin zuerst an (Wartungsmodus, oder stoppen Sie den Container), und behalten Sie eine Kopie des
aktuellen Stands. Eine vollständige Sicherung endet mit der Zeile `-- End of backup`.

```
zcat kasmin-daily-….sql.gz | mysql -u USER -p DATABASE          # or mariadb; phpMyAdmin: Import
```

Bei Sicherungen von vor 0.16 müssen die Fremdschlüsselprüfungen von Hand abgeschaltet werden:

```
(echo "SET FOREIGN_KEY_CHECKS=0;"; zcat kasmin-….sql.gz) | mysql -u USER -p DATABASE
```

Führen Sie danach `php artisan kasmin:upgrade` aus. Der `APP_KEY` in `.env` muss derjenige sein, mit dem die Sicherung erstellt wurde.
