# Systemstatus, Benachrichtigungen und Updates

## Sprache

Eine Installation arbeitet in **einer Sprache** – Deutsch oder Englisch – für alle: Verwaltung, Portal, öffentliche
Seiten, Mails und Dokumente. Sie wird bei der Installation gewählt; Administratorinnen und Administratoren ändern sie unter *Administration → Globale Einstellungen →
System → Sprache*. Es gibt keine Umschaltung pro Person oder pro Besuch.

Die Sprache bestimmt die Texte der Software. Alles, was **eingegeben** wurde – Formulare und ihre Felder, Mailvorlagen,
Namen von Rollen und Mitgliedsarten, Automatisierungen, Texte von Veranstaltungen –, gibt es einmal, und es wird nicht übersetzt:
Es bleibt genau so, wie es ist, wenn die Sprache geändert wird.

Eine Installation einer Version bis 0.27 hat solche Texte einmal pro Sprache gespeichert. Das Upgrade behält den Text in der
Sprache der Installation (den anderen, wenn dieser leer war). Bei Mailvorlagen bleibt der Text der anderen Sprache
in den Versionen der Vorlage erhalten.

## Zeitzone

*Administration → Globale Einstellungen → Zeitzone*: alle Uhrzeiten und täglichen Läufe. Installationen vor 0.13 laufen in UTC, bis eine
Zeitzone gewählt wird. Schon gespeicherte Zeiten werden nicht umgerechnet. Links und Angebote, die in diesem Moment offen sind, können daher bis
zu zwei Stunden früher oder später ablaufen.

## Systemstatus

*Administration → System → Systemstatus* (nur Administratorinnen und Administratoren) zeigt:

- die PHP-Version und das Datum, bis zu dem sie Sicherheitsupdates bekommt
- PHP-Erweiterungen, Funktionen und Schreibrechte: *Jeden Punkt anzeigen* führt jeden einzeln an, mit seinem Zweck und
  ob er vorhanden ist (die ganze Liste: [Hosting](hosting.md#was-der-server-bieten-muss))
- den Speicher, den PHP verwenden darf (128 MB sind nötig)
- ob der Cronjob läuft (letzter Lauf des Schedulers)
- alte Mails, die in der Warteschlange warten
- Datenbank: Produkt und Version des Servers (zum Beispiel „MariaDB 11.4“) und die Schemaversion
- installierte und neueste Version von Kasmin
- **DNS-Einträge für Mail** jeder Absenderdomain: SPF, DKIM und DMARC, jeweils mit einem konkreten Vorschlag für den DNS-Eintrag.
  DMARC sollte `p=reject` verwenden; alles Schwächere wird als Warnung angezeigt. Bei *direkter Zustellung* (an die
  Mailserver der Empfänger, ohne SMTP-Relay) signiert Kasmin jede Mail mit DKIM – erzeugen Sie den Schlüssel beim
  Mailkonto und setzen Sie den angezeigten Eintrag.

Am Ende der Seite zeigt *PHP-Konfiguration (phpinfo)*, was der Server bietet – Versionen, Erweiterungen, Grenzen. Hoster fragen danach.
Die Schlüssel und Passwörter der Installation werden weggelassen; jede Ansicht wird protokolliert.

## Systembenachrichtigungen

Systembenachrichtigungen gehen **nur an Personen mit der Rolle Administration**. Sie erscheinen nach der Anmeldung oben in der Verwaltung,
bis sie ausgeblendet werden, und werden **einmal** per E-Mail gesendet:

- der Cronjob läuft nicht
- die installierte PHP-Version ist die niedrigste, die noch unterstützt wird (mit dem Datum ihres Supportendes) – aktualisieren Sie PHP, bevor
  neuere Versionen von Kasmin ein neueres PHP verlangen (siehe [Versionierung](../../development/versioning.md))
- eine neuere Version von Kasmin ist verfügbar

## Aktualisieren

Auf gewöhnlichem Webhosting kann jede Administratorin und jeder Administrator Kasmin auf der Systemseite aktualisieren. *Auf Aktualisierung prüfen* listet **jede
neuere Version** auf – gibt es mehrere, wählen Sie, auf welche aktualisiert wird (zu Beginn ist die neueste gewählt; die Versionen
dazwischen müssen nicht zuerst installiert werden, und eine ältere Version lässt sich nicht wieder installieren). Unter der Auswahl zeigt die Seite
**alles, was sich** zwischen der installierten und der gewählten Version **ändert**, aus dem Changelog, sowie die
Voraussetzungen dieser Version (PHP-Version, Erweiterungen, Funktionen, Schreibrechte, Speicherplatz). *Jetzt auf … aktualisieren*
lädt das Paket herunter und prüft seine Prüfsumme, sichert die Datenbank und die Programmdateien, ersetzt die Dateien
(und stellt die alten wieder her, wenn etwas fehlschlägt) und aktualisiert dann in einem zweiten Schritt die Datenbank.

**Docker:** Die Aktualisierung an Ort und Stelle ist abgeschaltet. Holen Sie das neue Image (z. B. `ghcr.io/kasmin-org/kasmin:latest`) und
erstellen Sie den Container neu; das Upgrade der Datenbank läuft mit `php artisan kasmin:upgrade` (oder dem Upgrade-Hook).

## Eine Einstellung finden

*Administration → Organisation*, *Administration → Globale Einstellungen* und *Buchhaltung → Rechnungseinstellungen* haben oben ein Suchfeld: Tippen Sie ein Wort (z. B. *IBAN* oder *Aufbewahrung*), und nur die passenden
Einstellungen bleiben sichtbar.

## Hilfe

*Hilfe zu dieser Seite* unter dem Titel jeder Seite der Verwaltung öffnet die passende Seite dieser Dokumentation, in der
Version, die installiert ist, und in der Sprache der Oberfläche.
