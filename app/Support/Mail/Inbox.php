<?php

namespace App\Support\Mail;

use App\Models\ContactPoint;
use App\Models\IncomingMail;
use App\Models\MailAccount;
use App\Models\OutgoingMail;
use DirectoryTree\ImapEngine\Mailbox;
use DirectoryTree\ImapEngine\MessageInterface;
use Illuminate\Support\Str;
use Throwable;

/**
 * Reads new messages of "send and receive" accounts (IMAP): assigns replies to people/families (ML04) and marks
 * bounced mails (MS05). Messages stay in the mailbox; only metadata and the plain text are stored.
 */
class Inbox
{
    /** @return array{replies: int, bounces: int} */
    public function fetch(MailAccount $account): array
    {
        $result = ['replies' => 0, 'bounces' => 0];
        if (! $account->canReceive()) {
            return $result;
        }
        $mailbox = new Mailbox([
            'host' => $account->imap_host, 'port' => $account->imap_port ?? 993, 'encryption' => 'ssl',
            'username' => $account->imap_username, 'password' => $account->imap_password, 'validate_cert' => true,
        ]);
        $since = $account->last_imap_check_at ?? now()->subDays(2);
        $messages = $mailbox->inbox()->messages()->since($since)->withHeaders()->withBody()->get();
        foreach ($messages as $message) {
            /** @var MessageInterface $message */
            $id = (string) $message->messageId();
            if ($id !== '' && IncomingMail::query()->where('message_id', $id)->exists()) {
                continue;
            }
            try {
                $this->handle($account, $message, $id) === 'bounce' ? $result['bounces']++ : $result['replies']++;
            } catch (Throwable) {
                // One unreadable message must not stop the others.
            }
        }
        $account->forceFill(['last_imap_check_at' => now()])->save();

        return $result;
    }

    private function handle(MailAccount $account, MessageInterface $message, string $id): string
    {
        $from = Str::lower((string) $message->from()?->email());
        $subject = (string) $message->subject();
        $text = (string) $message->text();
        $isBounce = str_starts_with($from, 'mailer-daemon@') || str_starts_with($from, 'postmaster@')
            || preg_match('/(undeliver|delivery status notification|returned mail|unzustellbar)/i', $subject) === 1;

        $original = null;
        $references = $message->inReplyTo();
        $inReplyTo = (string) ($references[0] ?? '');
        if ($inReplyTo !== '') {
            $original = OutgoingMail::query()->where('message_id', trim($inReplyTo, '<>'))->first();
        }
        if ($original === null && $isBounce && preg_match('/([0-9a-f-]{36})@/i', $text, $m)) {
            $original = OutgoingMail::query()->where('uuid', $m[1])->first();
        }

        $personId = $original->person_id ?? ContactPoint::query()->where('type', 'email')->where('value', $from)->value('person_id');
        IncomingMail::query()->create([
            'mail_account_id' => $account->id, 'message_id' => $id ?: null, 'in_reply_to' => $inReplyTo ?: null,
            'from_address' => $from, 'subject' => Str::limit($subject, 250), 'text' => Str::limit($text, 20000),
            'person_id' => $personId, 'household_id' => $original?->household_id, 'outgoing_mail_id' => $original?->id,
            'kind' => $isBounce ? 'bounce' : 'reply', 'received_at' => $message->date() ?? now(),
        ]);
        if ($isBounce && $original !== null) {
            $original->forceFill(['status' => 'bounced'])->save();
            app(Notifier::class)->event('bounces', __('mail.notify_bounce', ['to' => $original->to_address]));
        }

        return $isBounce ? 'bounce' : 'reply';
    }
}
