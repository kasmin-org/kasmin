<?php

return [
    'status' => [
        'backup' => 'Backup', 'backup_none' => 'There is no backup yet.', 'backup_last' => 'Last backup :time (:size MB)',
        'title' => 'System status', 'checks' => 'Checks', 'docs' => 'Guide', 'more' => 'Learn more', 'dismiss' => 'Dismiss',
        'levels' => ['ok' => 'OK', 'warning' => 'Warning', 'error' => 'Error', 'info' => 'Info'],
        'php_eol' => 'security updates until :date', 'php_unknown' => 'end of support unknown',
        'requirements' => 'PHP extensions, functions and write permissions', 'missing' => 'missing',
        'cron' => 'Cron job (scheduler)', 'cron_last' => 'last run :time', 'cron_never' => 'never ran – please set it up',
        'queue' => 'Mail queue', 'queue_old' => ':count mails waiting longer than 30 minutes',
        'database' => 'Database', 'latest' => 'latest version: :version', 'latest_unknown' => 'latest version not reachable',
    ],
    'dns' => [
        'title' => 'Mail: DNS records', 'intro' => 'SPF, DKIM and DMARC of the sender domains. DMARC with p=reject is recommended.',
        'direct' => 'direct delivery', 'suggestion' => 'Suggested DNS record:', 'none' => 'No senders configured yet.', 'server_settings' => 'server settings',
        'spf_missing' => 'No SPF record – mails may be classified as spam.',
        'dkim_direct' => 'PHP can sign with DKIM; the public key must be in DNS.',
        'dkim_unsupported' => 'PHP cannot sign with DKIM here (OpenSSL missing) – direct delivery not recommended.',
        'dkim_relay' => 'No DKIM record found. Enter the selector of your provider at the mail account.',
        'dmarc_missing' => 'No DMARC record.', 'dmarc_not_reject' => 'DMARC policy is :policy, reject (strict) is recommended.',
    ],
    'update' => [
        'title' => 'Update', 'installed' => 'Installed: Kasmin :version', 'latest' => 'Latest version: :version', 'notes' => 'Release notes',
        'check' => 'Check for updates', 'start' => 'Update to :version now', 'confirm' => 'Update Kasmin now? Database and program files are backed up first.',
        'how' => 'Steps: check requirements, download and verify the package, back up database and files, replace the files (rolled back on error), then update the database.',
        'precheck_failed' => 'The server does not meet the requirements of the new version – see the list.', 'up_to_date' => 'Kasmin is up to date.',
        'checksum' => 'Checksum mismatch – the package was not installed.', 'rolled_back' => 'Error while replacing the files, previous state restored: :error',
        'docker' => 'This installation runs in Docker. Please update the container image (e.g. ghcr.io/kasmin-org/kasmin:latest) instead of updating inside the running container.',
        'done' => 'Kasmin :version is installed.', 'back' => 'Back to the system status',
    ],
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
