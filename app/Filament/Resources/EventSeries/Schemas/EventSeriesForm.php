<?php

namespace App\Filament\Resources\EventSeries\Schemas;

use App\Filament\Support\AccountingFields;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class EventSeriesForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('admin.form.name'))
                    ->required()
                    ->maxLength(200),
                AccountingFields::costCenter(__('accounting.fields.series_cost_center_hint')),
            ]);
    }
}
