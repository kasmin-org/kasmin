<?php

namespace App\Filament\Resources\People;

use App\Filament\Resources\People\Pages\CreatePerson;
use App\Filament\Resources\People\Pages\EditPerson;
use App\Filament\Resources\People\Pages\ListPeople;
use App\Filament\Resources\People\Pages\ViewPerson;
use App\Filament\Resources\People\Schemas\PersonForm;
use App\Filament\Resources\People\Schemas\PersonInfolist;
use App\Filament\Resources\People\Tables\PeopleTable;
use App\Filament\Support\AreaAccess;
use App\Models\Person;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class PersonResource extends Resource
{
    use AreaAccess;

    public static function area(): string
    {
        return 'people';
    }

    protected static ?string $model = Person::class;

    protected static ?string $recordTitleAttribute = 'last_name';

    /** Global search (AD10): names and e-mail addresses. */
    public static function getGloballySearchableAttributes(): array
    {
        return ['first_name', 'last_name', 'contactPoints.value'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return $record instanceof Person ? $record->fullName() : '';
    }

    public static function getModelLabel(): string
    {
        return __('admin.person.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.person.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.people');
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUser;

    public static function form(Schema $schema): Schema
    {
        return PersonForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PersonInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PeopleTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\RolesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPeople::route('/'),
            'create' => CreatePerson::route('/create'),
            'view' => ViewPerson::route('/{record}'),
            'edit' => EditPerson::route('/{record}/edit'),
        ];
    }
}
