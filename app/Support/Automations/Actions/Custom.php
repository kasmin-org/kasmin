<?php

namespace App\Support\Automations\Actions;

use App\Filament\Support\Money;
use App\Models\Automation;
use App\Models\Event;
use App\Models\Group;
use App\Models\MailTemplate;
use App\Models\Participation;
use App\Models\Person;
use App\Support\Api\Presenter;
use App\Support\Automations\Action;
use App\Support\Automations\Subjects;
use App\Support\Events\Registrations;
use App\Support\Finance\Invoices;
use App\Support\Mail\Composer;
use App\Support\Mail\Notifier;
use App\Support\Mail\Routing;
use App\Support\People\GroupMembers;
use App\Support\Webhooks\Webhooks;
use Illuminate\Support\Collection;

/**
 * Automations of the organisation (AT01, X09): a target (the person of the trigger, participants of an event, members of
 * a group, or people by rules), conditions, and steps one after the other. Config: target {source, statuses, roles,
 * group_id}, conditions {match, rules}, steps [{type, …}], once.
 */
class Custom implements Action
{
    public const SOURCES = ['subject', 'event', 'group', 'people'];

    public const STEPS = ['send_mail', 'add_group', 'remove_group', 'set_status', 'notify_team', 'webhook', 'invoice'];

    /** Statuses an automation may set; everything else has its own rules (places, waitlist, offers). */
    public const STATUSES = ['confirmed', 'attended', 'no_show', 'cancelled'];

    public function __construct(private readonly GroupMembers $members, private readonly Subjects $subjects) {}

    public function plan(Automation $automation, ?Event $event): array
    {
        $steps = $this->steps($automation);
        if ($steps === []) {
            return [];
        }
        $details = implode(', ', array_map(fn (array $step) => $this->stepText($step), $steps));

        return array_values($this->people($automation, $event)->map(fn (Person $person) => ['key' => 'person:'.$person->id, 'label' => $person->fullName(), 'details' => $details])->all());
    }

    public function execute(Automation $automation, array $item, ?Event $event): string
    {
        $person = $this->people($automation, $event)->first(fn (Person $p) => 'person:'.$p->id === $item['key']);
        if ($person === null) {
            return 'skipped: no longer fits';
        }
        $results = [];
        foreach ($this->steps($automation) as $step) {
            $results[] = $this->step($automation, $step, $person, $event);
        }
        $this->subjects->touch($automation, $item['key']);

        return implode('; ', $results);
    }

    /** Does this automation work on one event? Then the trigger or the button has to give it. */
    public static function needsEvent(Automation $automation): bool
    {
        return (($automation->config['target'] ?? [])['source'] ?? '') === 'event';
    }

    /** @param  array<string, mixed>  $step */
    private function step(Automation $automation, array $step, Person $person, ?Event $event): string
    {
        $participation = $this->participation($automation, $person, $event);

        return match ($step['type']) {
            'add_group' => $this->group($step, fn (Group $g) => GroupMembers::join($g, $person->id, 'automation')),
            'remove_group' => $this->group($step, fn (Group $g) => GroupMembers::leave($g, $person->id)),
            'send_mail' => $this->mail($step, $person, $participation, $event),
            'set_status' => $this->status($step, $participation),
            'notify_team' => $this->notify($step, $automation, $person),
            'webhook' => app(Webhooks::class)->emit('automation.custom', ['automation' => $automation->key, 'name' => (string) ($step['name'] ?? ''), 'person' => Presenter::person($person), 'event' => $event?->public_id]).' webhook(s)',
            'invoice' => 'invoice draft '.app(Invoices::class)->single($this->payer($person), [['description' => (string) $step['description'], 'amount_cents' => Money::toCents((string) $step['amount']), 'person_id' => $person->id]])->id,
            default => 'unknown step',
        };
    }

    /**
     * @param  array<string, mixed>  $step
     * @param  \Closure(Group): void  $change
     */
    private function group(array $step, \Closure $change): string
    {
        $group = Group::query()->where('kind', 'manual')->find((int) ($step['group_id'] ?? 0));
        if ($group === null) {
            return 'skipped: no group';
        }
        $change($group);

        return $group->name;
    }

    /** @param  array<string, mixed>  $step */
    private function mail(array $step, Person $person, ?Participation $participation, ?Event $event): string
    {
        $template = MailTemplate::query()->where('key', (string) ($step['template'] ?? ''))->where('active', true)->first();
        if ($template === null) {
            return 'skipped: no template';
        }
        $sent = 0;
        $recipients = match ($step['to'] ?? 'template') {
            'person' => [$person],
            'guardians' => $participation?->isOwn() === true ? [$person] : ($person->guardians()->get()->all() ?: [$person]),
            default => Routing::recipients($template->key, $person, $participation),
        };
        foreach ($recipients as $recipient) {
            $mail = app(Composer::class)->queue($template, $recipient, ['event' => $event ?? $participation?->event, 'participation' => $participation, 'household' => $person->households()->first(), 'children' => $recipient->is($person) ? [] : [$person]]);
            $sent += $mail !== null ? 1 : 0;
        }

        return $sent.' mail(s)';
    }

    /** @param  array<string, mixed>  $step */
    private function status(array $step, ?Participation $participation): string
    {
        $status = (string) ($step['status'] ?? '');
        if ($participation === null || ! in_array($status, self::STATUSES, true) || in_array($participation->status, [$status, 'cancelled', 'declined', 'waitlist', 'offered'], true)) {
            return 'skipped: status';
        }
        $status === 'cancelled' ? app(Registrations::class)->cancel($participation, (string) __('automations.by_automation')) : $participation->fill(['status' => $status])->save();

        return $status;
    }

    /** @param  array<string, mixed>  $step */
    private function notify(array $step, Automation $automation, Person $person): string
    {
        app(Notifier::class)->event('tasks', trim($automation->label().': '.$person->fullName().' – '.($step['text'] ?? ''), ' –'), url('/admin/people/'.$person->id));

        return 'team notified';
    }

    /** @return Collection<int, Person> */
    private function people(Automation $automation, ?Event $event): Collection
    {
        $target = (array) $automation->setting('target', []);
        $people = match ($target['source'] ?? null) {
            'subject' => Person::query()->whereKey((int) ($automation->context['person_id'] ?? 0)),
            'event' => $event === null ? null : Person::query()->whereHas('participations', fn ($q) => $q->where('event_id', $event->id)->whereIn('status', (array) (($target['statuses'] ?? []) ?: ['registered', 'confirmed']))
                ->when(! empty($target['roles']), fn ($q) => $q->whereIn('role_key', (array) $target['roles']))),
            'group' => Person::query()->whereIn('id', ($group = Group::query()->find((int) ($target['group_id'] ?? 0))) !== null ? $this->members->withSubgroups($group) : []),
            // By rules only with rules: an empty filter must not mean everybody.
            'people' => $this->rules($automation) === [] ? null : Person::query(),
            default => null,
        };
        if ($people === null) {
            return collect();
        }
        $result = $people->whereNull('anonymised_at')->orderBy('last_name')->orderBy('first_name')->get();
        if ($this->rules($automation) !== []) {
            $filter = new Group(['kind' => 'dynamic', 'event_id' => $event?->id, 'filter' => (array) $automation->setting('conditions', [])]);
            $matching = $this->members->matching($filter);
            $result = $result->filter(fn (Person $p) => $matching->contains($p->id));
        }
        if ($automation->setting('once')) {
            $result = $result->filter(fn (Person $p) => $this->subjects->get($automation, 'person:'.$p->id)['count'] === 0);
        }

        return $result->values()->toBase();
    }

    private function participation(Automation $automation, Person $person, ?Event $event): ?Participation
    {
        $id = (int) ($automation->context['participation_id'] ?? 0);
        $query = Participation::query()->where('person_id', $person->id)->with('event', 'person');
        if ($id > 0) {
            return $query->find($id);
        }

        return $event === null ? null : $query->where('event_id', $event->id)->whereNotIn('status', ['cancelled', 'declined'])->first();
    }

    private function payer(Person $person): Person
    {
        return $person->guardians()->first() ?? $person;
    }

    /** @return list<array<string, mixed>> */
    private function rules(Automation $automation): array
    {
        return array_values(array_filter((array) (((array) $automation->setting('conditions', []))['rules'] ?? []), fn ($r) => is_array($r) && filled($r['field'] ?? null)));
    }

    /** @return list<array<string, mixed>> */
    private function steps(Automation $automation): array
    {
        return array_values(array_filter((array) $automation->setting('steps', []), fn ($s) => is_array($s) && in_array($s['type'] ?? null, self::STEPS, true)));
    }

    /** @param  array<string, mixed>  $step */
    private function stepText(array $step): string
    {
        $what = match ($step['type']) {
            'add_group', 'remove_group' => Group::query()->whereKey((int) ($step['group_id'] ?? 0))->value('name'),
            'send_mail' => MailTemplate::query()->where('key', (string) ($step['template'] ?? ''))->first()?->label(),
            'set_status' => __('events.status.'.($step['status'] ?? '')),
            'invoice' => ($step['description'] ?? '').' '.($step['amount'] ?? '').' €',
            'notify_team' => $step['text'] ?? '',
            default => $step['name'] ?? '',
        };

        return __('automations.steps.'.$step['type']).': '.$what;
    }
}
