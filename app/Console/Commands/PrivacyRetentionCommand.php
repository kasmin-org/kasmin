<?php

namespace App\Console\Commands;

use App\Http\Middleware\Idempotency;
use App\Support\Events\Guests;
use App\Support\Events\PublicForms;
use App\Support\Mail\MailRetention;
use App\Support\People\Addresses;
use App\Support\People\Guardians;
use App\Support\People\SignUps;
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
        $this->info('Deleted mails: '.app(MailRetention::class)->run());
        $this->info('Expired forms and invitations: '.(app(PublicForms::class)->expire() + app(Guests::class)->expire() + app(Guardians::class)->expire() + app(SignUps::class)->expire() + app(Addresses::class)->expire()));
        $this->info('Expired idempotency keys: '.Idempotency::prune());
        $this->info('Ended guardianships: '.app(Guardians::class)->endAtAge());

        return self::SUCCESS;
    }
}
