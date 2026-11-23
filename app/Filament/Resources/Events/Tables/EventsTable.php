<?php

namespace App\Filament\Resources\Events\Tables;

use App\Models\Event;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class EventsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('starts_at', 'desc')
            ->columns([
                TextColumn::make('title')->label(__('admin.event.title'))->searchable()->sortable(),
                TextColumn::make('series.name')->label(__('admin.event.series'))->toggleable(),
                TextColumn::make('starts_at')->label(__('admin.event.starts_at'))->date()->sortable(),
                TextColumn::make('status')->label(__('admin.event.status'))->formatStateUsing(fn (string $state) => __('events.event_status.'.$state))->badge(),
                TextColumn::make('occupied')->label(__('admin.event.occupied'))
                    ->state(fn (Event $record) => $record->roles->map(fn ($r) => __('events.roles.'.$r->role_key).': '.$r->occupied().($r->capacity !== null ? '/'.$r->capacity : '').' · '.__('admin.event.waitlist_short').' '.$r->waitlistLength())->implode(' | ')),
            ])
            ->recordActions([EditAction::make()]);
    }
}
