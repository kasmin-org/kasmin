<?php

return [
    'nav' => ['people' => 'People', 'events' => 'Events'],
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
    'event' => [
        'singular' => 'Event', 'plural' => 'Events', 'title' => 'Title', 'series' => 'Series', 'status' => 'Status',
        'starts_at' => 'Start', 'ends_at' => 'End', 'location' => 'Location', 'meeting_point' => 'Meeting point', 'description' => 'Description',
        'binding' => 'Registration binding immediately', 'binding_hint' => 'Off: registrations are requests that the team confirms.',
        'age_reference' => 'Reference date for ages', 'age_reference_hint' => 'Empty = start of the event.',
        'roles' => 'Roles, places and age limits', 'add_role' => 'Add role', 'role' => 'Role', 'capacity' => 'Places',
        'reserve_places' => 'Reserve places', 'reserve_hint' => 'Not given out; the waitlist starts earlier. Only the team assigns them by hand with a reason.',
        'min_age' => 'Minimum age', 'max_age' => 'Maximum age', 'registered_by' => 'Registered by',
        'registered_by_options' => ['guardian' => 'Legal guardians', 'self' => 'The person', 'admin' => 'Team only'],
        'participants' => 'Participants', 'waitlist_position' => 'Waitlist', 'reserve' => 'Reserve place', 'registered_at' => 'Registered at',
        'add_participant' => 'Register a person', 'assign_reserve' => 'Assign reserve place', 'reserve_reason' => 'Reason (internal)',
        'change_status' => 'Change status', 'occupied' => 'Occupancy', 'waitlist_short' => 'WL',
    ],
    'series' => ['singular' => 'Event series', 'plural' => 'Event series'],
    'group' => ['singular' => 'Group', 'plural' => 'Groups'],
    'genders' => ['female' => 'female', 'male' => 'male', 'diverse' => 'diverse', 'unspecified' => 'not specified'],
    'purposes' => ['login' => 'Login', 'information' => 'Information', 'invoices' => 'Invoices', 'emergency' => 'Emergency'],
];
