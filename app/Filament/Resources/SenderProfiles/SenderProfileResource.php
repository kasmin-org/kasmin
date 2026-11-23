<?php

namespace App\Filament\Resources\SenderProfiles;

use App\Filament\Resources\SenderProfiles\Pages\CreateSenderProfile;
use App\Filament\Resources\SenderProfiles\Pages\EditSenderProfile;
use App\Filament\Resources\SenderProfiles\Pages\ListSenderProfiles;
use App\Filament\Resources\SenderProfiles\Schemas\SenderProfileForm;
use App\Filament\Resources\SenderProfiles\Tables\SenderProfilesTable;
use App\Models\SenderProfile;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class SenderProfileResource extends Resource
{
    protected static ?string $model = SenderProfile::class;

    public static function getModelLabel(): string
    {
        return __('admin.mail.sender_profile');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.mail.sender_profile_plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.communication');
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAtSymbol;

    public static function form(Schema $schema): Schema
    {
        return SenderProfileForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SenderProfilesTable::configure($table);
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
            'index' => ListSenderProfiles::route('/'),
            'create' => CreateSenderProfile::route('/create'),
            'edit' => EditSenderProfile::route('/{record}/edit'),
        ];
    }
}
