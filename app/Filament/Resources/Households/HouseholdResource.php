<?php

namespace App\Filament\Resources\Households;

use App\Filament\Resources\Households\Pages\CreateHousehold;
use App\Filament\Resources\Households\Pages\EditHousehold;
use App\Filament\Resources\Households\Pages\ListHouseholds;
use App\Filament\Resources\Households\Schemas\HouseholdForm;
use App\Filament\Resources\Households\Tables\HouseholdsTable;
use App\Filament\Support\AreaAccess;
use App\Filament\Support\InArea;
use App\Models\Household;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class HouseholdResource extends Resource
{
    use AreaAccess;
    use InArea;

    public static function area(): string
    {
        return 'people';
    }

    protected static ?string $recordTitleAttribute = 'name';

    /** Global search (AD10). */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'city'];
    }

    protected static ?string $model = Household::class;

    public static function getModelLabel(): string
    {
        return __('admin.household.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.household.plural');
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    public static function form(Schema $schema): Schema
    {
        return HouseholdForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return HouseholdsTable::configure($table);
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
            'index' => ListHouseholds::route('/'),
            'create' => CreateHousehold::route('/create'),
            'edit' => EditHousehold::route('/{record}/edit'),
        ];
    }
}
