<?php

namespace App\Console\Commands;

use App\Support\Backup\Backups;
use App\Support\Backup\RemoteBackup;
use Illuminate\Console\Command;

class BackupCommand extends Command
{
    protected $signature = 'kasmin:backup';

    protected $description = 'Writes a database backup and removes old daily ones (runs daily from the scheduler)';

    public function handle(Backups $backups): int
    {
        $name = $backups->daily();
        $this->info('Backup written: '.$name);
        if (RemoteBackup::configured()) {
            $path = collect($backups->all())->firstWhere('name', $name)['path'] ?? null;
            $this->info('Copy outside the server: '.($path !== null && app(RemoteBackup::class)->upload($path) ? 'sent' : 'FAILED – '.RemoteBackup::state()['error']));
        }

        return self::SUCCESS;
    }
}
