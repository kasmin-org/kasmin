<?php

return [
    'singular' => 'Webhook', 'plural' => 'Webhooks', 'name' => 'Name', 'events' => 'Ereignisse', 'active' => 'Aktiv',
    'url_hint' => 'Nur HTTPS. Kasmin sendet einen POST mit JSON und den Headern X-Kasmin-Event, X-Kasmin-Delivery, X-Kasmin-Timestamp und X-Kasmin-Signature (sha256=HMAC über „Zeitstempel.Body“ mit dem Geheimnis).',
    'secret_title' => 'Signatur-Geheimnis', 'secret_hint' => 'Im empfangenden System hinterlegen – es wird nur dieses eine Mal angezeigt:',
    'new_secret' => 'Neues Geheimnis', 'new_secret_hint' => 'Das alte Geheimnis gilt sofort nicht mehr.',
    'deliveries' => 'Zustellungen', 'time' => 'Zeit', 'event' => 'Ereignis', 'status' => 'Status', 'attempts' => 'Versuche', 'error' => 'Fehler', 'retry' => 'Erneut senden',
];
