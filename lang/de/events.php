<?php

return [
    'errors' => [
        'no_offer' => 'Für diesen Platz gibt es kein offenes Angebot (mehr).',
        'not_open' => 'Für diese Veranstaltung ist derzeit keine Anmeldung möglich.',
        'no_role' => 'Für diese Rolle ist keine Anmeldung vorgesehen.',
        'age' => 'Das Alter passt nicht zu dieser Veranstaltung.',
        'no_guests' => 'Es können keine weiteren Gäste eingeladen werden.',
        'pay_to_accept' => 'Dieser Platz wird durch Bezahlung der Rechnung angenommen.',
        'no_place' => 'Es gibt keinen freien Platz, oder die Person ist bereits angemeldet.',
        'already' => 'Diese Person ist für diese Veranstaltung bereits angemeldet.',
        'reason' => 'Bitte geben Sie einen Grund an.',
        'no_reserve' => 'Es ist kein Reserveplatz mehr frei.',
    ],
    'status' => [
        'requested' => 'Anfrage gestellt', 'registered' => 'Angemeldet', 'confirmed' => 'Bestätigt', 'waitlist' => 'Warteliste',
        'offered' => 'Platz angeboten', 'cancelled' => 'Storniert', 'declined' => 'Abgesagt', 'attended' => 'Teilgenommen', 'no_show' => 'Nicht erschienen',
    ],
    'event_status' => [
        'draft' => 'Entwurf', 'announced' => 'Angekündigt', 'open' => 'Anmeldung offen', 'closed' => 'Anmeldung geschlossen',
        'running' => 'Läuft', 'finished' => 'Beendet', 'archived' => 'Archiviert',
    ],
    'roles' => ['child' => 'Kind', 'junior_counsellor' => 'Nachwuchs-Betreuung', 'staff' => 'Team', 'guest' => 'Gast'],
    'series_types' => ['camp' => 'Lager', 'alumni' => 'Alumni-Veranstaltung', 'meeting' => 'Treffen', 'other' => 'Sonstiges'],
    'waitlist_position' => 'Platz :position von :total auf der Warteliste',
    'places_left' => 'Freie Plätze: :count',
    'waitlist_only' => 'Nur noch Warteliste',
    'notify_registration' => ':name – :event: :status',
];
