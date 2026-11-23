<?php

namespace App\Support\Forms;

use App\Models\Form;
use App\Models\FormField;
use App\Models\FormResponse;
use App\Models\Person;
use App\Support\Audit\Audit;
use App\Support\Mail\Notifier;
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

        return 'confirmed';
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

    /** @return array<string, mixed> Laravel validation rules for the submitted "values" array */
    public function rules(Form $form): array
    {
        $rules = [];
        foreach ($form->fields as $field) {
            $base = 'values.'.$field->key;
            $presence = $field->required ? 'required' : 'nullable';
            $rules[$base] = match ($field->type) {
                'text', 'phone' => [$presence, 'string', 'max:500'],
                'textarea' => [$presence, 'string', 'max:5000'],
                'email' => [$presence, 'email', 'max:254'],
                'date' => [$presence, 'date'],
                'select' => [$presence, Rule::in(array_keys($field->choices()))],
                'multiselect' => [$presence, 'array'],
                'yes_no', 'consent' => [$field->required ? 'required' : 'nullable', 'in:yes,no'],
                'year_or_no' => [$presence, 'regex:/^((19|20)\d{2}|no|unknown)$/'],
                'none_or_text' => [$presence, 'string', 'max:2000'],
                'repeater' => [$presence, 'array', 'max:20'],
                default => [$presence],
            };
            if ($field->type === 'multiselect') {
                $rules[$base.'.*'] = [Rule::in(array_keys($field->choices()))];
            }
            if ($field->type === 'repeater') {
                foreach ($field->subFields() as $sub) {
                    $rules[$base.'.*.'.$sub] = ['nullable', 'string', 'max:500'];
                }
            }
        }

        return $rules;
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

        // Admin notification with field labels only, never values (NT03).
        if ($changed !== []) {
            $labels = $form->fields->whereIn('key', $changed)->map(fn (FormField $f) => $f->labelText())->implode(', ');
            app(Notifier::class)->event($form->key === 'safety_sheet' ? 'safety_updates' : 'profile_updates',
                __('forms.notify_changed', ['form' => $form->label(), 'name' => $person->fullName(), 'fields' => $labels]), url('/admin/people/'.$person->id));
        }

        return $response;
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
        foreach ($person->guardians()->with('contactPoints')->get() as $guardian) {
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
