<?php

namespace App\Filament\Resources\Events\RelationManagers;

use App\Filament\Support\Money;
use App\Models\Event;
use App\Models\FormField;
use App\Models\Participation;
use App\Models\Person;
use App\Models\User;
use App\Support\Auth\Permissions;
use App\Support\Events\ParticipationConfirmation;
use App\Support\Events\RegistrationException;
use App\Support\Events\Registrations;
use App\Support\Events\Rounds;
use App\Support\Finance\Corrections;
use App\Support\Forms\FormValue;
use App\Support\Forms\RegistrationAnswers;
use App\Support\Lists\EventLists;
use App\Support\Mail\Composer;
use App\Support\Mail\Routing;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Participants board of an event: status, waitlist position, reserve places (AD30, AD31, WL05).
 */
class ParticipationsRelationManager extends RelationManager
{
    protected static string $relationship = 'participations';

    private function mayEdit(): bool
    {
        /** @var Event $event */
        $event = $this->getOwnerRecord();

        return Permissions::currentEvent($event->id, 'edit');
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.event.participants');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('status')->label(__('admin.event.status'))->options(collect(__('events.status'))->except('cancelled')->all())->required(),
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
                // Out of turn, with a reason (RR03).
                Action::make('offer')->visible(fn () => $this->mayEdit() && Permissions::current('people'))->label(__('admin.event.offer_place'))->icon('heroicon-o-gift')->color('gray')
                    ->schema([
                        Select::make('person_id')->label(__('admin.person.singular'))->searchable()->required()
                            ->getSearchResultsUsing(fn (string $search) => Person::query()->whereNull('anonymised_at')->where(fn ($q) => $q->where('last_name', 'like', "%{$search}%")->orWhere('first_name', 'like', "%{$search}%"))->limit(30)->get()->mapWithKeys(fn (Person $p) => [$p->id => $p->fullName()])->all())
                            ->getOptionLabelUsing(fn ($value) => Person::query()->whereKey((int) $value)->first()?->fullName()),
                        Select::make('role_key')->label(__('admin.event.role'))->options(__('events.roles'))->default('child')->required(),
                        DatePicker::make('until')->label(__('admin.event.offer_until'))->required()->minDate(now()),
                        Textarea::make('reason')->label(__('admin.finance.reason'))->required()->maxLength(250),
                    ])
                    ->action(function (array $data) {
                        /** @var Event $event */
                        $event = $this->getOwnerRecord();
                        $person = Person::query()->whereKey((int) $data['person_id'])->firstOrFail();
                        $offered = app(Rounds::class)->offer($event, $person, (string) $data['role_key'], Carbon::parse((string) $data['until'])->endOfDay(), null, (string) $data['reason']);
                        if ($offered === null) {
                            Notification::make()->title(__('events.errors.no_place'))->danger()->send();

                            return;
                        }
                        foreach (Routing::recipients('round_offer', $person, $offered) as $recipient) {
                            app(Composer::class)->queue('round_offer', $recipient, ['event' => $event, 'participation' => $offered->load('event', 'person'), 'household' => $person->households()->first(), 'children' => [$person]]);
                        }
                    }),
                CreateAction::make()->visible(fn () => $this->mayEdit() && Permissions::current('people'))->label(__('admin.event.add_participant'))
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
                ActionGroup::make([
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
                    Action::make('price')->label(__('admin.finance.reduced_place'))->icon('heroicon-o-receipt-percent')
                        ->visible(fn () => Money::canManage())
                        ->fillForm(fn (Participation $record) => ['price' => $record->price_cents === null ? null : number_format($record->price_cents / 100, 2, '.', ''), 'reason' => $record->price_reason])
                        ->schema([
                            TextInput::make('price')->label(__('admin.finance.price'))->numeric()->minValue(0)->suffix('€')->helperText(__('admin.finance.reduced_place_hint')),
                            TextInput::make('reason')->label(__('admin.finance.reason'))->maxLength(250)->requiredWith('price'),
                        ])
                        ->action(function (Participation $record, array $data) {
                            $empty = ! filled($data['price'] ?? null);
                            $record->forceFill(['price_cents' => $empty ? null : Money::toCents($data['price']), 'price_reason' => $empty ? null : $data['reason']])->save();
                            app(Corrections::class)->refreshDrafts($record->event ?? throw new Halt);
                        }),
                    Action::make('answers')->label(__('portal.answers'))->icon('heroicon-o-chat-bubble-left-right')->modalSubmitAction(false)
                        ->visible(fn (Participation $record) => $record->event !== null && app(RegistrationAnswers::class)->form($record->event, $record->role_key) !== null)
                        ->schema(function (Participation $record) {
                            $service = app(RegistrationAnswers::class);
                            $form = $record->event !== null ? $service->form($record->event, $record->role_key) : null;
                            $values = $form !== null ? $service->values($record, $form) : [];
                            $user = auth()->user();
                            $safety = $user instanceof User && EventLists::mayViewSafety($user);

                            return $form === null ? [] : $form->fields->reject(fn (FormField $f) => $f->isConfidential() && ! $safety)
                                ->map(fn (FormField $f) => TextEntry::make('a_'.$f->key)->label($f->labelText())->state(FormValue::text($f, $values[$f->key] ?? null))->placeholder('–'))->values()->all();
                        }),
                    Action::make('confirmation')->label(__('events.confirmation.title'))->icon('heroicon-o-document-check')->openUrlInNewTab()
                        ->visible(fn (Participation $record) => ParticipationConfirmation::available($record))
                        ->url(fn (Participation $record) => route('admin.participation.confirmation', $record)),
                    Action::make('cancel')->label(__('admin.event.cancel_participation'))->icon('heroicon-o-x-circle')->color('danger')->requiresConfirmation()
                        ->visible(fn (Participation $record) => $this->mayEdit() && ! in_array($record->status, ['cancelled', 'declined', 'attended', 'no_show'], true))
                        ->schema([Textarea::make('reason')->label(__('admin.finance.reason'))->required()->maxLength(250)])
                        ->action(function (Participation $record, array $data) {
                            app(Registrations::class)->cancel($record->loadMissing('event'), (string) $data['reason']);
                            app(Corrections::class)->refreshDrafts($record->event ?? throw new Halt);
                            Notification::make()->title(__('admin.event.cancelled_hint'))->success()->send();
                        }),
                    EditAction::make()->visible(fn () => $this->mayEdit())->label(__('admin.event.change_status')),
                ]),
            ], RecordActionsPosition::BeforeColumns);
    }
}
