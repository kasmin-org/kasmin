# Kasmin installieren

Voraussetzungen: PHP 8.3 oder neuer mit 128 MB Speicher und den üblichen Erweiterungen, MariaDB oder MySQL und ein Cronjob. Die
ganze Liste, mit dem Zweck jedes Punktes, steht unter [Hosting](hosting.md#was-der-server-bieten-muss); das Skript zur
Einrichtung prüft sie, bevor es beginnt.

## Auf gewöhnlichem Webhosting (empfohlener Weg)

1. Legen Sie in der Verwaltungsoberfläche Ihres Hostings eine leere MariaDB-/MySQL-Datenbank an.
2. Laden Sie `installer/kasmin-setup.php` aus dem Repository herunter und laden Sie die Datei auf Ihren Webspace hoch.
3. Öffnen Sie sie im Browser. Sie zeigt, wo der Zugangscode liegt: in der Datei `kasmin-setup-code.txt` neben dem Skript
   (lesen Sie sie über FTP). So kann nur installieren, wer Zugriff auf den Webspace hat.
4. Wählen Sie eine Version (die letzten 10 Versionen werden mit Datum und Änderungen angeführt) – oder, nur zum Testen, haken Sie den
   *Entwicklungsmodus* an und wählen Sie einen Branch.
5. Geben Sie das Zielverzeichnis und die Daten der Datenbank ein. Das Skript lädt das Paket herunter, **prüft seine SHA-256-Prüfsumme**,
   entpackt es und schreibt die Konfigurationsdatei `.env`.
6. Richten Sie das Wurzelverzeichnis Ihrer Domain auf den Ordner `public` im Zielverzeichnis und öffnen Sie `/install`:
   Name der Organisation, **Sprache** (Deutsch oder Englisch – die eine Sprache der Installation, siehe
   [System](system.md#sprache)), **Standardinhalte** in dieser Sprache (oder keine – nicht empfohlen) und die erste
   Person mit Administrationsrechten.

## Mit Docker

Das Image beruht auf Alpine Linux (derzeit 3.24) mit Apache und PHP 8.5 und ist so klein wie möglich; jeder Build
installiert alle verfügbaren Sicherheitsupdates. `bash` und `vim` sind für die Wartung enthalten (`docker exec -it <container> bash`).

Images: `ghcr.io/kasmin-org/kasmin:<version>` (und `latest` für die neueste Version; `<branch>` und `sha-<commit>`
für Test-Builds). Alles lässt sich mit Umgebungsvariablen einstellen, auch das Zertifikat: siehe [Docker](docker.md).

## Auf der Kommandozeile

```sh
php kasmin-setup.php --target=/srv/kasmin --db-host=localhost --db-name=kasmin --db-user=kasmin --db-password=…
cd /srv/kasmin && php artisan kasmin:install
```

`php kasmin-setup.php --list` zeigt die Versionen, `--list-branches` die Entwicklungs-Builds, `--release=v0.1.0` oder
`--branch=develop` wählt eine aus, `--source=file.zip` installiert ein lokales Paket (das verwenden die CI-Tests).

Upgrades: siehe [upgrading.md](upgrading.md).
