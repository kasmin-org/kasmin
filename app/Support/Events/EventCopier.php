<?php

namespace App\Support\Events;

use App\Models\Event;
use App\Models\EventDocument;
use App\Models\EventInstallment;
use App\Models\EventPrice;
use App\Models\EventRole;
use App\Models\RegistrationRound;
use App\Support\Audit\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Copies an event with everything that is configured (EE06, ET01): all dates move by the same number of days. People,
 * invoices and costs are not copied.
 */
class EventCopier
{
    private const DATES = ['starts_at', 'ends_at', 'age_reference_date', 'answers_until', 'reserve_reminder_on', 'announce_at', 'registration_opens_at', 'registration_closes_at'];

    public function copy(Event $source, string $title, Carbon $startsOn, bool $asTemplate = false): Event
    {
        $days = (int) round($source->starts_at->copy()->startOfDay()->diffInDays($startsOn->copy()->startOfDay(), false));

        return DB::transaction(function () use ($source, $title, $days, $asTemplate) {
            $event = new Event;
            // Raw values: casts must not encode JSON a second time.
            $event->setRawAttributes(array_diff_key($source->getAttributes(), array_flip(['id', 'public_id', 'created_at', 'updated_at', 'status_checked_at', 'reserve_reminded_at'])));
            $event->forceFill(['title' => $title, 'status' => 'draft', 'is_template' => $asTemplate, 'public_page' => ! $asTemplate && $source->public_page]);
            foreach (self::DATES as $field) {
                $date = $source->getAttribute($field);
                $event->setAttribute($field, $date instanceof Carbon ? $date->copy()->addDays($days) : null);
            }
            $event->save();

            $source->roles()->get()->each(fn (EventRole $r) => $this->row($r, $event, []));
            $source->prices()->get()->each(fn (EventPrice $p) => $this->row($p, $event, ['from_date' => $p->from_date?->copy()->addDays($days), 'until_date' => $p->until_date?->copy()->addDays($days)]));
            $source->installments()->get()->each(fn (EventInstallment $i) => $this->row($i, $event, ['due_date' => $i->due_date?->copy()->addDays($days)]));
            $source->rounds()->get()->each(fn (RegistrationRound $r) => $this->row($r, $event, ['opens_at' => $r->opens_at?->copy()->addDays($days), 'closes_at' => $r->closes_at?->copy()->addDays($days)]));
            $source->documents()->get()->each(fn (EventDocument $d) => $this->row($d, $event, []));
            Audit::record('event.copied', $event, null, ['from' => $source->id, 'days' => $days]);

            return $event;
        });
    }

    /** @param  array<string, mixed>  $changes */
    private function row(Model $source, Event $event, array $changes): void
    {
        $copy = $source->newInstance();
        $copy->setRawAttributes(array_diff_key($source->getAttributes(), array_flip(['id', 'created_at', 'updated_at'])));
        $copy->forceFill(['event_id' => $event->id] + $changes)->save();
    }
}
