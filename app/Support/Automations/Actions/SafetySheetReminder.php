<?php

namespace App\Support\Automations\Actions;

use App\Models\Automation;
use App\Models\Event;
use App\Models\Form;
use App\Models\Household;
use App\Models\Participation;
use App\Models\Person;
use App\Support\Automations\Action;
use App\Support\Automations\Families;
use App\Support\Automations\Finishes;
use App\Support\Automations\Subjects;
use App\Support\Forms\Forms;
use App\Support\Mail\Composer;
use App\Support\Mail\Notifier;
use Illuminate\Support\Collection;

/**
 * A08, SF17–SF19: reminders for missing or unconfirmed safety sheets of participants of upcoming events, one mail per
 * family, repeated every interval_days (default 7), at most max (default 4) times; afterwards a "still missing"
 * notification to the team. Config: form (default safety_sheet), window_days (default 60), interval_days, max.
 */
class SafetySheetReminder implements Action, Finishes
{
    public function __construct(private readonly Forms $forms, private readonly Subjects $subjects) {}

    public function plan(Automation $automation, ?Event $event): array
    {
        $items = [];
        foreach ($this->missing($automation, $event) as $key => [$e, $family]) {
            $subject = $this->subjects->get($automation, $key);
            if ($subject['count'] >= (int) $automation->setting('max', 4)
                || ($subject['last_at'] !== null && $subject['last_at']->gt(now()->subDays((int) $automation->setting('interval_days', 7))->addHour()))) {
                continue;
            }
            $items[] = [
                'key' => $key,
                'label' => Families::label($family),
                'details' => $e->title.' · '.__('automations.items.reminder_no', ['no' => $subject['count'] + 1]),
            ];
        }

        return $items;
    }

    public function execute(Automation $automation, array $item, ?Event $event): string
    {
        $entry = $this->missing($automation, $event)->get($item['key']);
        if ($entry === null) {
            return 'skipped: complete';
        }
        [$e, $family] = $entry;
        foreach ($family['recipients'] as $recipient) {
            app(Composer::class)->queue('safety_sheet_reminder', $recipient, ['event' => $e, 'household' => $family['household'], 'children' => $family['children']]);
        }
        $this->subjects->touch($automation, $item['key']);

        return count($family['recipients']).' mail(s)';
    }

    public function finish(Automation $automation, ?Event $event): void
    {
        $missing = $this->missing($automation, $event);
        if ($missing->isEmpty()) {
            return;
        }
        foreach ($missing->groupBy(fn ($entry) => $entry[0]->id) as $entries) {
            $e = $entries->first()[0] ?? null;
            if (! $e instanceof Event) {
                continue;
            }
            $names = $entries->flatMap(fn ($entry) => array_map(fn ($c) => $c->fullName(), $entry[1]['children']))->implode(', ');
            app(Notifier::class)->event('safety_updates', __('automations.notify.safety_missing', ['event' => $e->title, 'count' => $entries->count(), 'names' => $names]), url('/admin/events/'.$e->id.'/edit'));
        }
    }

    /** @return Collection<string, array{0: Event, 1: array{household: Household|null, children: list<Person>, recipients: list<Person>, participations: list<Participation>}}> */
    private function missing(Automation $automation, ?Event $event): Collection
    {
        $form = Form::query()->where('key', (string) $automation->setting('form', 'safety_sheet'))->where('active', true)->first();
        if ($form === null) {
            return collect();
        }
        $events = $event !== null ? collect([$event]) : Event::query()->where('starts_at', '>', now())
            ->where('starts_at', '<=', now()->addDays((int) $automation->setting('window_days', 60)))->whereNotIn('status', ['draft', 'cancelled'])->get();
        $result = collect();
        foreach ($events as $e) {
            $participations = Participation::query()->where('event_id', $e->id)->whereIn('status', ['registered', 'confirmed', 'offered'])->with('person')->get()
                ->filter(fn (Participation $p) => $p->person !== null && in_array($this->forms->status($form, $p->person), ['missing', 'expired'], true));
            foreach (Families::group($participations) as $key => $family) {
                $result->put('event:'.$e->id.':'.$key, [$e, $family]);
            }
        }

        return $result;
    }
}
