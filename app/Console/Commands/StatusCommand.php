<?php

namespace App\Console\Commands;

use App\Support\Schema\SchemaGuard;
use Illuminate\Console\Command;

class StatusCommand extends Command
{
    protected $signature = 'kasmin:status';

    protected $description = 'Show the Kasmin version and the state of the database schema';

    public function handle(SchemaGuard $guard): int
    {
        $this->table(['', ''], [
            ['Code version', $guard->codeVersion()],
            ['Database version', $guard->databaseVersion() ?? '–'],
            ['Schema status', $guard->status(fresh: true)->value],
            ['Pending migrations', (string) count($guard->pendingMigrations())],
            ['Unknown migrations', (string) count($guard->unknownMigrations())],
        ]);

        return self::SUCCESS;
    }
}
