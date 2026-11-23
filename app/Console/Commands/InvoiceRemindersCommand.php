<?php

namespace App\Console\Commands;

use App\Support\Finance\Reminders;
use Illuminate\Console\Command;

class InvoiceRemindersCommand extends Command
{
    protected $signature = 'kasmin:invoices:remind';

    protected $description = 'Queues payment reminders before and after the due date (runs daily from the scheduler)';

    public function handle(Reminders $reminders): int
    {
        $this->info('Reminders queued: '.$reminders->run());

        return self::SUCCESS;
    }
}
