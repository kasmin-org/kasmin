<?php

return [
    'title' => 'Personen importieren', 'batch' => 'Import', 'batches' => 'Importe',
    'step_source' => '1. Quelle', 'step_mapping' => '2. Felder zuordnen',
    'mapping_hint' => 'Für jedes Kasmin-Feld die Spalte (oder das Webling-Feld) wählen, aus der es kommt. Leer lassen, was nicht importiert wird. Gesundheitsdaten werden hier nicht importiert.',
    'source_type' => 'Quelle', 'type_file' => 'CSV- oder Excel-Datei', 'locale' => 'Sprache neuer Personen',
    'source' => 'Name der Quelle', 'source_hint' => 'Kleinbuchstaben, z. B. alte-liste. Ein erneuter Import derselben Quelle aktualisiert statt zu verdoppeln (braucht eine Spalte source_id).',
    'file' => 'Datei (erste Zeile: Spaltennamen)', 'webling_url' => 'Webling-Adresse', 'webling_key' => 'Webling-API-Schlüssel',
    'webling_key_hint' => 'Ein API-Schlüssel mit Leserecht. Er wird nur für diesen Import verwendet und nicht gespeichert.',
    'read' => 'Quelle einlesen', 'preview' => 'Probelauf', 'import' => 'Importieren', 'need_file' => 'Datei und Namen der Quelle wählen.',
    'read_failed' => 'Die Quelle konnte nicht gelesen werden', 'read_done' => ':count Datensätze gelesen – Feldzuordnung prüfen, dann Probelauf starten.',
    'confirm' => 'Jetzt importieren? :create neu, :update aktualisiert. Der Import kann zurückgerollt werden, solange danach nichts geändert wird.',
    'imported' => 'Import :batch: :created neu, :updated aktualisiert, :skipped übersprungen.',
    'result' => 'Ergebnis', 'summary' => ':create neu, :update aktualisiert, :error mit Fehlern (übersprungen).',
    'action' => 'Aktion', 'name' => 'Name', 'messages' => 'Hinweise', 'actions' => ['create' => 'neu', 'update' => 'aktualisieren', 'error' => 'Fehler'],
    'families' => 'Familien', 'families_hint' => 'Hoch: gleiche E-Mail, Telefonnummer oder Erziehungsberechtigte – wird als eine Familie importiert. Mittel: nur gleiche Adresse – bitte selbst prüfen (zum Zusammenführen eine Spalte „family“ ergänzen).',
    'confidence' => ['high' => 'hoch', 'medium' => 'mittel'], 'rows' => 'Zeilen', 'no_families' => 'Keine Familien gefunden.',
    'time' => 'Zeit', 'by' => 'Durch', 'by_api' => 'API', 'status' => 'Status', 'statuses' => ['imported' => 'Importiert', 'rolled_back' => 'Zurückgerollt'],
    'stats' => ':created neu, :updated aktualisiert, :skipped übersprungen', 'rollback' => 'Zurückrollen',
    'rollback_hint' => 'Löscht, was dieser Import angelegt hat, und stellt wieder her, was er geändert hat. Nicht möglich, wenn Datensätze danach geändert wurden.',
    'rolled_back' => 'Import zurückgerollt.',
];
