<?php

return [
    'status' => [
        'title' => 'Systemstatus', 'checks' => 'Prüfungen', 'docs' => 'Anleitung', 'more' => 'Mehr erfahren', 'dismiss' => 'Ausblenden',
        'levels' => ['ok' => 'OK', 'warning' => 'Warnung', 'error' => 'Fehler', 'info' => 'Info'],
        'php_eol' => 'Sicherheitsupdates bis :date', 'php_unknown' => 'Supportende unbekannt',
        'requirements' => 'PHP-Erweiterungen, Funktionen und Schreibrechte', 'missing' => 'fehlt',
        'cron' => 'Cronjob (Zeitplan)', 'cron_last' => 'letzter Lauf :time', 'cron_never' => 'noch nie gelaufen – bitte einrichten',
        'queue' => 'Mail-Warteschlange', 'queue_old' => ':count Mails warten länger als 30 Minuten',
        'database' => 'Datenbank', 'latest' => 'neueste Version: :version', 'latest_unknown' => 'neueste Version nicht abrufbar',
    ],
    'dns' => [
        'title' => 'Mail: DNS-Einträge', 'intro' => 'SPF, DKIM und DMARC der Absenderdomains. Empfohlen ist DMARC mit p=reject.',
        'direct' => 'direkte Zustellung', 'suggestion' => 'Vorschlag für den DNS-Eintrag:', 'none' => 'Noch keine Absender eingerichtet.', 'server_settings' => 'Servereinstellungen',
        'spf_missing' => 'Kein SPF-Eintrag – Mails können als Spam eingestuft werden.',
        'dkim_direct' => 'PHP kann mit DKIM signieren; der öffentliche Schlüssel muss im DNS stehen.',
        'dkim_unsupported' => 'PHP kann hier nicht mit DKIM signieren (OpenSSL fehlt) – direkte Zustellung nicht empfohlen.',
        'dkim_relay' => 'Kein DKIM-Eintrag gefunden. Tragen Sie beim Mailkonto den Selektor Ihres Anbieters ein.',
        'dmarc_missing' => 'Kein DMARC-Eintrag.', 'dmarc_not_reject' => 'DMARC-Richtlinie ist :policy, empfohlen ist reject (streng).',
    ],
    'update' => [
        'title' => 'Aktualisierung', 'installed' => 'Installiert: Kasmin :version', 'latest' => 'Neueste Version: :version', 'notes' => 'Änderungen',
        'check' => 'Auf Aktualisierung prüfen', 'start' => 'Jetzt auf :version aktualisieren', 'confirm' => 'Kasmin jetzt aktualisieren? Vorher werden Datenbank und Programmdateien gesichert.',
        'how' => 'Ablauf: Voraussetzungen prüfen, Paket laden und Prüfsumme prüfen, Datenbank und Dateien sichern, Dateien ersetzen (bei Fehler zurück), danach Datenbank aktualisieren.',
        'precheck_failed' => 'Der Server erfüllt die Voraussetzungen der neuen Version nicht – siehe Liste.', 'up_to_date' => 'Kasmin ist aktuell.',
        'checksum' => 'Prüfsumme falsch – das Paket wurde nicht installiert.', 'rolled_back' => 'Fehler beim Ersetzen der Dateien, alter Stand wiederhergestellt: :error',
        'docker' => 'Diese Installation läuft in Docker. Bitte aktualisieren Sie das Container-Image (z. B. ghcr.io/kasmin-org/kasmin:latest) statt einer Aktualisierung im laufenden Container.',
        'done' => 'Kasmin :version ist installiert.', 'back' => 'Zurück zum Systemstatus',
    ],
    'schema' => [
        'title' => [
            'not_installed' => 'Kasmin ist noch nicht installiert',
            'upgrade_required' => 'Aktualisierung erforderlich',
            'database_newer' => 'Die Datenbank ist neuer als diese Version',
        ],
        'text' => [
            'not_installed' => 'Die Datenbank ist leer. Bitte führen Sie die Installation aus.',
            'upgrade_required' => 'Diese Kasmin-Version ist neuer als die Datenbank. Jemand mit Administrationsrechten muss die Aktualisierung ausführen, bevor Kasmin wieder genutzt werden kann.',
            'database_newer' => 'Die Datenbank wurde von einer neueren Kasmin-Version aktualisiert. Ein Zurückwechseln auf ältere Versionen ist nicht möglich. Bitte installieren Sie wieder die neuere Version.',
        ],
        'versions' => 'Installierte Version: :code · Datenbank: :database',
    ],
];
