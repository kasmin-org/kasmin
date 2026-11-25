<?php

namespace App\Support\Mail;

use App\Models\OutgoingMail;
use App\Models\Person;
use App\Support\Auth\LoginLinks;

/**
 * Personal login links of mails (L02–L04). Stored mails only contain a placeholder; the link is made when the mail is
 * sent, so nobody can use the link of somebody else from the mail log, the review of a mailing or a backup.
 */
class MailLinks
{
    public const PLACEHOLDER = 'made-when-sending';

    public static function placeholder(): string
    {
        return route('login.link', self::PLACEHOLDER);
    }

    public static function has(string $content): bool
    {
        return str_contains($content, '/login/link/'.self::PLACEHOLDER);
    }

    /**
     * Contents of the mail with the personal link.
     *
     * @return array{html: string, text: string}
     */
    public static function resolve(OutgoingMail $mail): array
    {
        if (! self::has($mail->html.$mail->text)) {
            return ['html' => $mail->html, 'text' => $mail->text];
        }
        $link = self::link($mail);

        return ['html' => self::replace($mail->html, $link), 'text' => self::replace($mail->text, $link)];
    }

    /** Replaces the placeholder, e.g. by the address of the portal where the mail is only shown. */
    public static function replace(string $content, string $link): string
    {
        return (string) preg_replace('#https?://[^\s"\'<>]*?/login/link/'.preg_quote(self::PLACEHOLDER, '#').'#', $link, $content);
    }

    private static function link(OutgoingMail $mail): string
    {
        $person = $mail->person_id !== null ? Person::query()->find($mail->person_id) : null;
        $user = $person?->user;
        // In the test mode all mails go to somebody else, and an address may have been removed since the mail was made.
        if ($person === null || $user === null || Sender::testAddress() !== null
            || ! $person->contactPoints()->where('type', 'email')->where('value', mb_strtolower($mail->to_address))->exists()) {
            return route('login');
        }

        return route('login.link', app(LoginLinks::class)->issue($user, 'mail_link', 'limited')['token']);
    }
}
