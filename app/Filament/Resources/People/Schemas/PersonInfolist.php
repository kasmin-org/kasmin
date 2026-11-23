<?php

namespace App\Filament\Resources\People\Schemas;

use App\Models\Form;
use App\Models\FormField;
use App\Models\Person;
use App\Models\User;
use App\Support\Forms\Forms;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PersonInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $sections = [
            Section::make(__('admin.person.section_person'))->columns(3)->schema([
                TextEntry::make('first_name')->label(__('admin.person.first_name')),
                TextEntry::make('last_name')->label(__('admin.person.last_name')),
                TextEntry::make('birth_date')->label(__('admin.person.birth_date'))->date()->placeholder('–'),
                TextEntry::make('guardians.last_name')->label(__('admin.household.guardians'))
                    ->formatStateUsing(fn ($state, Person $record) => $record->guardians->map->fullName()->implode(', '))->placeholder('–'),
                TextEntry::make('households.name')->label(__('admin.household.plural'))->badge()->placeholder('–'),
                TextEntry::make('contactPoints.value')->label(__('admin.person.section_contacts'))->listWithLineBreaks()->placeholder('–'),
            ]),
        ];

        // Safety sheet and other forms: only for the safety role and administrators (SF20); every view is logged.
        $user = auth()->user();
        $allowed = $user instanceof User && $user->person !== null
            && array_intersect($user->person->activeRoleKeys(), ['safety_officer', 'administrator']) !== [];
        if ($allowed) {
            foreach (Form::query()->where('active', true)->orderBy('sort')->with('fields')->get() as $form) {
                $sections[] = Section::make($form->label())->collapsible()->collapsed()->columns(2)
                    ->schema(fn (Person $record) => self::formEntries($form, $record));
            }
        }

        return $schema->components($sections);
    }

    /** @return list<TextEntry> */
    private static function formEntries(Form $form, Person $person): array
    {
        $forms = app(Forms::class);
        $current = $forms->current($form, $person);
        $forms->logView($form, $person);
        $entries = [TextEntry::make('status_'.$form->key)->label(__('admin.event.status'))
            ->state(__('forms.status.'.$forms->status($form, $person)).($current?->confirmed_at ? ' · '.$current->confirmed_at->isoFormat('LL') : ''))];
        if ($form->family_emergency_contacts) {
            $entries[] = TextEntry::make('emergency_'.$form->key)->label(__('forms.emergency_contacts'))->listWithLineBreaks()
                ->state(array_map(fn ($c) => $c['name'].' ('.$c['relation'].') '.$c['phone'], $forms->emergencyContacts($person)));
        }
        foreach ($form->fields as $field) {
            $entries[] = TextEntry::make('f_'.$form->key.'_'.$field->key)->label($field->labelText())->placeholder('–')
                ->state(self::display($field, $current->values[$field->key] ?? null));
        }

        return $entries;
    }

    private static function display(FormField $field, mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            $value === FormField::NONE => (string) __('forms.none'),
            in_array($field->type, ['yes_no', 'consent'], true) => (string) __('forms.'.$value),
            $field->type === 'select' => $field->choices()[(string) $value] ?? (string) $value,
            is_array($value) => collect($value)->map(fn ($v) => is_array($v) ? implode(' · ', array_filter($v)) : ($field->choices()[(string) $v] ?? $v))->implode('; '),
            default => (string) $value,
        };
    }
}
