<?php

namespace App\Support\Mail;

use App\Models\ContactPoint;
use App\Models\OutgoingMail;
use App\Support\Audit\Audit;
use Illuminate\Support\Str;
use Throwable;

/**
 * Addresses that cannot receive mails (MS05, M05): marked after a permanent error or a bounce, until the address is
 * changed or somebody says that it works again.
 */
class BadAddresses
{
    public function mark(OutgoingMail $mail, string $reason): void
    {
        $points = ContactPoint::query()->where('type', 'email')->where('value', mb_strtolower($mail->to_address))->get();
        foreach ($points as $point) {
            // Without the model events: nobody changes a contact here, so the rights of the current user do not matter.
            ContactPoint::query()->whereKey($point->id)->update(['bounced_at' => now(), 'bounce_reason' => Str::limit($reason, 480)]);
            Audit::record('mail.bad_address', $point->person, null, ['address' => $mail->to_address], 'system');
        }
    }

    public function clear(ContactPoint $point): void
    {
        ContactPoint::query()->whereKey($point->id)->update(['bounced_at' => null, 'bounce_reason' => null]);
        Audit::record('mail.address_works', $point->person, null, ['address' => $point->value]);
    }

    /** The server says that the address does not exist; everything else may be temporary and is tried again. */
    public static function permanent(Throwable $e): bool
    {
        $message = $e->getMessage();

        return preg_match('/(?<![\d.])5\.1\.(0|1|2|3|6|10)(?![\d.])/', $message) === 1
            || (preg_match('/\b(550|551|553)\b/', $message) === 1 && preg_match('/(user|recipient|mailbox|address|account)[^.]{0,40}(unknown|not found|does not exist|doesn\'t exist|unavailable|invalid|disabled|rejected)|no such (user|recipient|mailbox)|(unknown|invalid) (user|recipient|mailbox|address)/i', $message) === 1);
    }

    /** A notice that a mail is only late (4.x.x) is no bounce. */
    public static function onlyDelayed(string $subject, string $text): bool
    {
        return preg_match('/^Action:\s*delayed/mi', $text) === 1
            || (preg_match('/^Status:\s*4\./mi', $text) === 1 && preg_match('/^Status:\s*5\./mi', $text) !== 1)
            || preg_match('/(delayed|warning: could not send|verz(ö|oe)gert)/iu', $subject) === 1;
    }
}
