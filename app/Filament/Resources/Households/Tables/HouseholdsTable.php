<?php

namespace App\Filament\Resources\Households\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class HouseholdsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label(__('admin.household.name'))->searchable()->sortable(),
                TextColumn::make('guardians.last_name')->label(__('admin.household.guardians'))->badge(),
                TextColumn::make('children_count')->counts('children')->label(__('admin.household.children')),
                TextColumn::make('city')->label(__('admin.household.city'))->toggleable(),
            ])
            ->recordActions([EditAction::make()]);
    }
}
