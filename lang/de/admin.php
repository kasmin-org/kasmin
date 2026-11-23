<?php

return [
    'nav' => ['people' => 'Personen', 'events' => 'Veranstaltungen'],
    'person' => [
        'singular' => 'Person', 'plural' => 'Personen',
        'section_person' => 'Person', 'section_contacts' => 'Kontaktdaten',
        'first_name' => 'Vorname', 'last_name' => 'Nachname', 'birth_date' => 'Geburtsdatum', 'age' => 'Alter',
        'birth_date_hint' => 'Wird für Altersgrenzen von Veranstaltungen und Rollen verwendet.',
        'gender' => 'Geschlecht', 'locale' => 'Sprache',
        'add_contact' => 'Kontakt hinzufügen', 'contact_type' => 'Art', 'contact_value' => 'Adresse/Nummer',
        'email' => 'E-Mail', 'phone' => 'Telefon', 'purposes' => 'Verwendung', 'primary' => 'Hauptkontakt',
        'filter_children' => 'Hat Erziehungsberechtigte', 'filter_guardians' => 'Ist erziehungsberechtigt',
    ],
    'household' => [
        'singular' => 'Familie', 'plural' => 'Familien', 'name' => 'Name', 'members' => 'Mitglieder der Familie',
        'guardians' => 'Erziehungsberechtigte', 'children' => 'Kinder',
        'street' => 'Straße', 'postal_code' => 'PLZ', 'city' => 'Ort', 'country' => 'Land (ISO)',
    ],
    'event' => [
        'singular' => 'Veranstaltung', 'plural' => 'Veranstaltungen', 'title' => 'Titel', 'series' => 'Reihe', 'status' => 'Status',
        'starts_at' => 'Beginn', 'ends_at' => 'Ende', 'location' => 'Ort', 'meeting_point' => 'Treffpunkt', 'description' => 'Beschreibung',
        'binding' => 'Anmeldung sofort verbindlich', 'binding_hint' => 'Aus: Anmeldungen sind Anfragen, die das Team bestätigt.',
        'age_reference' => 'Stichtag für das Alter', 'age_reference_hint' => 'Leer = Beginn der Veranstaltung.',
        'roles' => 'Rollen, Plätze und Altersgrenzen', 'add_role' => 'Rolle hinzufügen', 'role' => 'Rolle', 'capacity' => 'Plätze',
        'reserve_places' => 'Reserveplätze', 'reserve_hint' => 'Werden nicht vergeben; die Warteliste beginnt früher. Nur das Team vergibt sie manuell mit Grund.',
        'min_age' => 'Mindestalter', 'max_age' => 'Höchstalter', 'registered_by' => 'Anmeldung durch',
        'registered_by_options' => ['guardian' => 'Erziehungsberechtigte', 'self' => 'Person selbst', 'admin' => 'Nur Team'],
        'participants' => 'Teilnehmende', 'waitlist_position' => 'Warteliste', 'reserve' => 'Reserveplatz', 'registered_at' => 'Angemeldet am',
        'add_participant' => 'Person anmelden', 'assign_reserve' => 'Reserveplatz vergeben', 'reserve_reason' => 'Grund (intern)',
        'change_status' => 'Status ändern', 'occupied' => 'Belegung', 'waitlist_short' => 'WL',
    ],
    'series' => ['singular' => 'Veranstaltungsreihe', 'plural' => 'Veranstaltungsreihen'],
    'group' => ['singular' => 'Gruppe', 'plural' => 'Gruppen'],
    'genders' => ['female' => 'weiblich', 'male' => 'männlich', 'diverse' => 'divers', 'unspecified' => 'keine Angabe'],
    'purposes' => ['login' => 'Anmeldung', 'information' => 'Informationen', 'invoices' => 'Rechnungen', 'emergency' => 'Notfall'],
];
