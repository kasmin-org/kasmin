<?php

namespace App\Support\Automations\Actions;

use App\Models\Automation;
use App\Models\Event;
use App\Models\Person;
use App\Support\Automations\Action;
use App\Support\Automations\Subjects;
use App\Support\Mail\Notifier;

/**
 * A13: children who reached a configured age (config: age, default 14; within the last window_days, default 30)
 * are reported to the team once, e.g. to propose a junior counsellor role or an own login.
 */
class AgeNotice implements Action
{
    public function __construct(private readonly Subjects $subjects) {}

    public function plan(Automation $automation, ?Event $event): array
    {
        $age = (int) $automation->setting('age', 14);
        $window = (int) $automation->setting('window_days', 30);
        $from = now()->subYears($age)->subDays($window)->startOfDay();
        $to = now()->subYears($age)->endOfDay();
        $items = [];
        foreach (Person::query()->whereHas('guardians')->whereNull('anonymised_at')->whereBetween('birth_date', [$from->toDateString(), $to->toDateString()])->get() as $person) {
            $key = 'person:'.$person->id.':age:'.$age;
            if ($this->subjects->get($automation, $key)['count'] > 0) {
                continue;
            }
            $items[] = ['key' => $key, 'label' => __('automations.items.age', ['name' => $person->fullName(), 'age' => $age, 'date' => $person->birth_date?->copy()->addYears($age)->isoFormat('L')])];
        }

        return $items;
    }

    public function execute(Automation $automation, array $item, ?Event $event): string
    {
        $id = (int) explode(':', $item['key'])[1];
        app(Notifier::class)->event('registrations', $item['label'], url('/admin/people/'.$id));
        $this->subjects->touch($automation, $item['key']);

        return 'team notified';
    }
}
