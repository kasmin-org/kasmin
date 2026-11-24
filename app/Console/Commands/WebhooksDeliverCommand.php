<?php

namespace App\Console\Commands;

use App\Support\Webhooks\Webhooks;
use Illuminate\Console\Command;

class WebhooksDeliverCommand extends Command
{
    protected $signature = 'kasmin:webhooks:deliver';

    protected $description = 'Delivers pending webhook events (runs every minute from the scheduler)';

    public function handle(Webhooks $webhooks): int
    {
        $this->info('Delivered: '.$webhooks->deliver());

        return self::SUCCESS;
    }
}
