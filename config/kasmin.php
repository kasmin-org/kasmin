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

    /*
     * Token for the HTTP hooks (upgrade after deployment, web cron, demo set-up). Hooks are disabled when empty.
     */
    'hook_token' => env('KASMIN_HOOK_TOKEN'),

    /*
     * "docker" in the official image: the self-update is disabled there (update the image instead).
     */
    'runtime' => env('KASMIN_RUNTIME'),

    /*
     * Time of the daily notification digest (weekly digests go out on Mondays at the same time), NT04.
     */
    'digest_time' => env('KASMIN_DIGEST_TIME', '07:00'),

    // Days an approval request of an automation stays open (AT04, AT05).
    // API key for `kasmin:import:people --webling` (read permission), only needed during a migration.
    'webling_key' => env('KASMIN_WEBLING_KEY'),

    'approval_days' => (int) env('KASMIN_APPROVAL_DAYS', 7),

    /*
     * Demo instances (APP_ENV=demo): language of the demo data, first administrator and e-mail domain of the
     * fictitious people (a catch-all mailbox makes their login links readable).
     */
    'demo' => [
        'locale' => env('KASMIN_DEMO_LOCALE', 'en'),
        'admin_email' => env('KASMIN_DEMO_ADMIN_EMAIL', 'admin@example.org'),
        'email_domain' => env('KASMIN_DEMO_EMAIL_DOMAIN', 'example.org'),
    ],
];
