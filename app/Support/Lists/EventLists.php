<?php

namespace App\Support\Lists;

use App\Models\Event;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormResponse;
use App\Models\Participation;
use App\Models\Person;
use App\Models\User;
use App\Support\Forms\Forms;
use App\Support\Forms\FormValue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Lists of an event (EC03, SF25, SF15, SF19a). Lists from the safety sheet are for the safety role and administrators only
 * (SF20); which fields appear where is set per form field ("list use").
 */
class EventLists
{
    /** Key => confidential. */
    public const LISTS = [
        'participants' => false, 'contacts' => false, 'bus' => false, 'birthdays' => false, 'answers' => false, 'missing' => false,
        'emergency' => true, 'kitchen' => true, 'medical' => true, 'medication' => true, 'insurance' => true, 'swimmers' => true, 'consents' => true, 'changes' => true,
    ];

    /** Participants that take part. */
    public const STATUSES = ['registered', 'confirmed', 'attended'];

    public function __construct(private readonly Forms $forms) {}

    public static function mayView(User $user, string $key): bool
    {
        if (! array_key_exists($key, self::LISTS)) {
            return false;
        }

        return self::LISTS[$key] ? self::mayViewSafety($user) : $user->hasAnyRole(['administrator', 'board', 'camp_leader', 'safety_officer']);
    }

    public static function mayViewSafety(User $user): bool
    {
        return $user->hasAnyRole(['safety_officer', 'administrator']);
    }

    /** @return list<string> */
    public static function keysFor(User $user): array
    {
        return array_values(array_filter(array_keys(self::LISTS), fn (string $key) => self::mayView($user, $key)));
    }

    public function build(Event $event, string $key, ?string $role = null): ListTable
    {
        $participants = $this->participants($event, $role);

        return match ($key) {
            'participants' => $this->participantList($event, $participants),
            'contacts' => $this->contacts($participants),
            'bus' => $this->bus($participants),
            'birthdays' => $this->birthdays($event, $participants),
            'answers' => $this->answers($participants),
            'missing' => $this->missing($participants),
            'emergency' => $this->emergency($event, $participants),
            'kitchen' => $this->fields($event, $participants, $key, fn (FormField $f) => in_array('kitchen', $f->list_use ?? [], true), onlyFilled: true),
            'medical' => $this->fields($event, $participants, $key, fn (FormField $f) => in_array('medical', $f->list_use ?? [], true) && $f->type !== 'repeater', onlyFilled: false),
            'medication' => $this->medication($event, $participants),
            'insurance' => $this->fields($event, $participants, $key, fn (FormField $f) => in_array('insurance', $f->list_use ?? [], true), onlyFilled: false),
            'swimmers' => $this->fields($event, $participants, $key, fn (FormField $f) => $f->key === 'swimmer' || in_array('swimmers', $f->list_use ?? [], true), onlyFilled: false),
            'consents' => $this->fields($event, $participants, $key, fn (FormField $f) => $f->type === 'consent', onlyFilled: false),
            'changes' => $this->changes($event, $participants),
            default => throw new InvalidArgumentException('Unknown list '.$key),
        };
    }

    /** @return Collection<int, Participation> */
    public function participants(Event $event, ?string $role = null): Collection
    {
        return Participation::query()->where('event_id', $event->id)->whereIn('status', self::STATUSES)
            ->when($role !== null && $role !== '', fn ($q) => $q->where('role_key', $role))
            ->with(['person.guardians.contactPoints', 'person.contactPoints', 'person.households.emergencyContacts'])->get()
            ->filter(fn (Participation $p) => $p->person !== null)
            ->sortBy(fn (Participation $p) => mb_strtolower($p->person?->last_name.' '.$p->person?->first_name))->values();
    }

    /** @return list<Form> safety sheets (forms with emergency contacts) */
    public function safetyForms(): array
    {
        return array_values(Form::query()->where('active', true)->where('family_emergency_contacts', true)->orderBy('sort')->with('fields')->get()->all());
    }

    /**
     * Fields changed since the person's last event before this one; null without an earlier version to compare with.
     *
     * @return list<string>|null
     */
    public function changedSince(Form $form, Person $person, Event $event): ?array
    {
        $last = Participation::query()->where('person_id', $person->id)->where('event_id', '!=', $event->id)->whereIn('participations.status', self::STATUSES)
            ->join('events', 'events.id', '=', 'participations.event_id')->where('events.ends_at', '<', $event->starts_at)->max('events.ends_at');
        if ($last === null) {
            return null;
        }
        $before = FormResponse::query()->where('form_id', $form->id)->where('person_id', $person->id)->where('created_at', '<=', Carbon::parse((string) $last))->latest('id')->first();
        $now = $this->forms->current($form, $person);
        if ($before === null || $now === null) {
            return null;
        }
        $changed = [];
        foreach ($form->fields as $field) {
            if (($before->values[$field->key] ?? null) != ($now->values[$field->key] ?? null)) {
                $changed[] = $field->key;
            }
        }

        return $changed;
    }

    /** @param  Collection<int, Participation>  $participants */
    private function participantList(Event $event, Collection $participants): ListTable
    {
        $rows = [];
        foreach ($participants as $i => $p) {
            $rows[] = [(string) ($i + 1), $this->person($p)->last_name, $this->person($p)->first_name, $this->date($this->person($p)->birth_date), (string) ($this->person($p)->ageAt($event->ageReferenceDate()) ?? '–'),
                (string) __('events.roles.'.$p->role_key), (string) __('events.status.'.$p->status), $this->person($p)->guardians->map->fullName()->implode(', ')];
        }

        return $this->table('participants', [__('lists.no'), __('admin.person.last_name'), __('admin.person.first_name'), __('admin.person.birth_date'), __('admin.person.age'), __('admin.event.role'), __('admin.event.status'), __('admin.household.guardians')], $rows);
    }

    /** @param  Collection<int, Participation>  $participants */
    private function contacts(Collection $participants): ListTable
    {
        $rows = [];
        foreach ($participants as $p) {
            $person = $this->person($p);
            $guardians = $person->guardians;
            foreach ($guardians->isEmpty() ? [$person] : $guardians as $contact) {
                $rows[] = [$person->fullName(), $contact->is($person) ? '–' : $contact->fullName(), $this->points($contact, 'phone'), $this->points($contact, 'email')];
            }
        }

        return $this->table('contacts', [__('lists.name'), __('forms.legal_guardian'), __('lists.phone'), __('lists.email')], $rows);
    }

    /** @param  Collection<int, Participation>  $participants */
    private function bus(Collection $participants): ListTable
    {
        $rows = [];
        foreach ($participants as $i => $p) {
            $person = $this->person($p);
            $guardian = $person->guardians->first();
            $rows[] = [(string) ($i + 1), $person->fullName(), $guardian?->fullName() ?? '–', $guardian !== null ? $this->points($guardian, 'phone') : $this->points($person, 'phone'), '☐', '☐'];
        }

        return $this->table('bus', [__('lists.no'), __('lists.name'), __('forms.legal_guardian'), __('lists.phone'), __('lists.outward'), __('lists.return')], $rows);
    }

    /** @param  Collection<int, Participation>  $participants */
    private function birthdays(Event $event, Collection $participants): ListTable
    {
        $rows = [];
        for ($day = $event->starts_at->copy()->startOfDay(); $day->lte($event->ends_at); $day->addDay()) {
            foreach ($participants as $p) {
                $birth = $this->person($p)->birth_date;
                // Born on 29 February: celebrated on 1 March in other years.
                $celebrated = $birth?->format('m-d') === '02-29' && ! $day->isLeapYear() ? '03-01' : $birth?->format('m-d');
                if ($celebrated === $day->format('m-d')) {
                    $rows[] = [$this->date($day), $this->person($p)->fullName(), (string) ($day->year - $birth->year)];
                }
            }
        }

        return $this->table('birthdays', [__('lists.date'), __('lists.name'), __('lists.turns')], $rows);
    }

    /**
     * All non-confidential answers of the other forms, e.g. T-shirt sizes.
     *
     * @param  Collection<int, Participation>  $participants
     */
    private function answers(Collection $participants): ListTable
    {
        $columns = [];
        foreach (Form::query()->where('active', true)->where('family_emergency_contacts', false)->orderBy('sort')->with('fields')->get() as $form) {
            foreach ($form->fields->reject(fn (FormField $f) => $f->isConfidential()) as $field) {
                $columns[] = [$form, $field];
            }
        }
        $rows = [];
        foreach ($participants as $p) {
            $row = [$this->person($p)->fullName()];
            foreach ($columns as [$form, $field]) {
                $row[] = FormValue::text($field, $this->forms->current($form, $this->person($p))->values[$field->key] ?? null) ?? '–';
            }
            $rows[] = $row;
        }

        return $this->table('answers', [__('lists.name'), ...array_map(fn (array $c) => $c[1]->labelText(), $columns)], $rows);
    }

    /**
     * "Still missing" (SF19a): no values, only the status.
     *
     * @param  Collection<int, Participation>  $participants
     */
    private function missing(Collection $participants): ListTable
    {
        $rows = [];
        foreach ($participants as $p) {
            foreach ($this->safetyForms() as $form) {
                $status = $this->forms->status($form, $this->person($p));
                if (in_array($status, ['missing', 'expired'], true)) {
                    $guardian = $this->person($p)->guardians->first();
                    $rows[] = [$this->person($p)->fullName(), $form->label(), (string) __('forms.status.'.$status), $guardian?->fullName() ?? '–', $guardian !== null ? $this->points($guardian, 'phone') : '–'];
                }
            }
        }

        return $this->table('missing', [__('lists.name'), __('lists.form'), __('admin.event.status'), __('forms.legal_guardian'), __('lists.phone')], $rows);
    }

    /** @param  Collection<int, Participation>  $participants */
    private function emergency(Event $event, Collection $participants): ListTable
    {
        $rows = [];
        foreach ($participants as $p) {
            $contacts = array_map(fn (array $c) => trim($c['name'].' ('.$c['relation'].') '.$c['phone']), $this->forms->emergencyContacts($this->person($p)));
            $rows[] = [$this->person($p)->fullName(), $this->date($this->person($p)->birth_date), implode("\n", $contacts)];
        }

        return $this->table('emergency', [__('lists.name'), __('admin.person.birth_date'), __('forms.emergency_contacts')], $rows);
    }

    /**
     * @param  Collection<int, Participation>  $participants
     * @param  \Closure(FormField): bool  $filter
     */
    private function fields(Event $event, Collection $participants, string $key, \Closure $filter, bool $onlyFilled): ListTable
    {
        $columns = [];
        foreach ($this->safetyForms() as $form) {
            foreach ($form->fields->filter($filter) as $field) {
                $columns[] = [$form, $field];
            }
        }
        $rows = [];
        $marks = [];
        foreach ($participants as $p) {
            $person = $this->person($p);
            $row = [$person->fullName()];
            $filled = false;
            $changedColumns = [];
            $applies = false;
            foreach ($columns as $i => [$form, $field]) {
                if ($this->forms->status($form, $person) === 'not_applicable') {
                    $row[] = '–'; // from the age limit on, the sheet is no longer visible (SF20a)

                    continue;
                }
                $applies = true;
                $response = $this->forms->current($form, $person);
                $value = $response->values[$field->key] ?? null;
                $row[] = $response === null ? (string) __('forms.status.missing') : (FormValue::text($field, $value) ?? '–');
                $filled = $filled || $response === null || ! in_array($value, [null, '', [], FormField::NONE, 'none'], true);
                if (in_array($field->key, $this->changedSince($form, $person, $event) ?? [], true)) {
                    $changedColumns[] = $i + 1;
                    $filled = true;
                }
            }
            if (! $applies || ($onlyFilled && ! $filled)) {
                continue;
            }
            foreach ($changedColumns as $column) {
                $marks[count($rows).':'.$column] = true;
            }
            $rows[] = $row;
        }

        return $this->table($key, [__('lists.name'), ...array_map(fn (array $c) => $c[1]->labelText(), $columns)], $rows, $marks);
    }

    /**
     * One row per medicine with dose and time.
     *
     * @param  Collection<int, Participation>  $participants
     */
    private function medication(Event $event, Collection $participants): ListTable
    {
        $subs = [];
        $rows = [];
        $marks = [];
        foreach ($this->safetyForms() as $form) {
            foreach ($form->fields->filter(fn (FormField $f) => $f->type === 'repeater' && in_array('medical', $f->list_use ?? [], true)) as $field) {
                $subs = $field->subFields();
                foreach ($participants as $p) {
                    $person = $this->person($p);
                    if ($this->forms->status($form, $person) === 'not_applicable') {
                        continue;
                    }
                    $response = $this->forms->current($form, $person);
                    if ($response === null) {
                        $rows[] = [$person->fullName(), (string) __('forms.status.missing'), ...array_map(fn () => '', array_slice($subs, 1))];

                        continue;
                    }
                    $changed = in_array($field->key, $this->changedSince($form, $person, $event) ?? [], true);
                    $items = $response->values[$field->key] ?? [];
                    foreach (is_array($items) ? $items : [] as $item) {
                        if ($changed) {
                            $marks[count($rows).':1'] = true;
                        }
                        $rows[] = [$person->fullName(), ...array_map(fn (string $s) => (string) ((is_array($item) ? $item[$s] ?? '' : '') ?: '–'), $subs)];
                    }
                }
                $headers = [__('lists.name'), ...array_map(fn (string $s) => $field->subFieldLabel($s), $subs)];
            }
        }

        return $this->table('medication', $headers ?? [__('lists.name')], $rows, $marks);
    }

    /**
     * What changed since the last event, with the new value (SF15).
     *
     * @param  Collection<int, Participation>  $participants
     */
    private function changes(Event $event, Collection $participants): ListTable
    {
        $rows = [];
        foreach ($participants as $p) {
            $person = $this->person($p);
            foreach ($this->safetyForms() as $form) {
                if ($this->forms->status($form, $person) === 'not_applicable') {
                    continue;
                }
                $current = $this->forms->current($form, $person);
                foreach ($this->changedSince($form, $person, $event) ?? [] as $key) {
                    $field = $form->fields->firstWhere('key', $key);
                    if ($field !== null) {
                        $rows[] = [$person->fullName(), $field->labelText(), FormValue::text($field, $current->values[$key] ?? null) ?? '–', $this->date($current?->created_at)];
                    }
                }
            }
        }

        return $this->table('changes', [__('lists.name'), __('lists.field'), __('lists.new_value'), __('lists.date')], $rows);
    }

    private function person(Participation $participation): Person
    {
        return $participation->person ?? throw new InvalidArgumentException('Participation without person');
    }

    private function points(Person $person, string $type): string
    {
        return $person->contactPoints->where('type', $type)->sortByDesc('is_primary')->pluck('value')->implode(', ') ?: '–';
    }

    private function date(?Carbon $date): string
    {
        return $date !== null ? $date->isoFormat('L') : '–';
    }

    /**
     * @param  array<int, mixed>  $headers
     * @param  list<list<string>>  $rows
     * @param  array<string, true>  $marks
     */
    private function table(string $key, array $headers, array $rows, array $marks = []): ListTable
    {
        return new ListTable((string) __('lists.titles.'.$key), array_values(array_map(fn ($h) => (string) $h, $headers)), $rows, self::LISTS[$key], $marks);
    }
}
