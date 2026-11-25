<?php

namespace App\Filament\Resources\Events;

use App\Filament\Resources\Events\Pages\CreateEvent;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Filament\Resources\Events\Pages\ListEvents;
use App\Filament\Resources\Events\Schemas\EventForm;
use App\Filament\Resources\Events\Tables\EventsTable;
use App\Filament\Support\AreaAccess;
use App\Models\Event;
use App\Support\Auth\Permissions;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class EventResource extends Resource
{
    use AreaAccess;

    public static function area(): string
    {
        return 'events';
    }

    /** Finance roles open events for prices and reduced places; everything else is read-only for them. */
    public static function canViewAny(): bool
    {
        return Permissions::current('events') || Permissions::current('finance');
    }

    public static function canEdit(Model $record): bool
    {
        return Permissions::current('events', 'edit') || Permissions::current('finance', 'edit');
    }

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
            RelationManagers\WaitlistRelationManager::class,
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
