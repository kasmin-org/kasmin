<?php

return [
    'schema' => [
        'title' => [
            'not_installed' => 'Kasmin ist noch nicht installiert',
            'upgrade_required' => 'Aktualisierung erforderlich',
            'database_newer' => 'Die Datenbank ist neuer als diese Version',
        ],
        'text' => [
            'not_installed' => 'Die Datenbank ist leer. Bitte führen Sie die Installation aus.',
            'upgrade_required' => 'Diese Kasmin-Version ist neuer als die Datenbank. Jemand mit Administrationsrechten muss die Aktualisierung ausführen, bevor Kasmin wieder genutzt werden kann.',
            'database_newer' => 'Die Datenbank wurde von einer neueren Kasmin-Version aktualisiert. Ein Zurückwechseln auf ältere Versionen ist nicht möglich. Bitte installieren Sie wieder die neuere Version.',
        ],
        'versions' => 'Installierte Version: :code · Datenbank: :database',
    ],
];
