<?php

namespace App\Support\Lists;

use App\Models\Event;
use App\Models\Participation;
use App\Models\Person;
use App\Support\Forms\Forms;
use App\Support\Forms\FormValue;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Camp folder (SF26): one page per child with emergency contacts and safety sheet, confidential.
 */
class CampFolder
{
    public function __construct(private readonly EventLists $lists, private readonly Forms $forms) {}

    public function render(Event $event, ?Person $only = null): string
    {
        return Pdf::loadView('pdf.camp-folder', ['event' => $event, 'sheets' => $this->sheets($event, $only)])->setPaper('a4')->output();
    }

    /** @return list<array{person: Person, contacts: list<array{name: string, relation: string, phone: string}>, forms: list<array{title: string, status: string, fields: list<array{label: string, value: string, changed: bool}>}>}> */
    public function sheets(Event $event, ?Person $only = null): array
    {
        $sheets = [];
        foreach ($this->lists->participants($event) as $participation) {
            /** @var Participation $participation */
            $person = $participation->person;
            if ($person === null || ($only !== null && ! $person->is($only))) {
                continue;
            }
            $forms = [];
            foreach ($this->lists->safetyForms() as $form) {
                $status = $this->forms->status($form, $person);
                if ($status === 'not_applicable') {
                    continue;
                }
                $current = $this->forms->current($form, $person);
                $changed = $this->lists->changedSince($form, $person, $event) ?? [];
                $fields = [];
                foreach ($current === null ? [] : $form->fields as $field) {
                    $fields[] = ['label' => $field->labelText(), 'value' => FormValue::text($field, $current->values[$field->key] ?? null) ?? '–', 'changed' => in_array($field->key, $changed, true)];
                }
                $forms[] = ['title' => $form->label(), 'status' => (string) __('forms.status.'.$status), 'fields' => $fields];
            }
            if ($forms !== []) {
                $sheets[] = ['person' => $person, 'contacts' => $this->forms->emergencyContacts($person), 'forms' => $forms];
            }
        }

        return $sheets;
    }
}
