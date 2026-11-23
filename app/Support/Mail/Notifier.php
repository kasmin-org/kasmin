<?php

namespace App\Support\Mail;

use App\Models\NotificationEvent;
use App\Models\NotificationSubscription;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Admin notifications (NT01–NT05): events are stored; subscribers with "immediate" get a mail at once, the others a
 * daily or weekly digest. Summaries never contain confidential values (NT03).
 */
class Notifier
{
    public function event(string $topic, string $summary, ?string $url = null): void
    {
        $event = NotificationEvent::query()->create(['topic' => $topic, 'summary' => Str::limit($summary, 1000), 'url' => $url, 'created_at' => now()]);
        foreach (NotificationSubscription::query()->where('topic', $topic)->where('frequency', 'immediate')->with('user')->get() as $sub) {
            if ($sub->user instanceof User) {
                $this->mail($sub->user, [$event]);
            }
        }
    }

    /**
     * Sends due digests: daily at the configured time (last 24 hours), weekly on Mondays (last 7 days).
     */
    public function digests(): int
    {
        $sent = 0;
        $subs = NotificationSubscription::query()->whereIn('frequency', ['daily', 'weekly'])->with('user')->get()->groupBy('user_id');
        foreach ($subs as $userSubs) {
            $user = $userSubs->first()?->user;
            if (! $user instanceof User) {
                continue;
            }
            $events = [];
            foreach ($userSubs as $sub) {
                if ($sub->frequency === 'weekly' && ! now()->isMonday()) {
                    continue;
                }
                $since = $sub->last_sent_at ?? now()->subDays($sub->frequency === 'weekly' ? 7 : 1);
                array_push($events, ...NotificationEvent::query()->where('topic', $sub->topic)->where('created_at', '>', $since)->orderBy('created_at')->get()->all());
                $sub->forceFill(['last_sent_at' => now()])->save();
            }
            if ($events !== []) {
                $this->mail($user, $events);
                $sent++;
            }
        }

        return $sent;
    }

    /** @param list<NotificationEvent> $events */
    private function mail(User $user, array $events): void
    {
        $locale = $user->person->locale ?? app()->getLocale();
        $items = collect($events)->groupBy('topic')->map(fn ($group, $topic) => '<h3>'.e(__('mail.topics.'.$topic, [], $locale)).'</h3><ul>'
            .$group->map(fn (NotificationEvent $e) => '<li>'.e($e->created_at->format('d.m.Y H:i')).' – '.($e->url ? '<a href="'.e($e->url).'">'.e($e->summary).'</a>' : e($e->summary)).'</li>')->implode('').'</ul>')->implode('');
        app(Composer::class)->queueRaw($user->email, $user->name, __('mail.digest_subject', ['count' => count($events)], $locale), $items, $locale);
    }
}
