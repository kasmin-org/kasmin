<?php

namespace App\Filament\Resources\Events\Tables;

use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\EventTemplates\EventTemplateResource;
use App\Models\Event;
use App\Support\Events\EventCopier;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

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
            ->recordActions([
                EditAction::make(),
                ActionGroup::make([
                    Action::make('copy')->label(__('admin.event.copy'))->icon('heroicon-o-document-duplicate')
                        ->fillForm(fn (Event $record) => ['title' => self::nextTitle($record), 'starts_on' => $record->starts_at->copy()->addWeeks(52)->toDateString()])
                        ->schema(self::copyFields())
                        ->action(fn (Event $record, array $data, $livewire) => $livewire->redirect(EventResource::getUrl('edit', ['record' => app(EventCopier::class)->copy($record, (string) $data['title'], Carbon::parse((string) $data['starts_on']))]))),
                    Action::make('template')->label(__('admin.event.save_template'))->icon('heroicon-o-bookmark-square')
                        ->visible(fn () => EventTemplateResource::canViewAny())
                        ->fillForm(fn (Event $record) => ['title' => $record->series->name ?? $record->title])
                        ->schema([TextInput::make('title')->label(__('admin.event.title'))->required()->maxLength(200)])
                        ->action(function (Event $record, array $data) {
                            app(EventCopier::class)->copy($record, (string) $data['title'], $record->starts_at, asTemplate: true);
                            Notification::make()->title(__('admin.event.template_saved'))->success()->send();
                        }),
                ]),
            ]);
    }

    /** @return list<TextInput|DatePicker> */
    public static function copyFields(): array
    {
        return [
            TextInput::make('title')->label(__('admin.event.title'))->required()->maxLength(200),
            DatePicker::make('starts_on')->label(__('admin.event.starts_at'))->required()->helperText(__('admin.event.copy_hint')),
        ];
    }

    /** "Camp 2027" becomes "Camp 2028". */
    private static function nextTitle(Event $event): string
    {
        $year = $event->starts_at->year;

        return str_replace((string) $year, (string) ($year + 1), $event->title);
    }
}
