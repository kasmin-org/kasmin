<?php

namespace App\Support\Automations;

use App\Models\AutomationApprovalToken;
use App\Models\AutomationRun;
use App\Models\OutgoingMail;
use App\Models\Role;
use App\Models\User;
use App\Support\Mail\Composer;
use App\Support\Mail\MailLinks;
use App\Support\Mail\Notifier;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Approval requests by e-mail (AT03–AT05): every approver gets a personal, one-time, expiring link to a page that
 * shows the plan and asks for the decision with a button (never on a plain GET). Reminder halfway, expiry notice.
 */
class Approvals
{
    /** @return Collection<int, User> */
    public function approvers(AutomationRun $run): Collection
    {
        $roles = $run->automation?->approverRoles() ?? ['board', 'administrator'];
        $roleIds = Role::query()->whereIn('key', $roles)->pluck('id');
        $today = now()->toDateString();

        return User::query()->whereHas('person.roleAssignments', fn ($q) => $q->whereIn('role_id', $roleIds)
            ->where(fn ($q) => $q->whereNull('starts_on')->orWhereDate('starts_on', '<=', $today))
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today)))->get();
    }

    /**
     * Asks the approvers by e-mail. With a time for the summary (setting approval_summary_time) nothing is sent now:
     * the request goes out with the next summary.
     */
    public function request(AutomationRun $run, bool $reminder = false): int
    {
        if (self::summaryTimes() !== []) {
            return 0;
        }
        $run->loadMissing('automation', 'event');
        $sent = 0;
        $done = $run->approvals()->pluck('user_id')->all();
        foreach ($this->approvers($run) as $user) {
            if (in_array($user->id, $done, true)) {
                continue;
            }
            $locale = $user->person->locale ?? app()->getLocale();
            $subject = __($reminder ? 'automations.mail.reminder_subject' : 'automations.mail.subject', ['name' => $run->automation?->label($locale)], $locale);
            $html = view('mail.approval-request', ['run' => $run, 'url' => route('approvals.show', MailLinks::PLACEHOLDER), 'locale' => $locale, 'user' => $user])->render();
            $mail = app(Composer::class)->queueRaw($user->email, $user->name, $subject, $html, $locale, 'notification', $user->person_id);
            $mail->forceFill(['attachments' => [['approval_run_id' => $run->id]]])->save();
            $sent++;
        }

        return $sent;
    }

    /**
     * Times of day at which approvers get one mail with everything that waits for them (AT06); empty = every request
     * is sent at once in a mail of its own.
     *
     * @return list<string>
     */
    public static function summaryTimes(): array
    {
        preg_match_all('/\b([01]\d|2[0-3]):[0-5]\d\b/', (string) Settings::get('approval_summary_time'), $m);
        $times = array_values(array_unique($m[0]));
        sort($times);

        return $times;
    }

    /**
     * Sends the summary if one of the times has passed since the last one: every approver gets one mail with all
     * requests that came in since the last summary, and those that are older, still open and half-way to their end
     * (once, instead of a reminder mail of their own). Returns the number of mails.
     */
    public function sendSummaries(): int
    {
        $times = self::summaryTimes();
        if ($times === []) {
            return 0;
        }
        $last = Settings::get('approval_summary_last');
        $last = $last !== null && $last !== '' ? Carbon::parse($last) : null;
        // The latest time of day that has passed (today or yesterday).
        $due = collect($times)->map(fn (string $t) => now()->copy()->setTimeFromTimeString($t))
            ->flatMap(fn (Carbon $t) => [$t, $t->copy()->subDay()])->filter(fn (Carbon $t) => $t->lte(now()))->max();
        if ($last === null) {
            // The first look after the summary was switched on: the next time of day counts, not one that has passed already.
            Settings::set('approval_summary_last', now()->toIso8601String());

            return 0;
        }
        if ($due === null || $last->gte($due)) {
            return 0;
        }
        // Everything that came in since the last summary, by the number of the request (the very first one takes all that is open).
        $since = (int) Settings::get('approval_summary_since');
        $pending = AutomationRun::query()->where('status', 'pending_approval')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->with('automation', 'event')->orderBy('id')->get();
        $fresh = $pending->filter(fn (AutomationRun $run) => $run->id > $since);
        $halfway = $pending->diff($fresh)->filter(function (AutomationRun $run) {
            $half = $run->created_at !== null && $run->expires_at !== null ? $run->created_at->copy()->addSeconds((int) ($run->created_at->diffInSeconds($run->expires_at) / 2)) : null;

            return $run->reminded_at === null && $half !== null && $half->isPast();
        });
        Settings::set('approval_summary_last', now()->toIso8601String());
        Settings::set('approval_summary_since', (string) max($since, (int) AutomationRun::query()->max('id')));
        if ($fresh->isEmpty() && $halfway->isEmpty()) {
            return 0;
        }
        $sent = 0;
        $users = $fresh->merge($halfway)->flatMap(fn (AutomationRun $run) => $this->approvers($run))->unique('id');
        foreach ($users as $user) {
            $mine = fn (Collection $runs) => $runs->filter(fn (AutomationRun $run) => $this->approvers($run)->contains('id', $user->id) && ! $run->approvals()->where('user_id', $user->id)->exists())->values();
            [$new, $old] = [$mine($fresh), $mine($halfway)];
            if ($new->isEmpty() && $old->isEmpty()) {
                continue;
            }
            $locale = $user->person->locale ?? app()->getLocale();
            $count = $new->count() + $old->count();
            $subject = (string) trans_choice('automations.mail.summary_subject', $count, ['count' => $count], $locale);
            $html = view('mail.approval-summary', ['new' => $new, 'old' => $old, 'locale' => $locale, 'user' => $user,
                'url' => fn (AutomationRun $run) => route('approvals.show', MailLinks::PLACEHOLDER.'-'.$run->id)])->render();
            $mail = app(Composer::class)->queueRaw($user->email, $user->name, $subject, $html, $locale, 'notification', $user->person_id);
            $mail->forceFill(['attachments' => $new->merge($old)->map(fn (AutomationRun $run) => ['approval_run_id' => $run->id])->values()->all()])->save();
            $sent++;
        }
        foreach ($halfway as $run) {
            $run->forceFill(['reminded_at' => now()])->save();
        }

        return $sent;
    }

    /** The other approvers who were asked learn who decided (RO03). */
    public function informOthers(AutomationRun $run, User $by): void
    {
        // Compared in PHP: databases store JSON with different spaces.
        $asked = OutgoingMail::query()->where('kind', 'notification')->where('attachments', 'like', '%approval_run_id%')->when($run->created_at, fn ($q, $since) => $q->where('created_at', '>=', $since))->get(['id', 'person_id', 'attachments'])
            ->filter(fn (OutgoingMail $mail) => collect($mail->attachments ?? [])->contains(fn (array $a) => (int) ($a['approval_run_id'] ?? 0) === $run->id))->pluck('person_id')->all();
        foreach ($this->approvers($run) as $user) {
            if ($user->id === $by->id || ! in_array($user->person_id, $asked, true)) {
                continue;
            }
            $locale = $user->person->locale ?? app()->getLocale();
            $text = __('automations.mail.decided_'.($run->status === 'denied' ? 'denied' : 'approved'), ['name' => $run->automation?->label($locale), 'by' => $by->name], $locale);
            app(Composer::class)->queueRaw($user->email, $user->name, (string) __('automations.mail.decided_subject', ['name' => $run->automation?->label($locale)], $locale), '<p>'.e($text).'</p>', $locale, 'notification', $user->person_id);
        }
    }

    /** Personal link of an approver; made when the mail is sent, so it is never stored (AT04). */
    public function link(AutomationRun $run, User $user): string
    {
        $token = Str::random(48);
        $run->tokens()->create(['user_id' => $user->id, 'token_hash' => hash('sha256', $token), 'expires_at' => $run->expires_at ?? now()->addDays(7)]);

        return route('approvals.show', $token);
    }

    public function findByToken(string $token): ?AutomationApprovalToken
    {
        $model = AutomationApprovalToken::query()->where('token_hash', hash('sha256', $token))->with('run.automation', 'run.event', 'user')->first();

        return $model !== null && $model->used_at === null && $model->expires_at->isFuture() && $model->run?->isPending() ? $model : null;
    }

    /** Expire undecided runs (nothing happens, approvers are told) and remind once halfway (AT05). */
    public function housekeeping(): void
    {
        $this->sendSummaries();
        foreach (AutomationRun::query()->where('status', 'pending_approval')->whereNotNull('expires_at')->with('automation')->get() as $run) {
            if ($run->expires_at?->isPast()) {
                $run->forceFill(['status' => 'expired'])->save();
                $run->tokens()->whereNull('used_at')->update(['used_at' => now()]);
                app(Notifier::class)->event('approvals', __('automations.notify.expired', ['name' => $run->automation?->label()]), url('/admin/automation-runs/'.$run->id));

                continue;
            }
            $created = $run->created_at;
            $half = $created !== null && $run->expires_at !== null ? $created->copy()->addSeconds((int) ($created->diffInSeconds($run->expires_at) / 2)) : null;
            if (self::summaryTimes() === [] && $run->reminded_at === null && $half !== null && $half->isPast()) {
                $run->forceFill(['reminded_at' => now()])->save();
                $this->request($run, true);
            }
        }
    }
}
