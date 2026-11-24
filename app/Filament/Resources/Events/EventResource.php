<?php

namespace App\Filament\Resources\Events;

use App\Filament\Resources\Events\Pages\CreateEvent;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Filament\Resources\Events\Pages\ListEvents;
use App\Filament\Resources\Events\Schemas\EventForm;
use App\Filament\Resources\Events\Tables\EventsTable;
use App\Models\Event;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class EventResource extends Resource
{
    protected static ?string $recordTitleAttribute = 'title';

    /** Global search (AD10). */
    public static function getGloballySearchableAttributes(): array
    {
        return ['title', 'location'];
    }

    protected static ?string $model = Event::class;

    public static function getModelLabel(): string
    {
        return __('admin.event.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.event.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.events');
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    public static function form(Schema $schema): Schema
    {
        return EventForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EventsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\ParticipationsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEvents::route('/'),
            'create' => CreateEvent::route('/create'),
            'edit' => EditEvent::route('/{record}/edit'),
        ];
    }
}
