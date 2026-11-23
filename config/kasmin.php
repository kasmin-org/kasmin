<?php

return [
    /*
     * Version of the installed code. Release builds replace the VERSION file with the tag (e.g. 0.1.0).
     */
    'version' => trim((string) @file_get_contents(base_path('VERSION'))) ?: '0.0.0-dev',

    /*
     * Directory for automatic database backups before upgrades (relative to storage/app).
     */
    'backup_path' => env('KASMIN_BACKUP_PATH', 'backups'),
];
