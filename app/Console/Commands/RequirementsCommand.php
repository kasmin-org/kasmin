<?php

namespace App\Console\Commands;

use App\Support\Install\Requirements;
use Illuminate\Console\Command;

/** Prints the requirements of this version as JSON (published with every release for the self-updater). */
class RequirementsCommand extends Command
{
    protected $signature = 'kasmin:requirements';

    protected $description = 'Print the server requirements of this Kasmin version as JSON';

    public function handle(): int
    {
        $this->line((string) json_encode(['version' => config('kasmin.version'), 'php' => Requirements::MIN_PHP, 'extensions' => Requirements::EXTENSIONS], JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
