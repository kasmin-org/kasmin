<?php

namespace App\Filament\Resources\Groups\Tables;

use App\Models\Group;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

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
                TextColumn::make('changes')->label(__('admin.group.changes'))->toggleable()->state(fn (Group $record) => '+'.DB::table('group_memberships')->where('group_id', $record->id)->where('starts_on', '>=', now()->subDays(30)->toDateString())->count()
                    .' / −'.DB::table('group_memberships')->where('group_id', $record->id)->whereBetween('ends_on', [now()->subDays(31)->toDateString(), now()->subDay()->toDateString()])->count()),
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
