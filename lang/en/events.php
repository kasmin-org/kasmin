<?php

return [
    'errors' => [
        'not_open' => 'Registration for this event is not possible at the moment.',
        'no_role' => 'Registration is not provided for this role.',
        'age' => 'The age does not match this event.',
        'already' => 'This person is already registered for this event.',
        'reason' => 'Please give a reason.',
        'no_reserve' => 'There is no reserve place left.',
    ],
    'status' => [
        'requested' => 'Requested', 'registered' => 'Registered', 'confirmed' => 'Confirmed', 'waitlist' => 'Waitlist',
        'offered' => 'Place offered', 'cancelled' => 'Cancelled', 'declined' => 'Declined', 'attended' => 'Attended', 'no_show' => 'No-show',
    ],
    'event_status' => [
        'draft' => 'Draft', 'announced' => 'Announced', 'open' => 'Registration open', 'closed' => 'Registration closed',
        'running' => 'Running', 'finished' => 'Finished', 'archived' => 'Archived',
    ],
    'roles' => ['child' => 'Child', 'junior_counsellor' => 'Junior counsellor', 'staff' => 'Team', 'guest' => 'Guest'],
    'series_types' => ['camp' => 'Camp', 'alumni' => 'Alumni event', 'meeting' => 'Meeting', 'other' => 'Other'],
    'waitlist_position' => 'Position :position of :total on the waitlist',
    'places_left' => ':count places left',
    'waitlist_only' => 'Waitlist only',
];
