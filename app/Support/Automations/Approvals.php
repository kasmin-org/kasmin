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

    public function request(AutomationRun $run, bool $reminder = false): int
    {
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

    /** The other approvers who were asked learn who decided (RO03). */
    public function informOthers(AutomationRun $run, User $by): void
    {
        $asked = OutgoingMail::query()->where('attachments', 'like', '%"approval_run_id":'.$run->id.'}%')->pluck('person_id')->all();
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
        foreach (AutomationRun::query()->where('status', 'pending_approval')->whereNotNull('expires_at')->with('automation')->get() as $run) {
            if ($run->expires_at?->isPast()) {
                $run->forceFill(['status' => 'expired'])->save();
                $run->tokens()->whereNull('used_at')->update(['used_at' => now()]);
                app(Notifier::class)->event('approvals', __('automations.notify.expired', ['name' => $run->automation?->label()]), url('/admin/automation-runs/'.$run->id));

                continue;
            }
            $created = $run->created_at;
            $half = $created !== null && $run->expires_at !== null ? $created->copy()->addSeconds((int) ($created->diffInSeconds($run->expires_at) / 2)) : null;
            if ($run->reminded_at === null && $half !== null && $half->isPast()) {
                $run->forceFill(['reminded_at' => now()])->save();
                $this->request($run, true);
            }
        }
    }
}
