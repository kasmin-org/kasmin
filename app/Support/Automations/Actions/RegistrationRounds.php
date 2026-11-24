<?php

namespace App\Support\Automations\Actions;

use App\Models\Automation;
use App\Models\Event;
use App\Models\Person;
use App\Models\RegistrationRound;
use App\Support\Automations\Action;
use App\Support\Events\Rounds;
use App\Support\Finance\Invoices;
use App\Support\Mail\Composer;
use App\Support\Settings;
use Illuminate\Support\Collection;

/**
 * RR01–RR03: offers of the active rounds, one per family. Accepting in the portal or – mode "payment" – paying the first
 * instalment by the deadline takes the place; otherwise it goes on to the next ones.
 */
class RegistrationRounds implements Action
{
    public function __construct(private readonly Rounds $rounds, private readonly Invoices $invoices) {}

    public function plan(Automation $automation, ?Event $event): array
    {
        $items = [];
        foreach ($this->active($event) as $round) {
            foreach ($this->families($round) as $key => $people) {
                $items[] = [
                    'key' => $round->id.':'.$key,
                    'label' => (string) __('automations.items.round', ['names' => $people->map(fn (Person $p) => $p->fullName())->implode(', '), 'event' => $round->event?->title]),
                    'details' => __('admin.event.round_kinds.'.$round->kind).' · '.__('automations.items.offer_until', ['date' => $round->closes_at?->isoFormat('L')]),
                ];
            }
        }

        return $items;
    }

    public function execute(Automation $automation, array $item, ?Event $event): string
    {
        [$id, $key] = explode(':', $item['key'], 2);
        $round = RegistrationRound::query()->with('event')->find((int) $id);
        $e = $round?->event;
        $people = $round !== null && $round->active() ? $this->families($round)->get($key) : null;
        if ($round === null || $e === null || $people === null || $round->closes_at === null) {
            return 'skipped';
        }
        $offered = [];
        foreach ($people as $person) {
            $p = $this->rounds->offer($e, $person, $round->role_key, $round->closes_at, $round->mode === 'payment' ? 'payment' : null);
            if ($p !== null) {
                $offered[] = $p;
            }
        }
        if ($offered === []) {
            return 'skipped: no free place';
        }
        $attachments = null;
        $installment = $round->mode === 'payment' ? $e->installments()->first() : null;
        if ($installment !== null) {
            $group = $this->invoices->pendingGroups($e->refresh(), $installment)->get($key);
            if ($group !== null) {
                $invoice = $this->invoices->issue($this->invoices->draftFor($e, $installment, $group, Invoices::household($group)));
                $attachments = [['invoice_id' => $invoice->id, 'paid' => false]];
            }
        }
        $children = array_map(fn ($p) => $people->firstWhere('id', $p->person_id) ?? $people->first(), $offered);
        $recipients = collect($children)->flatMap(fn (?Person $c) => $c === null ? [] : ($c->guardians->all() ?: [$c]))->unique('id');
        foreach ($recipients as $recipient) {
            app(Composer::class)->queue('round_offer', $recipient, [
                'event' => $e, 'participation' => $offered[0]->load('event', 'person'), 'household' => $people->first()?->households->first(), 'children' => array_values(array_filter($children)),
            ], attachments: $attachments);
        }

        return 'offered: '.count($offered);
    }

    /** @return iterable<RegistrationRound> */
    private function active(?Event $event): iterable
    {
        return RegistrationRound::query()->whereIn('kind', ['returning', 'selection'])->whereNotNull('closes_at')->when($event, fn ($q) => $q->where('event_id', $event?->id))
            ->whereHas('event', fn ($q) => $q->whereIn('status', ['announced', 'open'])->where('starts_at', '>', now()))->with('event')->orderBy('sort')->get()
            ->filter(fn (RegistrationRound $r) => $r->active());
    }

    /** @return Collection<string, Collection<int, Person>> */
    private function families(RegistrationRound $round): Collection
    {
        $groups = [];
        $adult = Settings::int('guardian_until_age');
        foreach ($this->rounds->candidates($round) as $person) {
            $household = ($person->ageAt($round->event?->starts_at) ?? 0) >= $adult ? null : $person->households->first();
            $groups[(string) ($household->id ?? 'p'.$person->id)][] = $person;
        }

        return collect($groups)->mapWithKeys(fn (array $g, int|string $k) => [(string) $k => collect($g)]);
    }
}
