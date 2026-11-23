<?php

namespace App\Support\System;

use App\Models\Role;
use App\Models\SystemNotification;
use App\Models\User;
use App\Support\Mail\Composer;
use App\Support\Mail\Sender;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * System notifications (SN01): only for users with the administrator role; shown after login until dismissed and
 * mailed once. Mails are sent directly (not via the queue), because a missing cron job would otherwise stop them.
 */
class SystemNotifications
{
    /**
     * @param  array{de: string, en: string}  $title
     * @param  array{de: string, en: string}  $message
     */
    public function raise(string $key, string $level, array $title, array $message, ?string $url = null): SystemNotification
    {
        $notification = SystemNotification::query()->firstOrNew(['key' => $key]);
        $isNew = ! $notification->exists || $notification->resolved_at !== null;
        $notification->fill(['level' => $level, 'title' => $title, 'message' => $message, 'url' => $url, 'resolved_at' => null])->save();
        if ($isNew) {
            DB::table('system_notification_user')->where('system_notification_id', $notification->id)->delete();
        }
        $this->mailOnce($notification);

        return $notification;
    }

    public function resolve(string $keyPrefix): void
    {
        SystemNotification::query()->where('key', 'like', $keyPrefix.'%')->whereNull('resolved_at')->update(['resolved_at' => now()]);
    }

    /** @return Collection<int, User> */
    public function administrators(): Collection
    {
        $roleId = Role::query()->where('key', 'administrator')->value('id');
        $today = now()->toDateString();

        return User::query()->whereHas('person.roleAssignments', fn ($q) => $q->where('role_id', $roleId)
            ->where(fn ($q) => $q->whereNull('starts_on')->orWhere('starts_on', '<=', $today))
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $today)))->get();
    }

    /** @return Collection<int, SystemNotification> notifications the user has not dismissed yet */
    public function openFor(User $user): Collection
    {
        if (! $this->administrators()->contains('id', $user->id)) {
            return collect();
        }
        $dismissed = DB::table('system_notification_user')->where('user_id', $user->id)->whereNotNull('dismissed_at')->pluck('system_notification_id');

        return SystemNotification::query()->whereNull('resolved_at')->whereNotIn('id', $dismissed)->orderByDesc('id')->get();
    }

    public function dismiss(SystemNotification $notification, User $user): void
    {
        DB::table('system_notification_user')->updateOrInsert(
            ['system_notification_id' => $notification->id, 'user_id' => $user->id],
            ['dismissed_at' => now()],
        );
    }

    private function mailOnce(SystemNotification $notification): void
    {
        foreach ($this->administrators() as $admin) {
            $row = DB::table('system_notification_user')->where('system_notification_id', $notification->id)->where('user_id', $admin->id)->first();
            if ($row !== null && $row->mailed_at !== null) {
                continue;
            }
            $locale = $admin->person->locale ?? 'en';
            $html = '<p>'.e($notification->text('message', $locale)).'</p>'.($notification->url ? '<p><a href="'.e($notification->url).'">'.e($notification->url).'</a></p>' : '');
            $mail = app(Composer::class)->queueRaw($admin->email, $admin->name, 'Kasmin: '.$notification->text('title', $locale), $html, $locale);
            try {
                app(Sender::class)->send($mail, null);
            } catch (Throwable) {
                // Stays queued; the queue retries it.
            }
            DB::table('system_notification_user')->updateOrInsert(
                ['system_notification_id' => $notification->id, 'user_id' => $admin->id],
                ['mailed_at' => now()],
            );
        }
    }
}
