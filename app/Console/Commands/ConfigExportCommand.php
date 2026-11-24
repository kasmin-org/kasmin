<?php

namespace App\Console\Commands;

use App\Support\Install\ConfigTransfer;
use Illuminate\Console\Command;

class ConfigExportCommand extends Command
{
    protected $signature = 'kasmin:config:export {file : JSON file to write} {--templates-only} {--automations-only}';

    protected $description = 'Exports mail templates and automations as JSON (to share or keep in version control)';

    public function handle(ConfigTransfer $transfer): int
    {
        $data = $transfer->export(! $this->option('automations-only'), ! $this->option('templates-only'));
        file_put_contents((string) $this->argument('file'), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
        $this->info('Written: '.count($data['mail_templates'] ?? []).' templates, '.count($data['automations'] ?? []).' automations.');

        return self::SUCCESS;
    }
}
