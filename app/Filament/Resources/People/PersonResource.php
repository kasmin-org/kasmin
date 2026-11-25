<?php

namespace App\Filament\Resources\People;

use App\Filament\Resources\People\Pages\CreatePerson;
use App\Filament\Resources\People\Pages\EditPerson;
use App\Filament\Resources\People\Pages\ListPeople;
use App\Filament\Resources\People\Pages\PersonTimeline;
use App\Filament\Resources\People\Pages\ViewPerson;
use App\Filament\Resources\People\Schemas\PersonForm;
use App\Filament\Resources\People\Schemas\PersonInfolist;
use App\Filament\Resources\People\Tables\PeopleTable;
use App\Filament\Support\AreaAccess;
use App\Models\Participation;
use App\Models\Person;
use App\Support\Auth\Permissions;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PersonResource extends Resource
{
    use AreaAccess;

    public static function area(): string
    {
        return 'people';
    }

    public static function canViewAny(): bool
    {
        return Permissions::current('people') || Permissions::restriction('people') !== [];
    }

    public static function canView(Model $record): bool
    {
        return self::canViewAny() && self::getEloquentQuery()->whereKey($record->getKey())->exists();
    }

    /**
     * Roles for the own events only see the people of these events and their legal guardians (RO01). What somebody
     * registered without the legal guardians stays private, also for them (PE05a).
     *
     * @return Builder<Person>
     */
    public static function getEloquentQuery(): Builder
    {
        $own = Permissions::restriction('people');
        $query = Person::query();
        if ($own === null) {
            return $query;
        }
        $active = Participation::query()->whereIn('event_id', $own)->whereNotIn('status', ['cancelled', 'declined']);
        $people = (clone $active)->pluck('person_id');
        $shared = (clone $active)->get()->reject(fn (Participation $p) => $p->isOwn())->pluck('person_id');

        return $query->where(fn ($q) => $q->whereIn('people.id', $people)->orWhereHas('wards', fn ($q) => $q->whereIn($q->qualifyColumn('id'), $shared)));
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
            'timeline' => PersonTimeline::route('/{record}/timeline'),
            'edit' => EditPerson::route('/{record}/edit'),
        ];
    }
}
