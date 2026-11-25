<?php

namespace App\Support\Mail;

use App\Models\EventDocument;
use App\Models\Invoice;
use App\Models\MailAccount;
use App\Models\OutgoingMail;
use App\Models\SenderProfile;
use App\Support\Audit\Audit;
use App\Support\Finance\InvoicePdf;
use App\Support\Settings;
use DirectoryTree\ImapEngine\Mailbox;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Crypto\DkimSigner;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;
use Throwable;

/**
 * Processes the mail queue (MS01–MS06, MS09): rate limits per account, retries with backoff, IMAP copy of sent mails.
 * Called every minute by the scheduler (cron or web cron).
 */
class Sender
{
    public const MAX_ATTEMPTS = 5;

    /** @var array<int, TransportInterface> */
    private array $transports = [];

    /**
     * @return array{sent: int, failed: int, deferred: int}
     */
    public function process(int $limit = 100): array
    {
        $result = ['sent' => 0, 'failed' => 0, 'deferred' => 0];
        $mails = OutgoingMail::query()->where('status', 'queued')
            ->where(fn ($q) => $q->whereNull('send_after')->orWhere('send_after', '<=', now()))
            ->orderBy('id')->limit($limit)->with('senderProfile.account')->get();

        foreach ($mails as $mail) {
            $account = $mail->senderProfile->account ?? MailAccount::query()->where('is_default', true)->first();
            if ($account !== null && ! $this->withinRateLimits($account)) {
                $result['deferred']++;

                continue;
            }
            $this->send($mail, $account) ? $result['sent']++ : $result['failed']++;
        }

        return $result;
    }

    public function send(OutgoingMail $mail, ?MailAccount $account): bool
    {
        $mail->forceFill(['status' => 'sending', 'attempts' => $mail->attempts + 1])->save();
        try {
            $email = $this->build($mail, $mail->senderProfile, $account);
            if ($account !== null && $account->dkim_private_key && MailDns::canSignDkim()) {
                $email = (new DkimSigner($account->dkim_private_key, (string) ($account->dkim_domain ?: Str::after($mail->senderProfile->from_address ?? '', '@')), (string) $account->dkim_selector))->sign($email);
            }
            $account?->isDirect() ? $this->sendDirect($email, self::testAddress() ?? $mail->to_address) : $this->transport($account)->send($email);
            $mail->forceFill(['status' => 'sent', 'sent_at' => now(), 'last_error' => null])->save();
            if ($account?->canReceive() && $account->imap_sent_folder) {
                $this->copyToImap($account, $email, $mail);
            }

            return true;
        } catch (Throwable $e) {
            $final = $mail->attempts >= self::MAX_ATTEMPTS;
            $mail->forceFill([
                'status' => $final ? 'failed' : 'queued',
                'last_error' => Str::limit($e->getMessage(), 1000),
                'send_after' => $final ? null : now()->addMinutes(2 ** $mail->attempts),
            ])->save();
            if ($final) {
                Audit::record('mail.failed', $mail, null, ['to' => $mail->to_address, 'error' => Str::limit($e->getMessage(), 200)], 'system');
                app(Notifier::class)->event('bounces', __('mail.notify_failed', ['to' => $mail->to_address]));
            }

            return false;
        }
    }

    public function build(OutgoingMail $mail, ?SenderProfile $profile, ?MailAccount $account): Email
    {
        $fromAddress = $profile->from_address ?? (string) config('mail.from.address');
        $fromName = $profile->from_name ?? (string) config('mail.from.name');
        $domain = Str::after($fromAddress, '@') ?: 'localhost';
        $email = (new Email)
            ->from(new Address($fromAddress, $fromName))
            ->to(new Address(self::testAddress() ?? $mail->to_address, (string) $mail->to_name))
            ->subject(self::testAddress() !== null ? '[TEST → '.$mail->to_address.'] '.$mail->subject : $mail->subject)
            ->html($mail->html)
            ->text($mail->text);
        if ($profile?->reply_to) {
            $email->replyTo($profile->reply_to);
        }
        foreach ($mail->attachments ?? [] as $attachment) {
            $file = isset($attachment['document_id']) ? $this->document((int) $attachment['document_id']) : $attachment;
            if (isset($file['file'])) {
                // A mail never goes out without a file that was promised.
                $content = Storage::disk('local')->get((string) $file['file']) ?? throw new MailException('Attachment missing: '.($file['name'] ?? ''));
                $email->attach($content, (string) ($file['name'] ?? basename((string) $file['file'])));
            }
            if (isset($attachment['invoice_id'])) {
                $invoice = Invoice::query()->whereKey((int) $attachment['invoice_id'])->first();
                if ($invoice !== null) {
                    $pdf = app(InvoicePdf::class);
                    $paid = (bool) ($attachment['paid'] ?? false);
                    $email->attach($pdf->render($invoice, $paid), $pdf->fileName($invoice, $paid), 'application/pdf');
                }
            }
        }
        $messageId = $mail->message_id ?? $mail->uuid.'@'.$domain;
        $email->getHeaders()->addIdHeader('Message-ID', $messageId);
        $mail->forceFill(['message_id' => $messageId])->save();
        if (in_array($mail->kind, ['mailing', 'newsletter'], true)) {
            $prefs = $this->preferencesUrl($mail);
            if ($prefs !== null) {
                $email->getHeaders()->addTextHeader('List-Unsubscribe', '<'.$prefs.'>');
                $email->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            }
        }
        if ($mail->kind === 'notification') {
            $email->getHeaders()->addTextHeader('Auto-Submitted', 'auto-generated');
        }

        return $email;
    }

    /** Test mode (MS08): everything goes to this address. */
    public static function testAddress(): ?string
    {
        $address = trim((string) Settings::get('mail_test_address'));

        return $address === '' ? null : $address;
    }

    /** @return array{file: string, name: string} */
    private function document(int $id): array
    {
        $document = EventDocument::query()->find($id) ?? throw new MailException('Document missing');

        return ['file' => $document->path, 'name' => Str::slug($document->title).'.'.pathinfo($document->path, PATHINFO_EXTENSION)];
    }

    private function preferencesUrl(OutgoingMail $mail): ?string
    {
        return preg_match('#https?://[^"\s]+/mail/preferences/[^"\s]+#', $mail->html, $m) ? html_entity_decode($m[0]) : null;
    }

    /**
     * Direct delivery to the mail server of the recipient's domain (MX), STARTTLS required with a verified
     * certificate – no unencrypted fallback.
     */
    protected function sendDirect(Message $email, string $to): void
    {
        $domain = Str::after($to, '@');
        $mx = [];
        $weights = [];
        getmxrr($domain, $mx, $weights) ?: $mx = [$domain];
        array_multisort($weights, $mx);
        $last = null;
        foreach ($mx as $host) {
            try {
                $transport = new EsmtpTransport($host, 25, false);
                $transport->setAutoTls(true);
                $transport->setRequireTls(true);
                $transport->send($email, Envelope::create($email));

                return;
            } catch (Throwable $e) {
                $last = $e;
            }
        }
        throw $last ?? new MailException("No mail server found for {$domain}");
    }

    public function transport(?MailAccount $account): TransportInterface
    {
        if ($account === null) {
            return Mail::mailer()->getSymfonyTransport();
        }

        return $this->transports[$account->id] ??= Transport::fromDsn(sprintf(
            '%s://%s:%s@%s:%d?require_tls=true', // STARTTLS without fallback to an unencrypted connection
            $account->smtp_encryption === 'ssl' ? 'smtps' : 'smtp',
            rawurlencode((string) $account->smtp_username),
            rawurlencode((string) $account->smtp_password),
            $account->smtp_host,
            $account->smtp_port,
        ));
    }

    /** Sends a test mail directly (MS07). */
    public function test(MailAccount $account, string $to): void
    {
        (new Mailer($this->transport($account)))->send((new Email)
            ->from((string) ($account->smtp_username ?: config('mail.from.address')))->to($to)
            ->subject('Kasmin test mail')->text('This is a test mail from Kasmin ('.$account->name.').'));
    }

    private function withinRateLimits(MailAccount $account): bool
    {
        $profiles = SenderProfile::query()->where('mail_account_id', $account->id)->pluck('id');
        $count = fn (int $minutes) => OutgoingMail::query()->where('status', 'sent')->where('sent_at', '>=', now()->subMinutes($minutes))
            ->where(fn ($q) => $q->whereIn('sender_profile_id', $profiles)->when($account->is_default, fn ($q) => $q->orWhereNull('sender_profile_id')))->count();

        return ($account->rate_per_minute === null || $count(1) < $account->rate_per_minute)
            && ($account->rate_per_hour === null || $count(60) < $account->rate_per_hour)
            && ($account->rate_per_day === null || $count(1440) < $account->rate_per_day);
    }

    private function copyToImap(MailAccount $account, Message $email, OutgoingMail $mail): void
    {
        try {
            $mailbox = new Mailbox([
                'host' => $account->imap_host, 'port' => $account->imap_port ?? 993, 'encryption' => 'ssl',
                'username' => $account->imap_username, 'password' => $account->imap_password, 'validate_cert' => true,
            ]);
            $mailbox->folders()->firstOrCreate((string) $account->imap_sent_folder)->messages()->append($email->toString(), ['\\Seen']);
            $mail->forceFill(['imap_copied_at' => now()])->save();
        } catch (Throwable $e) {
            $mail->forceFill(['last_error' => 'IMAP copy: '.Str::limit($e->getMessage(), 500)])->save();
        }
    }
}
