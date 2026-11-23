<?php

return [
    'nav' => ['people' => 'Personen'],
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
    'genders' => ['female' => 'weiblich', 'male' => 'männlich', 'diverse' => 'divers', 'unspecified' => 'keine Angabe'],
    'purposes' => ['login' => 'Anmeldung', 'information' => 'Informationen', 'invoices' => 'Rechnungen', 'emergency' => 'Notfall'],
];
