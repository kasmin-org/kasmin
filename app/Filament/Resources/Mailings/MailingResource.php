<?php

namespace App\Filament\Resources\Mailings;

use App\Filament\Resources\Mailings\Pages\CreateMailing;
use App\Filament\Resources\Mailings\Pages\EditMailing;
use App\Filament\Resources\Mailings\Pages\ListMailings;
use App\Filament\Resources\Mailings\Schemas\MailingForm;
use App\Filament\Resources\Mailings\Tables\MailingsTable;
use App\Models\Mailing;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class MailingResource extends Resource
{
    protected static ?string $model = Mailing::class;

    public static function getModelLabel(): string
    {
        return __('admin.mail.mailing');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.mail.mailing_plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.communication');
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperAirplane;

    public static function form(Schema $schema): Schema
    {
        return MailingForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MailingsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\MailsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMailings::route('/'),
            'create' => CreateMailing::route('/create'),
            'edit' => EditMailing::route('/{record}/edit'),
        ];
    }
}
