<?php

return [
    'status' => [
        'backup' => 'Datensicherung', 'backup_none' => 'Es gibt noch keine Sicherung.', 'backup_last' => 'Letzte Sicherung :time (:size MB)',
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
    'upgrade' => [
        'banner' => 'Eine neue Version wurde installiert. Bis das Upgrade abgeschlossen ist, können sich nur Administratorinnen und Administratoren anmelden.',
        'only_administrators' => 'Ein Upgrade steht an. Bis es abgeschlossen ist, können sich nur Administratorinnen und Administratoren anmelden – mit einem neuen Anmeldelink.',
        'title' => 'Kasmin aktualisieren', 'text' => 'Die Dateien einer neuen Version sind installiert. Die Datenbank muss aktualisiert werden, bevor Kasmin wieder benutzt werden kann.',
        'steps' => '{1} 1 Schritt|[2,*] :count Schritte', 'backup' => 'Zuerst wird eine Sicherung der Datenbank erstellt.', 'wait' => 'Das kann einige Minuten dauern. Bitte die Seite nicht schließen.',
        'start' => 'Jetzt aktualisieren', 'again' => 'Erneut versuchen', 'running' => 'Upgrade läuft …', 'done' => 'Kasmin ist jetzt auf Version :version. Alle können sich wieder anmelden.', 'continue' => 'Weiter zur Verwaltung',
        'failed' => 'Das Upgrade wurde nicht abgeschlossen. Es ist nichts verloren: Die vorher erstellte Sicherung liegt im Sicherungsordner. Bitte erneut versuchen; schlägt es wieder fehl, die Details unten für die Support-Anfrage aufbewahren.', 'details' => 'Details',
    ],
    'auto_update' => [
        'title' => 'Automatische Updates', 'enabled' => 'Neue Versionen automatisch installieren', 'days' => 'Tage nach der Veröffentlichung', 'time' => 'Uhrzeit',
        'hint' => 'Eine neue Version wird in der Nacht installiert, sobald sie so viele Tage veröffentlicht ist. Administratorinnen und Administratoren werden zwei Tage vorher und danach informiert. Erscheint dazwischen eine neuere Version, beginnt die Wartezeit von vorne; eine Version, die kurz darauf korrigiert wird, wird so übersprungen. Braucht den Cronjob.',
        'notice_subject' => 'Update auf :version am :date', 'notice' => 'Kasmin :version wird am :date automatisch installiert. Vorher wird eine Sicherung erstellt. Sie müssen nichts tun; Sie können auch früher aktualisieren oder automatische Updates in den Einstellungen ausschalten. Änderungen: :url',
        'cancelled_subject' => 'Update auf :old abgesagt', 'cancelled' => 'Das angekündigte Update auf Kasmin :old ist abgesagt, weil die neuere Version :new veröffentlicht wurde. Die Wartezeit beginnt von vorne: :new ist für :date geplant.',
        'done_subject' => 'Auf :version aktualisiert', 'done' => 'Kasmin wurde automatisch von :from auf :version aktualisiert. Das sind die Änderungen:',
        'failed_subject' => 'Update auf :version hat nicht funktioniert', 'failed' => 'Das automatische Update auf Kasmin :version hat nicht funktioniert; die bisherige Version läuft weiter. Morgen wird es erneut versucht. Grund: :error',
    ],
];
