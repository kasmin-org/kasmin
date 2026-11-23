<?php

namespace App\Console\Commands;

use App\Support\System\SystemNotifications;
use App\Support\System\SystemStatus;
use Illuminate\Console\Command;

class SystemCheckCommand extends Command
{
    protected $signature = 'kasmin:system:check';

    protected $description = 'Check PHP support, new Kasmin releases and the cron job; raise system notifications for administrators';

    public function handle(SystemStatus $status, SystemNotifications $notifications): int
    {
        $status->notify($notifications);
        foreach ($status->checks() as $check) {
            $this->line(str_pad($check['status'], 8).$check['label'].($check['detail'] ? ' – '.$check['detail'] : ''));
        }

        return self::SUCCESS;
    }
}
