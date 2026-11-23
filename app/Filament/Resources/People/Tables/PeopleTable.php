<?php

namespace App\Filament\Resources\People\Tables;

use App\Models\Person;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PeopleTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('last_name')
            ->columns([
                TextColumn::make('last_name')->label(__('admin.person.last_name'))->searchable()->sortable(),
                TextColumn::make('first_name')->label(__('admin.person.first_name'))->searchable()->sortable(),
                TextColumn::make('birth_date')->label(__('admin.person.birth_date'))->date()->sortable()->toggleable(),
                TextColumn::make('age')->label(__('admin.person.age'))->state(fn (Person $record) => $record->ageAt()),
                TextColumn::make('households.name')->label(__('admin.household.singular'))->badge()->toggleable(),
            ])
            ->filters([
                Filter::make('children')->label(__('admin.person.filter_children'))
                    ->query(fn (Builder $query) => $query->whereHas('guardians')),
                Filter::make('guardians')->label(__('admin.person.filter_guardians'))
                    ->query(fn (Builder $query) => $query->whereHas('wards')),
            ])
            ->recordActions([ViewAction::make(), EditAction::make()]);
    }
}
