<?php

namespace App\Filament\Resources\Forms\Schemas;

use App\Models\FormField;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Form builder (SF01–SF09, FB06–FB08): fields with type, labels and help texts in German and English, sensitivity,
 * carry-over and list use.
 */
class FormForm
{
    public static function configure(Schema $schema): Schema
    {
        $hint = fn (string $key) => ['heroicon-m-question-mark-circle', __('admin.form.hints.'.$key)];

        return $schema->components([
            Section::make(__('admin.form.singular'))->columns(2)->schema([
                TextInput::make('key')->label(__('admin.form.key'))->required()->alphaDash()->maxLength(64)->unique(ignoreRecord: true),
                Toggle::make('active')->label(__('admin.form.active'))->default(true),
                TextInput::make('name.de')->label(__('admin.form.name').' (Deutsch)')->required(),
                TextInput::make('name.en')->label(__('admin.form.name').' (English)')->required(),
                Textarea::make('description.de')->label(__('admin.form.description').' (Deutsch)'),
                Textarea::make('description.en')->label(__('admin.form.description').' (English)'),
                TextInput::make('applies_until_age')->label(__('admin.form.applies_until_age'))->numeric()->minValue(1)->maxValue(120)
                    ->hintIcon(...$hint('applies_until_age')),
                TextInput::make('confirm_every_days')->label(__('admin.form.confirm_every_days'))->numeric()->minValue(1)
                    ->hintIcon(...$hint('confirm_every_days')),
                Toggle::make('family_emergency_contacts')->label(__('admin.form.family_emergency_contacts'))
                    ->hintIcon(...$hint('family_emergency_contacts')),
                TextInput::make('version')->label(__('admin.form.version'))->disabled()->dehydrated(false),
            ]),
            Section::make(__('admin.form.fields'))->schema([
                Repeater::make('fields')->relationship()->orderColumn('sort')->label('')->collapsible()->cloneable()
                    ->itemLabel(fn (array $state) => ($state['label']['de'] ?? $state['key'] ?? '').' · '.($state['type'] ?? ''))
                    ->addActionLabel(__('admin.form.add_field'))->columns(2)
                    ->schema([
                        TextInput::make('key')->label(__('admin.form.key'))->required()->alphaDash()->maxLength(64)->distinct(),
                        Select::make('type')->label(__('admin.form.type'))->options(__('admin.form.types'))->required(),
                        TextInput::make('label.de')->label(__('admin.form.label').' (Deutsch)')->required(),
                        TextInput::make('label.en')->label(__('admin.form.label').' (English)')->required(),
                        TextInput::make('help.de')->label(__('admin.form.help').' (Deutsch)'),
                        TextInput::make('help.en')->label(__('admin.form.help').' (English)'),
                        Select::make('sensitivity')->label(__('admin.form.sensitivity'))->options(__('admin.form.sensitivities'))->default('normal')->required()
                            ->hintIcon(...$hint('sensitivity')),
                        Select::make('list_use')->label(__('admin.form.list_use'))->multiple()->options(__('admin.form.lists')),
                        Toggle::make('required')->label(__('admin.form.required')),
                        Toggle::make('carry_over')->label(__('admin.form.carry_over'))->default(true)->hintIcon(...$hint('carry_over')),
                        KeyValue::make('options.choices')->label(__('admin.form.choices'))->keyLabel(__('admin.form.choice_value'))->valueLabel(__('admin.form.choice_label'))
                            ->visible(fn ($get) => in_array($get('type'), ['select', 'multiselect'], true))
                            ->formatStateUsing(fn ($state) => collect((array) $state)->map(fn ($l) => is_array($l) ? ($l['de'] ?? reset($l)) : $l)->all())
                            ->dehydrateStateUsing(fn ($state) => collect((array) $state)->map(fn ($l) => ['de' => $l, 'en' => $l])->all())
                            ->columnSpanFull(),
                    ]),
            ]),
        ]);
    }

    /** @return list<string> */
    public static function types(): array
    {
        return FormField::TYPES;
    }
}
