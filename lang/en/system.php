<?php

return [
    'schema' => [
        'title' => [
            'not_installed' => 'Kasmin is not installed yet',
            'upgrade_required' => 'Upgrade required',
            'database_newer' => 'Database is newer than this version',
        ],
        'text' => [
            'not_installed' => 'The database is empty. Please run the installer.',
            'upgrade_required' => 'This version of Kasmin is newer than the database. An administrator has to run the upgrade before Kasmin can be used again.',
            'database_newer' => 'The database was upgraded by a newer version of Kasmin. Downgrades are not supported. Please install the newer version again.',
        ],
        'versions' => 'Installed version: :code · database: :database',
    ],
];
