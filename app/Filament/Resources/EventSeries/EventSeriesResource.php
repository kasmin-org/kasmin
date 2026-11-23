<?php

namespace App\Filament\Resources\EventSeries;

use App\Filament\Resources\EventSeries\Pages\CreateEventSeries;
use App\Filament\Resources\EventSeries\Pages\EditEventSeries;
use App\Filament\Resources\EventSeries\Pages\ListEventSeries;
use App\Filament\Resources\EventSeries\Schemas\EventSeriesForm;
use App\Filament\Resources\EventSeries\Tables\EventSeriesTable;
use App\Models\EventSeries;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class EventSeriesResource extends Resource
{
    protected static ?string $model = EventSeries::class;

    public static function getModelLabel(): string
    {
        return __('admin.series.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.series.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.events');
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    public static function form(Schema $schema): Schema
    {
        return EventSeriesForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EventSeriesTable::configure($table);
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
            'index' => ListEventSeries::route('/'),
            'create' => CreateEventSeries::route('/create'),
            'edit' => EditEventSeries::route('/{record}/edit'),
        ];
    }
}
