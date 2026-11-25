<?php

namespace App\Support\Automations;

use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\Event;
use App\Models\EventInstallment;
use App\Models\RegistrationRound;
use Illuminate\Support\Carbon;

/**
 * When what happens: the schedule of one event (EC02) and the next runs of all automations (AD05).
 */
class Schedule
{
    /**
     * Dates of the event, automations before and after it, and runs so far.
     *
     * @return list<array{at: Carbon, what: string, kind: string, state: string, automation: int|null, run: int|null}>
     */
    public function ofEvent(Event $event): array
    {
        $items = [];
        $add = function (?Carbon $at, string $what, string $kind = 'date', string $state = '', ?int $automation = null, ?int $run = null) use (&$items): void {
            if ($at !== null) {
                $items[] = ['at' => $at, 'what' => $what, 'kind' => $kind, 'state' => $state, 'automation' => $automation, 'run' => $run];
            }
        };
        $add($event->announce_at, (string) __('automations.schedule.announce'));
        $add($event->registration_opens_at, (string) __('automations.schedule.opens'));
        $add($event->registration_closes_at, (string) __('automations.schedule.closes'));
        $add($event->answers_until, (string) __('automations.schedule.answers'));
        $add($event->reserve_reminder_on, (string) __($event->reserve_auto_release ? 'automations.schedule.reserve_release' : 'automations.schedule.reserve'));
        $add($event->starts_at, (string) __('automations.schedule.start'));
        $add($event->ends_at, (string) __('automations.schedule.end'));
        foreach ($event->rounds()->get() as $round) {
            /** @var RegistrationRound $round */
            $add($round->opens_at, __('automations.schedule.round').': '.__('admin.event.round_kinds.'.$round->kind).' ('.__('events.roles.'.$round->role_key).')');
        }
        foreach ($event->installments()->get() as $installment) {
            /** @var EventInstallment $installment */
            $add($installment->due_date, __('automations.schedule.due').': '.$installment->labelText());
        }
        $runs = AutomationRun::query()->where('event_id', $event->id)->with('automation')->get();
        foreach (Automation::query()->whereIn('trigger', ['before_event', 'after_event'])->get() as $automation) {
            $at = $this->at($automation, $event);
            if ($at !== null && ! $runs->contains(fn (AutomationRun $r) => $r->automation_id === $automation->id && $r->dedupe_key === 'event:'.$event->id)) {
                $add($at, $automation->label(), 'automation', $automation->active ? ($automation->mode === 'manual' ? 'manual' : 'planned') : 'off', $automation->id);
            }
        }
        foreach ($runs as $run) {
            $at = $run->executed_at ?? $run->created_at;
            $add($at !== null ? Carbon::instance($at) : null, $run->automation?->label() ?? '–', 'run', $run->status, $run->automation_id, $run->id);
        }
        usort($items, fn (array $a, array $b) => $a['at'] <=> $b['at']);

        return $items;
    }

    /**
     * Next runs in the coming days.
     *
     * @return list<array{name: string, at: Carbon, event: string|null, automation: int}>
     */
    public function upcoming(int $days = 14): array
    {
        $until = now()->addDays($days);
        $items = [];
        foreach (Automation::query()->where('active', true)->where('mode', '!=', 'manual')->get() as $automation) {
            if (in_array($automation->trigger, ['daily', 'weekly', 'monthly', 'once'], true)) {
                $at = $this->next($automation);
                if ($at !== null && $at->lte($until)) {
                    $items[] = ['name' => $automation->label(), 'at' => $at, 'event' => null, 'automation' => $automation->id];
                }
            } elseif (in_array($automation->trigger, ['before_event', 'after_event'], true)) {
                foreach (Event::query()->whereNotIn('status', ['draft', 'cancelled'])->where('ends_at', '>', now()->subDays(400))->get() as $event) {
                    $at = $this->at($automation, $event);
                    if ($at !== null && $at->between(now(), $until) && ! $automation->runs()->where('dedupe_key', 'event:'.$event->id)->exists()) {
                        $items[] = ['name' => $automation->label(), 'at' => $at, 'event' => $event->title, 'automation' => $automation->id];
                    }
                }
            }
        }
        usort($items, fn (array $a, array $b) => $a['at'] <=> $b['at']);

        return $items;
    }

    /** Next time of a regular automation. */
    public function next(Automation $automation): ?Carbon
    {
        [$h, $m] = array_map('intval', explode(':', (string) $automation->triggerSetting('time', '08:00')) + [1 => '0']);
        if ($automation->trigger === 'once') {
            $date = $automation->triggerSetting('date');
            $at = filled($date) ? Carbon::parse((string) $date)->setTime($h, $m) : null;

            return $at !== null && $at->isFuture() ? $at : null;
        }
        $at = now()->setTime($h, $m);
        for ($i = 0; $i < 62; $i++, $at->addDay()) {
            if ($at->isFuture() && $this->onDay($automation, $at)) {
                return $at;
            }
        }

        return null;
    }

    /** Does a regular automation run on this day? */
    public function onDay(Automation $automation, Carbon $day): bool
    {
        return match ($automation->trigger) {
            'daily' => true,
            'weekly' => $day->dayOfWeekIso === (int) $automation->triggerSetting('weekday', 1),
            // Day 31 means the last day in shorter months.
            'monthly' => $day->day === min((int) $automation->triggerSetting('day', 1), $day->daysInMonth),
            'once' => $day->toDateString() === (string) $automation->triggerSetting('date'),
            default => false,
        };
    }

    private function at(Automation $automation, Event $event): ?Carbon
    {
        $series = $automation->triggerSetting('series');
        if (filled($series) && (int) $series !== (int) $event->event_series_id) {
            return null;
        }
        $days = (int) $automation->triggerSetting('days', 0);

        return $automation->trigger === 'before_event' ? $event->starts_at->copy()->subDays($days) : $event->ends_at->copy()->addDays($days);
    }
}
