<?php

namespace App\Filament\Resources\MailAccounts;

use App\Filament\Resources\MailAccounts\Pages\CreateMailAccount;
use App\Filament\Resources\MailAccounts\Pages\EditMailAccount;
use App\Filament\Resources\MailAccounts\Pages\ListMailAccounts;
use App\Filament\Resources\MailAccounts\Schemas\MailAccountForm;
use App\Filament\Resources\MailAccounts\Tables\MailAccountsTable;
use App\Models\MailAccount;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class MailAccountResource extends Resource
{
    protected static ?string $model = MailAccount::class;

    public static function getModelLabel(): string
    {
        return __('admin.mail.account');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.mail.account_plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.communication');
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    public static function form(Schema $schema): Schema
    {
        return MailAccountForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MailAccountsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMailAccounts::route('/'),
            'create' => CreateMailAccount::route('/create'),
            'edit' => EditMailAccount::route('/{record}/edit'),
        ];
    }
}
