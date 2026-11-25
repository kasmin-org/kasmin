<?php

namespace App\Filament\Resources\People\Schemas;

use App\Models\Person;
use App\Support\Auth\Permissions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PersonForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('admin.person.section_person'))->columns(2)->schema([
                TextInput::make('first_name')->label(__('admin.person.first_name'))->required()->maxLength(120),
                TextInput::make('last_name')->label(__('admin.person.last_name'))->required()->maxLength(120),
                DatePicker::make('birth_date')->label(__('admin.person.birth_date'))->maxDate(now())
                    ->hintIcon('heroicon-m-question-mark-circle', tooltip: __('admin.person.birth_date_hint')),
                Select::make('gender')->label(__('admin.person.gender'))->options(__('admin.genders'))->placeholder('–'),
                Select::make('locale')->label(__('admin.person.locale'))->options(['de' => 'Deutsch', 'en' => 'English'])->default('de')->required(),
            ]),
            Section::make(__('admin.person.section_contacts'))->schema([
                Repeater::make('contactPoints')->relationship()->disabled(fn (?Person $record) => Permissions::administrative($record) && ! Permissions::administrator())->label('')->columns(3)->defaultItems(0)
                    ->addActionLabel(__('admin.person.add_contact'))
                    // The address of the login cannot be taken away here; it is changed in the portal with a confirmation (as in the portal and the API).
                    ->rule(fn (?Person $record) => function (string $attribute, mixed $value, \Closure $fail) use ($record): void {
                        $login = $record?->user?->email;
                        $values = array_map(fn ($row) => mb_strtolower(trim((string) (is_array($row) ? ($row['value'] ?? '') : ''))), is_array($value) ? $value : []);
                        if ($login !== null && ! in_array(mb_strtolower($login), $values, true)) {
                            $fail(__('admin.person.login_address_needed', ['address' => $login]));
                        }
                    })
                    ->schema([
                        Select::make('type')->label(__('admin.person.contact_type'))->options(['email' => __('admin.person.email'), 'phone' => __('admin.person.phone')])->required()->live(),
                        TextInput::make('value')->label(__('admin.person.contact_value'))->required()->maxLength(254)
                            ->email(fn ($get) => $get('type') === 'email')->tel(fn ($get) => $get('type') === 'phone'),
                        Select::make('purposes')->label(__('admin.person.purposes'))->multiple()->options(__('admin.purposes')),
                        Toggle::make('is_primary')->label(__('admin.person.primary')),
                    ]),
            ]),
        ]);
    }
}
