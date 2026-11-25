<?php

namespace App\Console\Commands;

use App\Support\Backup\Backups;
use Illuminate\Console\Command;

class BackupCommand extends Command
{
    protected $signature = 'kasmin:backup';

    protected $description = 'Writes a database backup and removes old daily ones (runs daily from the scheduler)';

    public function handle(Backups $backups): int
    {
        $this->info('Backup written: '.$backups->daily());

        return self::SUCCESS;
    }
}
