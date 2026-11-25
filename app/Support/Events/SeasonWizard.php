<?php

namespace App\Support\Events;

use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\Event;
use App\Models\User;
use App\Support\Automations\Engine;
use App\Support\Automations\Schedule;
use App\Support\Automations\Triggers;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * "Copy from last year" and "start season" with a preview of everything that will happen (AD32, A02, EE06).
 */
class SeasonWizard
{
    public function __construct(private readonly EventCopier $copier, private readonly Engine $engine, private readonly Schedule $schedule) {}

    /**
     * Exactly what would happen: the event is copied in a transaction that is always rolled back.
     *
     * @return array{title: string, starts_at: Carbon, ends_at: Carbon, roles: list<string>, counts: array<string, int>, dates: list<array{at: Carbon, what: string}>, people: list<array{key: string, label: string, details?: string|null}>}
     */
    public function preview(Event $source, string $title, Carbon $startsOn): array
    {
        return Triggers::muted(function () use ($source, $title, $startsOn) {
            DB::beginTransaction();
            try {
                $event = Event::query()->findOrFail($this->copier->copy($source, $title, $startsOn)->id);
                $season = $this->automation();

                return [
                    'title' => $event->title, 'starts_at' => $event->starts_at, 'ends_at' => $event->ends_at,
                    'roles' => array_values($event->roles()->get()->map(fn ($r) => __('events.roles.'.$r->role_key).': '.($r->capacity ?? '∞'))->all()),
                    'counts' => ['prices' => $event->prices()->count(), 'installments' => $event->installments()->count(), 'rounds' => $event->rounds()->count(), 'documents' => $event->documents()->count()],
                    'dates' => array_map(fn (array $i) => ['at' => $i['at'], 'what' => $i['what']], array_values(array_filter($this->schedule->ofEvent($event), fn (array $i) => $i['kind'] === 'date' || $i['state'] === 'planned'))),
                    'people' => $season === null ? [] : $this->engine->preview($season, $event->load('roles', 'installments')),
                ];
            } finally {
                DB::rollBack();
            }
        });
    }

    public function copy(Event $source, string $title, Carbon $startsOn): Event
    {
        return $this->copier->copy($source, $title, $startsOn);
    }

    /** Runs "start season" for the event: directly if the person may approve it, otherwise the approvers are asked. */
    public function start(Event $event, User $by): ?AutomationRun
    {
        $season = $this->automation();

        return $season === null ? null : $this->engine->trigger($season, 'manual', $event->load('roles', 'installments'), null, $by);
    }

    public function automation(): ?Automation
    {
        return Automation::query()->where('action', 'start_season')->orderBy('id')->first();
    }
}
