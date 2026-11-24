<?php

namespace App\Console\Commands;

use App\Support\Events\EventStatus;
use Illuminate\Console\Command;

class EventStatusCommand extends Command
{
    protected $signature = 'kasmin:events:status';

    protected $description = 'Changes the status of events whose date is reached (runs from the scheduler)';

    public function handle(EventStatus $status): int
    {
        $this->info('Changed: '.$status->tick());

        return self::SUCCESS;
    }
}
