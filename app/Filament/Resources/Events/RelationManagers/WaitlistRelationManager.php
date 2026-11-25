<?php

namespace App\Filament\Resources\Events\RelationManagers;

use App\Filament\Support\WaitlistActions;
use App\Models\Event;
use App\Models\EventRole;
use App\Models\Participation;
use App\Models\Person;
use App\Support\Auth\Permissions;
use App\Support\Events\RegistrationException;
use App\Support\Events\Registrations;
use App\Support\Events\ReservePlaces;
use App\Support\Events\Rounds;
use App\Support\Events\Waitlist;
use App\Support\Mail\Composer;
use App\Support\Mail\Routing;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Waitlist of an event in its order, with the reason of the order, open offers and manual actions (AD31, WL02, WL06).
 *
 * @phpstan-import-type Place from Waitlist
 */
class WaitlistRelationManager extends RelationManager
{
    protected static string $relationship = 'participations';

    /** @var array<int, Place>|null */
    private ?array $places = null;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('events.waitlist_title');
    }

    private function mayEdit(): bool
    {
        /** @var Event $event */
        $event = $this->getOwnerRecord();

        return Permissions::currentEvent($event->id, 'edit');
    }

    private function event(): Event
    {
        /** @var Event $event */
        $event = $this->getOwnerRecord();

        return $event;
    }

    /** @return array<int, Place> by participation id, in order of role and position */
    private function places(): array
    {
        if ($this->places === null) {
            $this->places = [];
            foreach ($this->event()->roles()->get() as $role) {
                foreach (app(Waitlist::class)->ofEvent($this->event(), $role->role_key) as $place) {
                    $this->places[$place['entry']->id] = $place;
                }
            }
        }

        return $this->places;
    }

    public function table(Table $table): Table
    {
        return $table
            ->description(fn () => $this->event()->roles()->get()->filter(fn (EventRole $r) => $r->waitlistLength() > 0)
                ->map(fn (EventRole $r) => __('events.roles.'.$r->role_key).': '.__('events.waitlist_order').' '.Waitlist::ruleText($r).' · '.__('events.reserve_places_left', ['count' => $r->reserveLeft()]))->implode(' — '))
            ->modifyQueryUsing(function (Builder $query) {
                $query->whereIn('status', ['waitlist', 'offered']);
                $bindings = [];
                foreach (array_keys($this->places()) as $i => $id) {
                    array_push($bindings, $id, $i + 1);
                }
                if ($bindings !== []) { // offers first, then the order of the rules
                    $query->orderByRaw('CASE'.str_repeat(' WHEN participations.id = ? THEN ?', count($this->places())).' ELSE 0 END', $bindings);
                }

                return $query;
            })
            ->columns([
                TextColumn::make('position')->label('#')->state(fn (Participation $record) => $this->places()[$record->id]['position'] ?? '–'),
                TextColumn::make('person.last_name')->label(__('admin.person.singular'))->formatStateUsing(fn (Participation $record) => $record->person?->fullName()),
                TextColumn::make('role_key')->label(__('admin.event.role'))->formatStateUsing(fn (string $state) => __('events.roles.'.$state))->badge()->color('gray'),
                TextColumn::make('status')->label(__('admin.event.status'))->badge()
                    ->state(fn (Participation $record) => $record->status === 'offered' ? __('events.offered_until', ['date' => $record->offer_expires_at?->isoFormat('L') ?? '–']) : ($record->skipped_at !== null ? __('events.waitlist_skipped') : __('events.status.waitlist')))
                    ->color(fn (Participation $record) => $record->status === 'offered' ? 'success' : ($record->skipped_at !== null ? 'gray' : 'warning'))
                    ->tooltip(fn (Participation $record) => $record->skip_reason),
                TextColumn::make('why')->label(__('events.waitlist_why'))->state(fn (Participation $record) => WaitlistActions::score($this->places()[$record->id] ?? null))->description(fn (Participation $record) => $record->waitlist_note)->wrap(),
                TextColumn::make('registered_at')->label(__('admin.event.registered_at'))->dateTime(),
            ])
            ->filters([SelectFilter::make('role_key')->label(__('admin.event.role'))->options(__('events.roles'))])
            ->headerActions([
                Action::make('release')->label(__('events.reserve_release'))->icon('heroicon-o-lock-open')->color('gray')
                    ->visible(fn () => $this->mayEdit() && $this->event()->roles()->get()->contains(fn (EventRole $r) => $r->reserveLeft() > 0))
                    ->modalDescription(__('events.reserve_release_hint'))
                    ->schema([
                        Select::make('role')->label(__('admin.event.role'))->required()->options(fn () => $this->event()->roles()->get()->filter(fn (EventRole $r) => $r->reserveLeft() > 0)->mapWithKeys(fn (EventRole $r) => [$r->id => __('events.roles.'.$r->role_key).' ('.$r->reserveLeft().')'])->all()),
                        TextInput::make('count')->label(__('events.reserve_count'))->numeric()->minValue(1)->default(1)->required(),
                        Textarea::make('reason')->label(__('admin.finance.reason'))->required()->maxLength(250),
                    ])
                    ->action(function (array $data) {
                        $role = $this->event()->roles()->whereKey((int) $data['role'])->firstOrFail();
                        $released = app(ReservePlaces::class)->release($role, (int) $data['count'], (string) $data['reason']);
                        Notification::make()->title(__('events.reserve_released', ['count' => $released]))->success()->send();
                    }),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('offer_now')->label(__('admin.event.offer_place'))->icon('heroicon-o-gift')
                        ->visible(fn (Participation $record) => $this->mayEdit() && $record->status === 'waitlist')
                        ->schema([
                            DatePicker::make('until')->label(__('admin.event.offer_until'))->required()->minDate(now())->default(now()->addDays(7)),
                            Textarea::make('reason')->label(__('admin.finance.reason'))->required(fn (Participation $record) => ($this->places()[$record->id]['position'] ?? 1) > 1)->maxLength(250),
                        ])
                        ->action(function (Participation $record, array $data) {
                            $person = Person::query()->find($record->person_id);
                            $offered = $person !== null ? app(Rounds::class)->offer($this->event(), $person, $record->role_key, Carbon::parse((string) $data['until'])->endOfDay(), null, (string) ($data['reason'] ?? '') ?: null) : null;
                            if ($offered === null) {
                                Notification::make()->title(__('events.errors.no_place'))->danger()->send();

                                return;
                            }
                            foreach (Routing::recipients('waitlist_offer', $person, $offered) as $recipient) {
                                app(Composer::class)->queue('waitlist_offer', $recipient, ['event' => $this->event(), 'participation' => $offered->load('event', 'person'), 'household' => $person->households()->first(), 'children' => [$person]]);
                            }
                            Notification::make()->title(__('events.offered_to', ['name' => $person->fullName()]))->success()->send();
                        }),
                    Action::make('reserve')->label(__('admin.event.assign_reserve'))->icon('heroicon-o-star')
                        ->visible(fn (Participation $record) => $this->mayEdit() && $record->status === 'waitlist')
                        ->schema([Textarea::make('reason')->label(__('admin.event.reserve_reason'))->required()->maxLength(255)])
                        ->action(function (Participation $record, array $data) {
                            try {
                                app(Registrations::class)->assignReservePlace($record, (string) $data['reason']);
                            } catch (RegistrationException $e) {
                                Notification::make()->title($e->getMessage())->danger()->send();
                            }
                        }),
                    ...WaitlistActions::make(fn () => $this->mayEdit()),
                ]),
            ], RecordActionsPosition::BeforeColumns);
    }
}
