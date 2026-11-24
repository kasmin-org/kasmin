<?php

return [
    'singular' => 'Webhook', 'plural' => 'Webhooks', 'name' => 'Name', 'events' => 'Events', 'active' => 'Active',
    'url_hint' => 'HTTPS only. Kasmin sends a POST with JSON and the headers X-Kasmin-Event, X-Kasmin-Delivery, X-Kasmin-Timestamp and X-Kasmin-Signature (sha256=HMAC of "timestamp.body" with the secret).',
    'secret_title' => 'Signing secret', 'secret_hint' => 'Store it in the receiving system – it is shown only this once:',
    'new_secret' => 'New secret', 'new_secret_hint' => 'The old secret stops working at once.',
    'deliveries' => 'Deliveries', 'time' => 'Time', 'event' => 'Event', 'status' => 'Status', 'attempts' => 'Attempts', 'error' => 'Error', 'retry' => 'Send again',
];
