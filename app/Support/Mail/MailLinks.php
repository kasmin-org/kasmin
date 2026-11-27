<?php

namespace App\Support\Mail;

use App\Models\AutomationRun;
use App\Models\Event;
use App\Models\OutgoingMail;
use App\Models\Person;
use App\Models\User;
use App\Support\Auth\LoginLinks;
use App\Support\Automations\Approvals;
use Illuminate\Support\Carbon;

/**
 * Personal login and approval links of mails (L02–L04, AT04). Stored mails only contain a placeholder; the link is made when the mail is
 * sent, so nobody can use the link of somebody else from the mail log, the review of a mailing or a backup.
 */
class MailLinks
{
    public const PLACEHOLDER = 'made-when-sending';

    /** Where the mail says until when its link is valid; replaced when the mail is sent. */
    public const UNTIL_PLACEHOLDER = '[[link-valid-until]]';

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
            [$link, $until] = self::link($mail);
            [$html, $text] = [self::replace($html, $link), self::replace($text, $link)];
            $date = $until instanceof Carbon ? $until->copy()->locale(self::locale($mail))->isoFormat('LL') : null;
            [$html, $text] = [self::until($html, $date), self::until($text, $date)];
        }
        // A summary holds several requests: each one has its own placeholder with the number of the run.
        if (preg_match_all('#/approvals/'.preg_quote(self::PLACEHOLDER, '#').'-(\d+)#', $html.$text, $m) > 0) {
            foreach (array_unique($m[1]) as $runId) {
                $link = self::approvalLink($mail, (int) $runId);
                $pattern = '#https?://[^\s"\'<>]*?/approvals/'.preg_quote(self::PLACEHOLDER, '#').'-'.$runId.'(?!\d)#';
                [$html, $text] = [(string) preg_replace($pattern, $link, $html), (string) preg_replace($pattern, $link, $text)];
            }
        }
        if (str_contains($html.$text, '/approvals/'.self::PLACEHOLDER)) {
            $link = self::approvalLink($mail);
            [$html, $text] = [self::replace($html, $link, 'approvals'), self::replace($text, $link, 'approvals')];
        }

        return ['html' => $html, 'text' => $text];
    }

    /** Puts in the date the link is valid until; without a personal link the sentence is left out. */
    public static function until(string $content, ?string $date): string
    {
        if ($date !== null) {
            return str_replace(self::UNTIL_PLACEHOLDER, $date, $content);
        }

        return (string) preg_replace(['#<p[^>]*>[^<]*'.preg_quote(e(self::UNTIL_PLACEHOLDER), '#').'[^<]*</p>#', '#\n\n[^\n]*'.preg_quote(self::UNTIL_PLACEHOLDER, '#').'[^\n]*#'], '', $content);
    }

    /** For previews and the log: the link becomes the given address, the date a hint that it is set when sending. */
    public static function preview(string $content, string $link = '#'): string
    {
        return str_replace([self::UNTIL_PLACEHOLDER, e(self::UNTIL_PLACEHOLDER)], __('mail.link_valid_until_preview'), self::replace($content, $link));
    }

    private static function locale(OutgoingMail $mail): string
    {
        $locale = $mail->person_id !== null ? (string) Person::query()->whereKey($mail->person_id)->value('locale') : '';

        return $locale !== '' ? $locale : app()->getLocale();
    }

    /** Replaces the placeholder, e.g. by the address of the portal where the mail is only shown. */
    public static function replace(string $content, string $link, string $path = 'login/link'): string
    {
        return (string) preg_replace('#https?://[^\s"\'<>]*?/'.$path.'/'.preg_quote(self::PLACEHOLDER, '#').'#', $link, $content);
    }

    /** Link to decide about a run; without the right receiver only the page in the admin interface, which needs a login. */
    private static function approvalLink(OutgoingMail $mail, ?int $runId = null): string
    {
        $ids = collect($mail->attachments ?? [])->pluck('approval_run_id')->filter()->map(fn ($id) => (int) $id);
        $id = $runId !== null ? ($ids->contains($runId) ? $runId : 0) : (int) ($ids->first() ?? 0);
        $run = $id > 0 ? AutomationRun::query()->with('automation')->find($id) : null;
        $user = $mail->person_id !== null ? User::query()->where('person_id', $mail->person_id)->first() : null;
        $approvals = app(Approvals::class);
        if ($run === null || $user === null || ! $run->isPending() || Sender::testAddress() !== null || $user->email !== $mail->to_address || ! $approvals->approvers($run)->contains('id', $user->id)) {
            return url('/admin/automation-runs'.($run !== null ? '/'.$run->id : ''));
        }

        return $approvals->link($run, $user);
    }

    /** @return array{0: string, 1: Carbon|null} the link and until when it is valid */
    private static function link(OutgoingMail $mail): array
    {
        $person = $mail->person_id !== null ? Person::query()->find($mail->person_id) : null;
        $user = $person?->user;
        // In the test mode all mails go to somebody else, and an address may have been removed since the mail was made.
        if ($person === null || $user === null || Sender::testAddress() !== null
            || ! $person->contactPoints()->where('type', 'email')->where('value', mb_strtolower($mail->to_address))->exists()) {
            return [route('login'), null];
        }
        $links = app(LoginLinks::class);
        $event = $mail->event_id !== null ? Event::query()->find($mail->event_id) : null;
        ['token' => $token, 'model' => $model] = $links->issue($user, 'mail_link', 'limited', $links->mailLinkMinutes($person, $event), $event?->id);

        return [route('login.link', $token), $model->expires_at];
    }
}
