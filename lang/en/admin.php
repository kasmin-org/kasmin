<?php

return [
    'nav' => ['people' => 'People'],
    'person' => [
        'singular' => 'Person', 'plural' => 'People',
        'section_person' => 'Person', 'section_contacts' => 'Contact data',
        'first_name' => 'First name', 'last_name' => 'Last name', 'birth_date' => 'Date of birth', 'age' => 'Age',
        'birth_date_hint' => 'Used for the age limits of events and roles.',
        'gender' => 'Gender', 'locale' => 'Language',
        'add_contact' => 'Add contact', 'contact_type' => 'Type', 'contact_value' => 'Address/number',
        'email' => 'E-mail', 'phone' => 'Phone', 'purposes' => 'Used for', 'primary' => 'Main contact',
        'filter_children' => 'Has legal guardians', 'filter_guardians' => 'Is a legal guardian',
    ],
    'household' => [
        'singular' => 'Family', 'plural' => 'Families', 'name' => 'Name', 'members' => 'Members of the family',
        'guardians' => 'Legal guardians', 'children' => 'Children',
        'street' => 'Street', 'postal_code' => 'Postcode', 'city' => 'City', 'country' => 'Country (ISO)',
    ],
    'genders' => ['female' => 'female', 'male' => 'male', 'diverse' => 'diverse', 'unspecified' => 'not specified'],
    'purposes' => ['login' => 'Login', 'information' => 'Information', 'invoices' => 'Invoices', 'emergency' => 'Emergency'],
];
