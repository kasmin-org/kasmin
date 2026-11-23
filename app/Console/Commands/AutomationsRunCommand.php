<?php

namespace App\Console\Commands;

use App\Support\Automations\Engine;
use Illuminate\Console\Command;

class AutomationsRunCommand extends Command
{
    protected $signature = 'kasmin:automations:run';

    protected $description = 'Starts due automations and handles expired approvals (runs hourly from the scheduler)';

    public function handle(Engine $engine): int
    {
        $this->info('Runs started: '.$engine->tick());

        return self::SUCCESS;
    }
}
