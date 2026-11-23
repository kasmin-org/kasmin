<?php

namespace App\Console\Commands;

use App\Models\MailAccount;
use App\Support\Mail\Inbox;
use Illuminate\Console\Command;
use Throwable;

class MailFetchCommand extends Command
{
    protected $signature = 'kasmin:mail:fetch';

    protected $description = 'Read replies and bounces from the IMAP mailboxes of "send and receive" accounts';

    public function handle(Inbox $inbox): int
    {
        foreach (MailAccount::query()->whereNotNull('imap_host')->get() as $account) {
            try {
                $r = $inbox->fetch($account);
                $this->info("{$account->name}: {$r['replies']} replies, {$r['bounces']} bounces");
            } catch (Throwable $e) {
                $this->error("{$account->name}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
