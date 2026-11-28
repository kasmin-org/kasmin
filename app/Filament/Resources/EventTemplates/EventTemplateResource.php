<?php

namespace App\Filament\Resources\EventTemplates;

use App\Filament\Resources\Events\Schemas\EventForm;
use App\Filament\Resources\EventTemplates\Pages\CreateEventTemplate;
use App\Filament\Resources\EventTemplates\Pages\EditEventTemplate;
use App\Filament\Resources\EventTemplates\Pages\ListEventTemplates;
use App\Filament\Support\AreaAccess;
use App\Filament\Support\InArea;
use App\Models\EventTemplate;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Event types (ET01): everything a new event starts with.
 */
class EventTemplateResource extends Resource
{
    use AreaAccess;
    use InArea;

    public static function area(): string
    {
        return 'events';
    }

    protected static ?string $model = EventTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookmarkSquare;

    public static function getModelLabel(): string
    {
        return __('admin.event.template');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.event.templates');
    }

    public static function form(Schema $schema): Schema
    {
        return EventForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('title')->columns([
            TextColumn::make('title')->label(__('admin.event.title'))->searchable(),
            TextColumn::make('days')->label(__('admin.event.days'))->state(fn (EventTemplate $record) => (int) $record->starts_at->copy()->startOfDay()->diffInDays($record->ends_at->copy()->startOfDay()) + 1),
        ])->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ListEventTemplates::route('/'), 'create' => CreateEventTemplate::route('/create'), 'edit' => EditEventTemplate::route('/{record}/edit')];
    }
}
