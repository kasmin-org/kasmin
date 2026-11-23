<?php

namespace App\Support\Automations\Actions;

use App\Models\Automation;
use App\Models\Event;
use App\Models\Household;
use App\Models\Participation;
use App\Models\Person;
use App\Support\Automations\Action;
use App\Support\Automations\Families;
use App\Support\Mail\Composer;
use Illuminate\Support\Collection;

/**
 * A09 and similar: a mail template to the participants of an event, one mail per family. Config: template,
 * statuses (default registered/confirmed), roles (optional), to (guardians, person, both).
 */
class SendTemplate implements Action
{
    public function plan(Automation $automation, ?Event $event): array
    {
        if ($event === null) {
            return [];
        }
        $items = [];
        foreach ($this->families($automation, $event) as $key => $family) {
            $items[] = [
                'key' => $key,
                'label' => Families::label($family),
                'details' => implode(', ', array_map(fn ($r) => $r->fullName(), $family['recipients'])),
            ];
        }

        return $items;
    }

    public function execute(Automation $automation, array $item, ?Event $event): string
    {
        $family = $event !== null ? $this->families($automation, $event)->get($item['key']) : null;
        if ($family === null) {
            return 'skipped';
        }
        $sent = 0;
        foreach ($family['recipients'] as $recipient) {
            $mail = app(Composer::class)->queue((string) $automation->setting('template', 'event_information'), $recipient, [
                'event' => $event, 'household' => $family['household'], 'children' => $family['children'],
                'participation' => count($family['participations']) === 1 ? $family['participations'][0] : null,
            ]);
            $sent += $mail !== null ? 1 : 0;
        }

        return $sent.' mail(s)';
    }

    /** @return Collection<string, array{household: Household|null, children: list<Person>, recipients: list<Person>, participations: list<Participation>}> */
    private function families(Automation $automation, Event $event)
    {
        $statuses = (array) $automation->setting('statuses', ['registered', 'confirmed']);
        $roles = (array) $automation->setting('roles', []);
        $participations = Participation::query()->where('event_id', $event->id)->whereIn('status', $statuses)
            ->when($roles !== [], fn ($q) => $q->whereIn('role_key', $roles))->get();

        return Families::group($participations, (string) $automation->setting('to', 'guardians'));
    }
}
