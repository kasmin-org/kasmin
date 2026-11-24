<?php

namespace App\Console\Commands;

use App\Support\Install\ConfigTransfer;
use Illuminate\Console\Command;

class ConfigImportCommand extends Command
{
    protected $signature = 'kasmin:config:import {file : JSON file from kasmin:config:export} {--update : replace existing entries} {--dry-run}';

    protected $description = 'Imports mail templates and automations from JSON (new automations are switched off)';

    public function handle(ConfigTransfer $transfer): int
    {
        try {
            $result = $transfer->import((array) json_decode((string) file_get_contents((string) $this->argument('file')), true), (bool) $this->option('update'), (bool) $this->option('dry-run'));
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        foreach ($result as $kind => $items) {
            $this->line($kind.': '.(implode(', ', $items) ?: '–'));
        }

        return self::SUCCESS;
    }
}
