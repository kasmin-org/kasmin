<?php

namespace App\Filament\Resources\Groups\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class GroupsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('admin.form.name'))->searchable()->sortable(),
                TextColumn::make('parent.name')->label(__('admin.group.parent'))->placeholder('–')->toggleable(),
                TextColumn::make('kind')->label(__('admin.form.kind'))->badge()->formatStateUsing(fn (string $state) => __('admin.group.kinds.'.$state)),
                TextColumn::make('people_count')->counts('people')->label(__('admin.group.members')),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
