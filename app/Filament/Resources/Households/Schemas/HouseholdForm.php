<?php

namespace App\Filament\Resources\Households\Schemas;

use App\Models\Household;
use App\Models\Person;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

class HouseholdForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('admin.household.singular'))->columns(2)->schema([
                TextInput::make('name')->label(__('admin.household.name'))->required()->maxLength(200),
                TextInput::make('street')->label(__('admin.household.street'))->maxLength(200),
                TextInput::make('postal_code')->label(__('admin.household.postal_code'))->maxLength(16),
                TextInput::make('city')->label(__('admin.household.city'))->maxLength(120),
                TextInput::make('country')->label(__('admin.household.country'))->length(2)->default('AT'),
            ]),
            Section::make(__('admin.household.members'))->schema([
                Select::make('guardianMembers')->label(__('admin.household.guardians'))->multiple()->searchable()->preload()
                    ->relationship('guardians', 'last_name', modifyQueryUsing: null)
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->fullName())
                    ->pivotData(['role' => 'guardian']),
                Select::make('childMembers')->label(__('admin.household.children'))->multiple()->searchable()->preload()
                    ->relationship('children', 'last_name')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->fullName())
                    ->pivotData(['role' => 'child']),
                Select::make('invoice_recipient_id')->label(__('admin.overview.invoice_recipient'))->placeholder(__('admin.overview.invoice_recipient_auto'))
                    ->options(fn (?Household $record) => $record === null ? [] : $record->guardians()->get()->mapWithKeys(fn (Person $p) => [$p->id => $p->fullName()])->all())
                    ->visibleOn('edit'),
            ]),
            Section::make(__('admin.overview.title'))->visibleOn('edit')->collapsible()->schema([View::make('filament.household-overview')]),
        ]);
    }
}
