<?php

namespace App\Filament\Resources\Groups\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class GroupForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required(),
                Select::make('parent_id')
                    ->relationship('parent', 'name')
                    ->default(null),
                TextInput::make('kind')
                    ->required()
                    ->default('manual'),
                Textarea::make('filter')
                    ->default(null)
                    ->columnSpanFull(),
                Textarea::make('description')
                    ->default(null)
                    ->columnSpanFull(),
            ]);
    }
}
