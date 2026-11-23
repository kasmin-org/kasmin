<?php

namespace App\Filament\Resources\SenderProfiles\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SenderProfilesTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label(__('admin.mail.name')),
            TextColumn::make('from_address')->label(__('admin.mail.from_address')),
            TextColumn::make('account.name')->label(__('admin.mail.account')),
        ])->recordActions([EditAction::make()]);
    }
}
