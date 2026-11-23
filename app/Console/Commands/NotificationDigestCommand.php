<?php

namespace App\Console\Commands;

use App\Support\Mail\Notifier;
use Illuminate\Console\Command;

class NotificationDigestCommand extends Command
{
    protected $signature = 'kasmin:notifications:digest';

    protected $description = 'Send the daily (and on Mondays the weekly) notification digests to administrators';

    public function handle(Notifier $notifier): int
    {
        $this->info($notifier->digests().' digests queued');

        return self::SUCCESS;
    }
}
