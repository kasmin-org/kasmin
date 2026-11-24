<?php

namespace App\Console\Commands;

use App\Support\Events\Guests;
use App\Support\Events\PublicForms;
use App\Support\Privacy\Privacy;
use Illuminate\Console\Command;

class PrivacyRetentionCommand extends Command
{
    protected $signature = 'kasmin:privacy:retention {--dry-run}';

    protected $description = 'Deletes confidential form answers after the retention period (runs daily from the scheduler)';

    public function handle(Privacy $privacy): int
    {
        if ($this->option('dry-run')) {
            foreach ($privacy->retentionPlan() as $entry) {
                $this->line($entry['person']->fullName().': '.$entry['responses'].' answer(s), last event/answer '.$entry['since']);
            }

            return self::SUCCESS;
        }
        $this->info('Deleted answers: '.$privacy->runRetention());
        $this->info('Expired forms and invitations: '.(app(PublicForms::class)->expire() + app(Guests::class)->expire()));

        return self::SUCCESS;
    }
}
