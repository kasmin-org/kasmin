<?php

namespace App\Console\Commands;

use App\Models\Mailing;
use App\Support\Mail\Sender;
use Illuminate\Console\Command;

class MailSendCommand extends Command
{
    protected $signature = 'kasmin:mail:send {--limit=100}';

    protected $description = 'Send queued mails (respecting the rate limits of the mail accounts)';

    public function handle(Sender $sender): int
    {
        $result = $sender->process((int) $this->option('limit'));
        Mailing::query()->where('status', 'sending')->whereDoesntHave('mails', fn ($q) => $q->whereIn('status', ['queued', 'sending']))->update(['status' => 'sent']);
        $this->info("sent {$result['sent']}, failed {$result['failed']}, deferred {$result['deferred']}");

        return self::SUCCESS;
    }
}
