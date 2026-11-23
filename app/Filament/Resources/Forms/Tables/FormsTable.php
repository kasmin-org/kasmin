<?php

namespace App\Filament\Resources\Forms\Tables;

use App\Models\Form;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FormsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->columns([
                TextColumn::make('name')->label(__('admin.form.name'))->state(fn (Form $record) => $record->label()),
                TextColumn::make('fields_count')->counts('fields')->label(__('admin.form.fields')),
                TextColumn::make('version')->label(__('admin.form.version')),
                TextColumn::make('applies_until_age')->label(__('admin.form.applies_until_age')),
                IconColumn::make('active')->label(__('admin.form.active'))->boolean(),
            ])
            ->recordActions([EditAction::make()]);
    }
}
