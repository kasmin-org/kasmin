<?php

namespace App\Console\Commands;

use App\Support\Events\EventStatus;
use App\Support\Events\ReservePlaces;
use App\Support\People\GroupWaitlist;
use Illuminate\Console\Command;

class EventStatusCommand extends Command
{
    protected $signature = 'kasmin:events:status';

    protected $description = 'Changes the status of events whose date is reached (runs from the scheduler)';

    public function handle(EventStatus $status): int
    {
        $this->info('Changed: '.$status->tick());
        $this->info('Reserve places: '.app(ReservePlaces::class)->tick());
        $this->info('Expired group offers: '.app(GroupWaitlist::class)->expire());

        return self::SUCCESS;
    }
}
