<?php

return [
    'title' => 'Löschung und Aufbewahrung', 'requests' => 'Löschanfragen', 'requests_hint' => 'Anfragen über die Links in den E-Mails. Prüfen, was gelöscht werden kann und was aufbewahrt werden muss, dann anonymisieren. Die Person informieren Sie selbst.',
    'no_requests' => 'Keine offenen Löschanfragen.', 'review' => 'Prüfen', 'plan_for' => ':name anonymisieren',
    'will_delete' => 'Wird gelöscht', 'will_keep' => 'Bleibt erhalten', 'reason' => 'Grund (für das Audit-Protokoll)', 'default_reason' => 'Löschanfrage (DSGVO Art. 17)',
    'anonymise' => 'Jetzt anonymisieren', 'confirm' => 'Diese Person anonymisieren? Das kann nicht rückgängig gemacht werden.', 'done' => 'Die Person wurde anonymisiert.',
    'has_roles' => 'Diese Person hat noch eine aktive Rolle. Bitte zuerst die Rolle entfernen.',
    'anonymous' => 'Anonymisiert',
    'items' => ['contact_points' => 'Kontaktdaten', 'login' => 'Zugang', 'form_responses' => 'Formularangaben (inkl. Sicherheitsblätter)', 'mails' => 'E-Mails an die Person',
        'family_links' => 'Verbindungen zu Familie und Erziehungsberechtigten', 'groups' => 'Gruppenmitgliedschaften', 'invoices' => 'Rechnungen', 'participations' => 'Teilnahmen', 'audit' => 'Audit-Protokoll'],
    'reason_invoices' => 'gesetzliche Aufbewahrungsfrist (:years Jahre); der Name bleibt auf der Rechnung',
    'reason_statistics' => 'bleiben anonymisiert für Statistiken erhalten (Geburtsjahr, Rolle, Jahr)',
    'reason_audit' => 'das Audit-Protokoll ist unveränderlich; es hält fest, dass anonymisiert wurde',
    'retention' => 'Aufbewahrung vertraulicher Daten', 'retention_hint' => 'Angaben in Formularen mit Gesundheits- oder Identitätsdaten werden :years Jahre nach der letzten Veranstaltung der Person gelöscht (Einstellung unter „Organisation und Rechnungen“). Das läuft jede Nacht automatisch.',
    'retention_none' => 'Derzeit nichts zu löschen.', 'retention_run' => 'Jetzt löschen', 'retention_confirm' => 'Diese Angaben jetzt löschen?', 'retention_done' => ':count Angabe(n) gelöscht.',
    'answers' => '{1} 1 Angabe|[2,*] :count Angaben', 'since' => 'letzte Veranstaltung oder Angabe',
    'audit' => 'Audit-Protokoll', 'audit_entry' => 'Audit-Eintrag', 'time' => 'Zeit', 'action' => 'Aktion', 'actor' => 'Akteur', 'object' => 'Objekt',
    'action_prefix' => 'Aktion beginnt mit', 'from' => 'Von', 'to' => 'Bis', 'changes' => 'Änderungen', 'details' => 'Details',
    'consent' => 'Einwilligung', 'consents' => 'Einwilligungen und Abmeldungen', 'kind' => 'Art', 'granted' => 'Erteilt', 'source' => 'Quelle',
];
