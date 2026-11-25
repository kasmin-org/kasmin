<?php

namespace App\Support\Mail;

use App\Models\AutomationRun;
use App\Models\OutgoingMail;
use App\Models\Person;
use App\Models\User;
use App\Support\Auth\LoginLinks;
use App\Support\Automations\Approvals;

/**
 * Personal login and approval links of mails (L02–L04, AT04). Stored mails only contain a placeholder; the link is made when the mail is
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
        $html = $mail->html;
        $text = $mail->text;
        if (self::has($html.$text)) {
            $link = self::link($mail);
            [$html, $text] = [self::replace($html, $link), self::replace($text, $link)];
        }
        if (str_contains($html.$text, '/approvals/'.self::PLACEHOLDER)) {
            $link = self::approvalLink($mail);
            [$html, $text] = [self::replace($html, $link, 'approvals'), self::replace($text, $link, 'approvals')];
        }

        return ['html' => $html, 'text' => $text];
    }

    /** Replaces the placeholder, e.g. by the address of the portal where the mail is only shown. */
    public static function replace(string $content, string $link, string $path = 'login/link'): string
    {
        return (string) preg_replace('#https?://[^\s"\'<>]*?/'.$path.'/'.preg_quote(self::PLACEHOLDER, '#').'#', $link, $content);
    }

    /** Link to decide about a run; without the right receiver only the page in the admin interface, which needs a login. */
    private static function approvalLink(OutgoingMail $mail): string
    {
        $id = (int) (collect($mail->attachments ?? [])->pluck('approval_run_id')->filter()->first() ?? 0);
        $run = $id > 0 ? AutomationRun::query()->with('automation')->find($id) : null;
        $user = $mail->person_id !== null ? User::query()->where('person_id', $mail->person_id)->first() : null;
        $approvals = app(Approvals::class);
        if ($run === null || $user === null || ! $run->isPending() || Sender::testAddress() !== null || $user->email !== $mail->to_address || ! $approvals->approvers($run)->contains('id', $user->id)) {
            return url('/admin/automation-runs'.($run !== null ? '/'.$run->id : ''));
        }

        return $approvals->link($run, $user);
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
