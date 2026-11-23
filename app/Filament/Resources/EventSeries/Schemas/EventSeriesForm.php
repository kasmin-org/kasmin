<?php

namespace App\Filament\Resources\EventSeries\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class EventSeriesForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required(),
                TextInput::make('type')
                    ->required()
                    ->default('camp'),
            ]);
    }
}
