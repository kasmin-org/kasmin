<?php

namespace App\Support\Forms;

use App\Models\Event;
use App\Models\Form;
use App\Models\FormField;
use App\Models\Participation;
use App\Models\Person;
use App\Models\RegistrationAnswer;
use App\Support\Audit\Audit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Questions and consents of an event (FB01–FB05).
 */
class RegistrationAnswers
{
    public function __construct(private readonly Forms $forms) {}

    public function form(Event $event, string $roleKey): ?Form
    {
        $id = $event->roles()->where('role_key', $roleKey)->value('registration_form_id');

        return $id === null ? null : Form::query()->where('kind', 'registration')->where('active', true)->with('fields')->whereKey($id)->first();
    }

    /** Family questions are asked once: not again for the next child of the family. */
    public function asksFamily(Event $event, Form $form, Person $person): bool
    {
        $household = $person->households()->first();

        return $household === null || ! RegistrationAnswer::query()->where('event_id', $event->id)->where('form_id', $form->id)->where('household_id', $household->id)->exists();
    }

    /**
     * @param  Collection<int, FormField>|null  $fields
     * @return array<string, mixed>
     */
    public function rules(Form $form, ?Collection $fields = null): array
    {
        $rules = $this->forms->rules($form);
        foreach ($form->fields as $field) {
            $base = 'values.'.$field->key;
            if ($fields !== null && ! $fields->contains('key', $field->key)) {
                unset($rules[$base]);

                continue;
            }
            if ($field->type === 'consent' && $field->required) {
                $rules[$base] = ['required', 'in:yes']; // a required consent must be given
            }
        }

        return $rules;
    }

    /**
     * Answers of a participant including those of the family.
     *
     * @return array<string, mixed>
     */
    public function values(Participation $participation, Form $form): array
    {
        $household = $participation->person?->households()->first();
        $values = [];
        foreach (RegistrationAnswer::query()->where('event_id', $participation->event_id)->where('form_id', $form->id)
            ->where(fn ($q) => $q->where('participation_id', $participation->id)->when($household !== null, fn ($q) => $q->orWhere('household_id', $household?->id)))->get() as $answer) {
            $values += $answer->allValues();
        }

        return $values;
    }

    public function editable(Event $event): bool
    {
        $until = $event->answers_until ?? $event->starts_at;

        return now()->toDateString() <= $until->toDateString();
    }

    /** @param  array<string, mixed>  $values */
    public function save(Participation $participation, Form $form, array $values, ?Person $by): void
    {
        DB::transaction(function () use ($participation, $form, $values, $by) {
            $household = $participation->person?->households()->first();
            $family = $form->fields->where('scope', 'family');
            $own = $household === null ? $form->fields : $form->fields->where('scope', '!=', 'family');
            $changed = $this->store($participation, $form, $own, $values, $by, ['participation_id' => $participation->id, 'household_id' => null]);
            if ($household !== null && $family->contains(fn (FormField $f) => array_key_exists($f->key, $values))) {
                $changed = array_merge($changed, $this->store($participation, $form, $family, $values, $by, ['participation_id' => null, 'household_id' => $household->id]));
            }
            if ($changed !== []) {
                Audit::record('registration.answers_saved', $participation, null, ['form' => $form->key, 'form_version' => $form->version, 'changed_fields' => $changed]); // keys only
            }
        });
    }

    /**
     * @param  Collection<int, FormField>  $fields
     * @param  array<string, mixed>  $values
     * @param  array{participation_id: int|null, household_id: int|null}  $owner
     * @return list<string> changed fields
     */
    private function store(Participation $participation, Form $form, Collection $fields, array $values, ?Person $by, array $owner): array
    {
        if ($fields->isEmpty()) {
            return [];
        }
        $answer = RegistrationAnswer::query()->firstOrNew(['event_id' => $participation->event_id, 'form_id' => $form->id] + $owner);
        $before = $answer->exists ? $answer->allValues() : [];
        $normal = $confidential = $consents = [];
        foreach ($fields as $field) {
            $value = $values[$field->key] ?? null;
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $field->isConfidential() ? $confidential[$field->key] = $value : $normal[$field->key] = $value;
            if ($field->type === 'consent') {
                $consents[$field->key] = trim($field->labelText().' – '.$field->helpText(), ' –');
            }
        }
        $after = $normal + $confidential;
        $answer->fill(['values' => $normal, 'confidential' => $confidential === [] ? null : $confidential, 'consents' => $consents === [] ? null : $consents, 'form_version' => $form->version, 'answered_by_id' => $by?->id])->save();

        return array_values(array_filter($fields->pluck('key')->map('strval')->all(), fn (string $key) => ($before[$key] ?? null) != ($after[$key] ?? null)));
    }
}
