<?php

namespace App\Support\Forms;

use App\Models\Event;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormResponse;
use App\Models\Household;
use App\Models\Participation;
use App\Models\Person;
use App\Support\Audit\Audit;
use App\Support\Mail\Notifier;
use App\Support\Webhooks\Webhooks;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Validation, versions, status and emergency contacts of person forms (safety sheet, profile forms).
 */
class Forms
{
    public function current(Form $form, Person $person): ?FormResponse
    {
        return FormResponse::query()->where('form_id', $form->id)->where('person_id', $person->id)->latest('id')->first();
    }

    /** @var Collection<int, Event>|null upcoming events with an own confirmation period (SF16) */
    private ?Collection $periods = null;

    /**
     * not_applicable (age limit reached), missing, expired (confirmation too old) or confirmed.
     */
    public function status(Form $form, Person $person): string
    {
        if ($form->applies_until_age !== null && ($age = $person->ageAt()) !== null && $age >= $form->applies_until_age) {
            return 'not_applicable';
        }
        $current = $this->current($form, $person);
        if ($current === null || $current->confirmed_at === null) {
            return 'missing';
        }
        if ($form->confirm_every_days !== null && $current->confirmed_at->lt(now()->subDays($form->confirm_every_days))) {
            return 'expired';
        }
        // Upcoming events may ask for a newer confirmation, from that number of days before the start (SF16).
        if ($form->family_emergency_contacts && $this->periods()->isNotEmpty()) {
            foreach (Participation::query()->where('person_id', $person->id)->whereIn('event_id', $this->periods()->keys())->whereIn('status', ['registered', 'confirmed', 'offered'])->pluck('event_id') as $id) {
                $event = $this->periods()->get($id);
                if ($event === null) {
                    continue;
                }
                $from = $event->starts_at->copy()->subDays((int) $event->safety_confirm_days)->startOfDay();
                if ($from->isPast() && $current->confirmed_at->lt($from)) {
                    return 'expired';
                }
            }
        }

        return 'confirmed';
    }

    /** @return Collection<int, Event> */
    private function periods(): Collection
    {
        return $this->periods ??= Event::query()->whereNotNull('safety_confirm_days')->where('ends_at', '>', now())->whereNotIn('status', ['draft', 'cancelled'])->get()->keyBy('id')->toBase();
    }

    /**
     * Values as sent by the form in the portal: the free text of a choice with "other" and of a person reference moves
     * into the value.
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $other
     * @return array<string, mixed>
     */
    public function normalise(Form $form, array $values, array $other): array
    {
        foreach ($form->fields as $field) {
            $text = trim((string) (is_scalar($other[$field->key] ?? null) ? $other[$field->key] : ''));
            $value = $values[$field->key] ?? null;
            if ($field->type === 'year_or_no' && is_string($value)) {
                $values[$field->key] = match (mb_strtolower(trim($value))) {
                    'no', mb_strtolower((string) __('forms.no')) => 'no',
                    'unknown', mb_strtolower((string) __('forms.unknown')) => 'unknown',
                    default => trim($value),
                };
            } elseif ($field->type === 'select' && $value === FormField::OTHER) {
                $values[$field->key] = FormField::OTHER.$text;
            } elseif ($field->type === 'multiselect' && is_array($value) && in_array(FormField::OTHER, $value, true)) {
                $values[$field->key] = [...array_values(array_diff($value, [FormField::OTHER])), FormField::OTHER.$text];
            } elseif ($field->type === 'person' && is_array($value) && ($value['person'] ?? '') !== '') {
                // A free name belongs to "another person" only.
                unset($values[$field->key]['name']);
                if ($value['person'] === FormField::OTHER) {
                    $values[$field->key] = ['name' => $text] + (isset($value['number']) ? ['number' => $value['number']] : []);
                }
            }
        }

        return $values;
    }

    /**
     * The legal guardians that can be picked in a person field, with the number already given for another child of the
     * person filling in the form (SF12).
     *
     * @return list<array{id: string, name: string, number: string|null}>
     */
    public function personOptions(Form $form, FormField $field, Person $person, Person $by): array
    {
        $numbers = [];
        if ($field->options['number'] ?? false) {
            foreach ($by->wards()->whereKeyNot($person->id)->get() as $sibling) {
                $value = $this->status($form, $sibling) === 'not_applicable' ? null : ($this->current($form, $sibling)->values[$field->key] ?? null);
                if (is_array($value) && ! empty($value['person']) && ! empty($value['number'])) {
                    $numbers[(string) $value['person']] = (string) $value['number'];
                }
            }
        }

        return array_values($person->guardians()->get()->map(fn (Person $g) => ['id' => $g->public_id, 'name' => $g->fullName(), 'number' => $numbers[$g->public_id] ?? null])->all());
    }

    /**
     * Values to pre-fill: the current version, without fields that are not carried over once a new period starts.
     *
     * @return array<string, mixed>
     */
    public function prefill(Form $form, Person $person): array
    {
        $current = $this->current($form, $person);
        if ($current === null) {
            return [];
        }
        $values = $current->values;
        if ($this->status($form, $person) === 'expired') {
            foreach ($form->fields as $field) {
                if (! $field->carry_over) {
                    unset($values[$field->key]);
                }
            }
        }

        return $values;
    }

    /** @return array<string, mixed> Laravel validation rules for the submitted "values" array; person references: only legal guardians of $person */
    public function rules(Form $form, ?Person $person = null): array
    {
        $rules = [];
        foreach ($form->fields as $field) {
            $base = 'values.'.$field->key;
            $presence = $field->required ? 'required' : 'nullable';
            $rules[$base] = match ($field->type) {
                'text', 'phone' => [$presence, 'string', 'max:500', ...$this->format($field)],
                'textarea' => [$presence, 'string', 'max:5000'],
                'email' => [$presence, 'email', 'max:254'],
                'number' => [$presence, 'numeric', ...$this->range($field, 'min', 'max')],
                'date' => [$presence, 'date', ...$this->range($field, 'after_or_equal', 'before_or_equal')],
                'select' => [$presence, $this->choice($field)],
                'multiselect' => [$presence, 'array'],
                'yes_no', 'consent' => [$field->required ? 'required' : 'nullable', 'in:yes,no'],
                'year_or_no' => [$presence, 'regex:/^((19|20)\d{2}|no|unknown)$/'],
                'none_or_text' => [$presence, 'string', 'max:2000'],
                'repeater' => [$presence, 'array', 'max:20'],
                'person' => [$presence, 'array:person,name,number', $this->personRule($field)],
                default => [$presence],
            };
            if ($field->type === 'multiselect') {
                $rules[$base.'.*'] = [$this->choice($field)];
            }
            if ($field->type === 'person') {
                $rules[$base.'.person'] = ['nullable', 'string', Rule::in($person?->guardians()->pluck('people.public_id')->all() ?? [])];
                $rules[$base.'.name'] = ['nullable', 'string', 'max:200'];
                $rules[$base.'.number'] = ['nullable', 'string', 'max:60', ...$this->format($field)];
            }
            if ($field->type === 'repeater') {
                foreach ($field->subFields() as $sub) {
                    $rules[$base.'.*.'.$sub] = ['nullable', 'string', 'max:500'];
                }
            }
        }

        return $rules;
    }

    private function choice(FormField $field): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($field): void {
            $value = is_scalar($value) ? (string) $value : '';
            if (isset($field->choices()[$value])) {
                return;
            }
            $text = $field->allowsOther() && str_starts_with($value, FormField::OTHER) ? trim(substr($value, strlen(FormField::OTHER))) : '';
            if ($text === '' || mb_strlen($text) > 200) {
                $fail(__($field->allowsOther() ? 'forms.other_required' : 'validation.in', ['attribute' => $field->labelText()]));
            }
        };
    }

    private function personRule(FormField $field): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($field): void {
            if ($field->required && is_array($value) && blank($value['person'] ?? null) && blank($value['name'] ?? null)) {
                $fail(__('validation.required', ['attribute' => $field->labelText()]));
            }
        };
    }

    /** @return list<Closure> */
    private function format(FormField $field): array
    {
        $format = (string) ($field->options['format'] ?? '');

        return $format === '' ? [] : [function (string $attribute, mixed $value, Closure $fail) use ($format): void {
            if (! Formats::valid($format, (string) $value)) {
                $fail(__('forms.format_invalid', ['format' => Formats::options()[$format] ?? $format]));
            }
        }];
    }

    /** @return list<string> */
    private function range(FormField $field, string $min, string $max): array
    {
        return array_values(array_filter([
            filled($field->options['min'] ?? null) ? $min.':'.$field->options['min'] : null,
            filled($field->options['max'] ?? null) ? $max.':'.$field->options['max'] : null,
        ]));
    }

    /**
     * Saves a new version. $confirm marks it as confirmed by the given person (yearly confirmation).
     *
     * @param  array<string, mixed>  $values
     */
    public function save(Form $form, Person $person, array $values, ?Person $by, bool $confirm = true, ?string $action = null): FormResponse
    {
        $clean = [];
        foreach ($form->fields as $field) {
            $value = $values[$field->key] ?? null;
            if ($field->type === 'repeater' && is_array($value)) {
                $value = array_values(array_filter($value, fn ($row) => is_array($row) && array_filter($row, fn ($v) => $v !== null && $v !== '') !== []));
            }
            if ($field->type === 'person' && is_array($value)) {
                $value = array_filter(array_intersect_key($value, array_flip(['person', 'name', 'number'])), fn ($v) => $v !== null && $v !== '');
                if (isset($value['person'])) {
                    $value['name'] = Person::query()->where('public_id', $value['person'])->first()?->fullName() ?? '';
                }
                ksort($value);
            }
            if ($value !== null && $value !== '' && $value !== []) {
                $clean[$field->key] = $value;
            }
        }

        $previous = $this->current($form, $person);
        $changed = [];
        foreach (array_unique(array_merge(array_keys($clean), array_keys($previous->values ?? []))) as $key) {
            if (($previous->values[$key] ?? null) != ($clean[$key] ?? null)) {
                $changed[] = (string) $key;
            }
        }

        $response = FormResponse::query()->create([
            'form_id' => $form->id,
            'person_id' => $person->id,
            'form_version' => $form->version,
            'values' => $clean,
            'changed_keys' => $changed,
            'confirmed_at' => $confirm ? now() : null,
            'confirmed_by_id' => $confirm ? $by?->id : null,
        ]);

        // Only field keys are logged; confidential values never reach the audit log (FB08, SF21).
        Audit::record($action ?? ($changed === [] ? 'form.confirmed_unchanged' : 'form.saved'), $person, null, [
            'form' => $form->key, 'response_id' => $response->id, 'changed_fields' => $changed,
        ]);

        // Never values or field names: the receiver asks the API, with its own scopes (X06, SF28).
        if ($confirm) {
            app(Webhooks::class)->emit('form.confirmed', [
                'form' => $form->key, 'person' => $person->public_id, 'changed' => $changed !== [],
                'confirmed_at' => $response->confirmed_at?->toIso8601String(),
            ]);
        }

        // Admin notification with field labels only, never values (NT03).
        if ($changed !== []) {
            $labels = $form->fields->whereIn('key', $changed)->map(fn (FormField $f) => $f->labelText())->implode(', ');
            app(Notifier::class)->event($form->key === 'safety_sheet' ? 'safety_updates' : 'profile_updates',
                __('forms.notify_changed', ['form' => $form->label(), 'name' => $person->fullName(), 'fields' => $labels]), url('/admin/people/'.$person->id));
        }

        return $response;
    }

    /**
     * Legal guardians of a family in the order of the emergency contacts (SF10).
     *
     * @return list<Person>
     */
    public function orderedGuardians(Household $household): array
    {
        $children = $household->members()->wherePivot('role', 'child')->pluck('people.id');
        $sort = DB::table('guardianships')->whereIn('child_id', $children)->whereNotNull('emergency_sort')->pluck('emergency_sort', 'guardian_id');

        return array_values($household->guardians()->get()->sortBy(fn (Person $g) => $sort[$g->id] ?? PHP_INT_MAX)->all());
    }

    /**
     * Sets the order for all children of the family.
     *
     * @param  array<string, int>  $order  public id of the legal guardian => position
     */
    public function orderGuardians(Household $household, array $order): void
    {
        if ($order === []) {
            return;
        }
        $children = $household->members()->wherePivot('role', 'child')->pluck('people.id');
        $position = 0;
        $guardians = collect($this->orderedGuardians($household))->sortBy(fn (Person $g) => $order[$g->public_id] ?? PHP_INT_MAX)->values();
        foreach ($guardians as $guardian) {
            DB::table('guardianships')->whereIn('child_id', $children)->where('guardian_id', $guardian->id)->update(['emergency_sort' => ++$position, 'updated_at' => now()]);
        }
        Audit::record('family.emergency_order', $household, null, ['order' => $guardians->pluck('id')->all()]);
    }

    public function logView(Form $form, Person $person): void
    {
        if ($form->fields->contains(fn (FormField $f) => $f->isConfidential())) {
            Audit::record('form.viewed', $person, null, ['form' => $form->key]);
        }
    }

    /**
     * Emergency contacts of a person: the legal guardians first (automatically, with their phone numbers),
     * then the family's further emergency contacts (SF10, SF11, FD02).
     *
     * @return list<array{name: string, relation: string, phone: string}>
     */
    public function emergencyContacts(Person $person): array
    {
        $contacts = [];
        foreach ($person->guardians()->with('contactPoints')->get()->sortBy(fn (Person $g) => $g->getRelationValue('pivot')?->getAttribute('emergency_sort') ?? PHP_INT_MAX) as $guardian) {
            $phones = $guardian->contactPoints->where('type', 'phone')->sortByDesc('is_primary')->pluck('value')->implode(', ');
            $contacts[] = ['name' => $guardian->fullName(), 'relation' => (string) __('forms.legal_guardian'), 'phone' => $phones];
        }
        foreach ($person->households as $household) {
            foreach ($household->emergencyContacts as $contact) {
                $contacts[] = ['name' => (string) $contact->getAttribute('name'), 'relation' => (string) $contact->getAttribute('relation'), 'phone' => (string) $contact->getAttribute('phone')];
            }
        }

        return $contacts;
    }
}
