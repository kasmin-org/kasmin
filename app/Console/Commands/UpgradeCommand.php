<?php

namespace App\Console\Commands;

use App\Support\Backup\DatabaseBackup;
use App\Support\Schema\SchemaGuard;
use App\Support\Schema\SchemaStatus;
use Illuminate\Console\Command;

/**
 * Upgrades the database to the installed code version: backup, lock, migrations, version record.
 */
class UpgradeCommand extends Command
{
    protected $signature = 'kasmin:upgrade {--no-backup : Skip the automatic database backup}';

    protected $description = 'Upgrade the database to the installed Kasmin version (with automatic backup)';

    public function handle(SchemaGuard $guard, DatabaseBackup $backup): int
    {
        $status = $guard->status(fresh: true);

        if ($status === SchemaStatus::DatabaseNewer) {
            $this->error(__('system.schema.text.database_newer'));

            return self::FAILURE;
        }
        if ($status === SchemaStatus::NotInstalled) {
            $this->error(__('system.schema.text.not_installed'));

            return self::FAILURE;
        }

        $lock = fopen(storage_path('framework/kasmin-upgrade.lock'), 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            $this->error('Another upgrade is running.');

            return self::FAILURE;
        }

        try {
            if ($status === SchemaStatus::UpgradeRequired && ! $this->option('no-backup')) {
                $file = $backup->create('before-'.$guard->codeVersion());
                $this->info("Backup written: {$file}");
            }

            $this->call('migrate', ['--force' => true]);
            $guard->recordCodeVersion();
            $this->info('Database is at version '.$guard->codeVersion().'.');

            return self::SUCCESS;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
