<?php

namespace App\Filament\Resources\Forms\Schemas;

use App\Models\FormField;
use App\Support\Forms\ChoiceLists;
use App\Support\Forms\Formats;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
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
            Section::make(__('admin.form.singular'))->columnSpanFull()->columns(['md' => 2])->schema([
                TextInput::make('key')->label(__('admin.form.key'))->required()->alphaDash()->maxLength(64)->unique(ignoreRecord: true),
                Toggle::make('active')->label(__('admin.form.active'))->default(true),
                Select::make('kind')->label(__('admin.form.kind'))->options(__('admin.form.kinds'))->default('person')->required()->live()->disabledOn('edit')->hintIcon(...$hint('kind')),
                TextInput::make('name.de')->label(__('admin.form.name').' (Deutsch)')->required(),
                TextInput::make('name.en')->label(__('admin.form.name').' (English)')->required(),
                Textarea::make('description.de')->label(__('admin.form.description').' (Deutsch)'),
                Textarea::make('description.en')->label(__('admin.form.description').' (English)'),
                TextInput::make('applies_until_age')->label(__('admin.form.applies_until_age'))->numeric()->minValue(1)->maxValue(120)->visible(fn ($get) => $get('kind') !== 'registration')
                    ->hintIcon(...$hint('applies_until_age')),
                TextInput::make('confirm_every_days')->label(__('admin.form.confirm_every_days'))->numeric()->minValue(1)->visible(fn ($get) => $get('kind') !== 'registration')
                    ->hintIcon(...$hint('confirm_every_days')),
                Toggle::make('family_emergency_contacts')->label(__('admin.form.family_emergency_contacts'))->visible(fn ($get) => $get('kind') !== 'registration')
                    ->hintIcon(...$hint('family_emergency_contacts')),
                TextInput::make('version')->label(__('admin.form.version'))->disabled()->dehydrated(false),
            ]),
            Section::make(__('admin.form.fields'))->columnSpanFull()->schema([
                Repeater::make('fields')->relationship()->orderColumn('sort')->label(__('admin.form.fields'))->hiddenLabel()->collapsible()->cloneable()
                    ->itemLabel(fn (array $state) => ($state['label']['de'] ?? $state['key'] ?? '').' · '.($state['type'] ?? ''))
                    ->addActionLabel(__('admin.form.add_field'))->columns(['md' => 2])
                    ->mutateRelationshipDataBeforeFillUsing(fn (array $data) => self::toRows($data))
                    ->mutateRelationshipDataBeforeSaveUsing(fn (array $data) => self::fromRows($data))
                    ->mutateRelationshipDataBeforeCreateUsing(fn (array $data) => self::fromRows($data))
                    ->schema([
                        TextInput::make('key')->label(__('admin.form.key'))->required()->alphaDash()->maxLength(64)->distinct(),
                        Select::make('type')->label(__('admin.form.type'))->options(__('admin.form.types'))->required()->live(),
                        TextInput::make('label.de')->label(__('admin.form.label').' (Deutsch)')->required(),
                        TextInput::make('label.en')->label(__('admin.form.label').' (English)')->required(),
                        TextInput::make('help.de')->label(__('admin.form.help').' (Deutsch)'),
                        TextInput::make('help.en')->label(__('admin.form.help').' (English)'),
                        Select::make('sensitivity')->label(__('admin.form.sensitivity'))->options(__('admin.form.sensitivities'))->default('normal')->required()
                            ->hintIcon(...$hint('sensitivity')),
                        Select::make('list_use')->label(__('admin.form.list_use'))->multiple()->options(__('admin.form.lists')),
                        Select::make('scope')->label(__('admin.form.scope'))->options(__('admin.form.scopes'))->default('participant')->required()->visible(fn ($get) => $get('../../kind') === 'registration'),
                        Toggle::make('required')->label(__('admin.form.required')),
                        Toggle::make('carry_over')->label(__('admin.form.carry_over'))->default(true)->hintIcon(...$hint('carry_over')),
                        ...self::options(),
                    ]),
            ]),
        ]);
    }

    /**
     * All settings of a type are shown together, so saving never loses one of them.
     *
     * @return list<Component>
     */
    private static function options(): array
    {
        $is = fn (string ...$types) => fn ($get) => in_array($get('type'), $types, true);
        $rows = fn (string $name, string $label, string $add) => Repeater::make($name)->label($label)->addActionLabel($add)->columns(['md' => 3])->columnSpanFull()->defaultItems(0)->reorderable()
            ->schema([
                TextInput::make('value')->label(__('admin.form.choice_value'))->required()->maxLength(64)->distinct(),
                TextInput::make('de')->label('Deutsch')->required()->maxLength(200),
                TextInput::make('en')->label('English')->required()->maxLength(200),
            ]);

        return [
            $rows('options.choices', __('admin.form.choices'), __('admin.form.add_choice'))->visible($is('select', 'multiselect')),
            Select::make('options.list')->label(__('admin.form.choice_list'))->options(ChoiceLists::options())->visible($is('select', 'multiselect'))->hintIcon('heroicon-m-question-mark-circle', tooltip: __('admin.form.hints.choice_list')),
            Toggle::make('options.other')->label(__('admin.form.allow_other'))->inline(false)->visible($is('select', 'multiselect')),
            $rows('options.fields', __('admin.form.sub_fields'), __('admin.form.add_field'))->visible($is('repeater')),
            Toggle::make('options.number')->label(__('admin.form.with_number'))->inline(false)->visible($is('person')),
            Select::make('options.format')->label(__('admin.form.format'))->options(Formats::options())->visible($is('text', 'person'))->hintIcon('heroicon-m-question-mark-circle', tooltip: __('admin.form.hints.format')),
            TextInput::make('options.min')->label(__('admin.form.min'))->numeric()->visible($is('number')),
            TextInput::make('options.max')->label(__('admin.form.max'))->numeric()->visible($is('number')),
            DatePicker::make('options.min')->label(__('admin.form.min'))->visible($is('date')),
            DatePicker::make('options.max')->label(__('admin.form.max'))->visible($is('date')),
        ];
    }

    /**
     * Choices and columns are stored as value => texts and edited as rows.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function toRows(array $data): array
    {
        foreach (['choices', 'fields'] as $part) {
            if (is_array($data['options'] ?? null) && isset($data['options'][$part])) {
                $data['options'][$part] = collect((array) $data['options'][$part])->map(fn ($l, $v) => ['value' => (string) $v, 'de' => is_array($l) ? ($l['de'] ?? '') : (string) $l, 'en' => is_array($l) ? ($l['en'] ?? '') : (string) $l])->values()->all();
            }
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function fromRows(array $data): array
    {
        foreach (['choices', 'fields'] as $part) {
            if (is_array($data['options'] ?? null) && isset($data['options'][$part])) {
                $data['options'][$part] = collect((array) $data['options'][$part])->filter(fn ($r) => is_array($r) && filled($r['value'] ?? null))->mapWithKeys(fn ($r) => [(string) $r['value'] => ['de' => $r['de'] ?? '', 'en' => $r['en'] ?? '']])->all();
            }
        }

        return $data;
    }

    /** @return list<string> */
    public static function types(): array
    {
        return FormField::TYPES;
    }
}
