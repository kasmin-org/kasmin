<?php

namespace App\Filament\Resources\Events\RelationManagers;

use App\Models\Event;
use App\Models\Participation;
use App\Models\Person;
use App\Support\Events\RegistrationException;
use App\Support\Events\Registrations;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Participants board of an event: status, waitlist position, reserve places (AD30, AD31, WL05).
 */
class ParticipationsRelationManager extends RelationManager
{
    protected static string $relationship = 'participations';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.event.participants');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('status')->label(__('admin.event.status'))->options(__('events.status'))->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('registered_at')
            ->columns([
                TextColumn::make('person.last_name')->label(__('admin.person.singular'))
                    ->formatStateUsing(fn (Participation $record) => $record->person?->fullName())->searchable(['first_name', 'last_name']),
                TextColumn::make('role_key')->label(__('admin.event.role'))->formatStateUsing(fn (string $state) => __('events.roles.'.$state))->badge(),
                TextColumn::make('status')->label(__('admin.event.status'))->formatStateUsing(fn (string $state) => __('events.status.'.$state))->badge()
                    ->color(fn (string $state) => match ($state) {
                        'registered', 'confirmed', 'attended' => 'success', 'waitlist', 'requested', 'offered' => 'warning', default => 'gray',
                    }),
                TextColumn::make('waitlist')->label(__('admin.event.waitlist_position'))->state(fn (Participation $record) => $record->waitlistPosition()),
                IconColumn::make('reserve_place')->label(__('admin.event.reserve'))->boolean()->tooltip(fn (Participation $record) => $record->getAttribute('reserve_reason')),
                TextColumn::make('registered_at')->label(__('admin.event.registered_at'))->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('admin.event.status'))->options(__('events.status')),
                SelectFilter::make('role_key')->label(__('admin.event.role'))->options(__('events.roles')),
            ])
            ->headerActions([
                CreateAction::make()->label(__('admin.event.add_participant'))
                    ->schema([
                        Select::make('person_id')->label(__('admin.person.singular'))->searchable()->required()
                            ->getSearchResultsUsing(fn (string $search) => Person::query()->where('last_name', 'like', "%{$search}%")->orWhere('first_name', 'like', "%{$search}%")->limit(30)->get()->mapWithKeys(fn (Person $p) => [$p->id => $p->fullName()])->all())
                            ->getOptionLabelUsing(fn ($value) => Person::query()->whereKey((int) $value)->first()?->fullName()),
                        Select::make('role_key')->label(__('admin.event.role'))->options(__('events.roles'))->default('child')->required(),
                    ])
                    ->using(function (array $data): Model {
                        /** @var Event $event */
                        $event = $this->getOwnerRecord();
                        $person = Person::query()->whereKey((int) $data['person_id'])->firstOrFail();
                        try {
                            return app(Registrations::class)->register($event, $person, (string) $data['role_key']);
                        } catch (RegistrationException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            throw new Halt;
                        }
                    }),
            ])
            ->recordActions([
                Action::make('reserve')->label(__('admin.event.assign_reserve'))->icon('heroicon-o-star')
                    ->visible(fn (Participation $record) => $record->status === 'waitlist')
                    ->schema([Textarea::make('reason')->label(__('admin.event.reserve_reason'))->required()->maxLength(255)])
                    ->action(function (Participation $record, array $data) {
                        try {
                            app(Registrations::class)->assignReservePlace($record, (string) $data['reason']);
                        } catch (RegistrationException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),
                EditAction::make()->label(__('admin.event.change_status')),
            ]);
    }
}
