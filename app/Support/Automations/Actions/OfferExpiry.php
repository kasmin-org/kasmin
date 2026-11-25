<?php

namespace App\Support\Automations\Actions;

use App\Models\Automation;
use App\Models\Event;
use App\Models\Participation;
use App\Support\Audit\Audit;
use App\Support\Automations\Action;
use App\Support\Automations\Engine;
use App\Support\Events\Registrations;
use App\Support\Mail\Notifier;

/**
 * A05: offers without an answer expire after their deadline; the place goes back to the waitlist automations.
 */
class OfferExpiry implements Action
{
    /** Transfers take some days until they are in the bank import. */
    public const PAYMENT_GRACE_DAYS = 3;

    public function plan(Automation $automation, ?Event $event): array
    {
        return array_values(Participation::query()->where('status', 'offered')->whereNotNull('offer_expires_at')->where('offer_expires_at', '<', now())
            ->where(fn ($q) => $q->whereNull('offer_mode')->orWhere('offer_expires_at', '<', now()->subDays(self::PAYMENT_GRACE_DAYS)))
            ->when($event, fn ($q) => $q->where('event_id', $event?->id))->with('person', 'event')->get()
            ->map(fn (Participation $p) => [
                'key' => 'participation:'.$p->id,
                'label' => (string) __('automations.items.offer_expired', ['name' => $p->person?->fullName(), 'event' => $p->event?->title, 'date' => $p->offer_expires_at?->isoFormat('L')]),
            ])->all());
    }

    public function execute(Automation $automation, array $item, ?Event $event): string
    {
        $p = Participation::query()->with('event', 'person')->find((int) substr($item['key'], 14));
        if ($p === null || $p->status !== 'offered' || $p->offer_expires_at === null || $p->offer_expires_at->isFuture()
            || ($p->offer_mode === 'payment' && $p->offer_expires_at->copy()->addDays(self::PAYMENT_GRACE_DAYS)->isFuture())) {
            return 'skipped';
        }
        $p->fill(['status' => 'declined', 'offer_expires_at' => null])->save();
        Audit::record('participation.offer_expired', $p);
        app(Registrations::class)->tell('offer_expired', $p);
        app(Notifier::class)->event('waitlist', __('automations.notify.offer_expired', ['name' => $p->person?->fullName(), 'event' => $p->event?->title]), url('/admin/events/'.$p->event_id.'/edit'));
        if ($p->event !== null) {
            app(Engine::class)->placeFreed($p->event);
        }

        return 'expired';
    }
}
