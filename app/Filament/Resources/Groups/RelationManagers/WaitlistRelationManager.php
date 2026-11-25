<?php

namespace App\Filament\Resources\Groups\RelationManagers;

use App\Filament\Support\WaitlistActions;
use App\Models\Group;
use App\Models\GroupWaitlistEntry;
use App\Models\Person;
use App\Support\Auth\Permissions;
use App\Support\Events\RegistrationException;
use App\Support\Events\Waitlist;
use App\Support\People\GroupWaitlist;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Waitlist of a group (WL01–WL03).
 *
 * @phpstan-import-type Place from Waitlist
 */
class WaitlistRelationManager extends RelationManager
{
    protected static string $relationship = 'waitlistEntries';

    /** @var array<int, Place>|null */
    private ?array $places = null;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('events.waitlist_title');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Group && $ownerRecord->kind === 'manual' && $ownerRecord->has_waitlist;
    }

    private static function mayEdit(): bool
    {
        return Permissions::current('people', 'edit');
    }

    private function group(): Group
    {
        /** @var Group $group */
        $group = $this->getOwnerRecord();

        return $group;
    }

    /** @return array<int, Place> */
    private function places(): array
    {
        if ($this->places === null) {
            $this->places = [];
            foreach (app(Waitlist::class)->ofGroup($this->group()) as $place) {
                $this->places[$place['entry']->id] = $place;
            }
        }

        return $this->places;
    }

    public function table(Table $table): Table
    {
        return $table
            ->description(fn () => __('events.waitlist_order').' '.Waitlist::text(Waitlist::criteria($this->group()->waitlist_order)).' · '.__('events.places_left', ['count' => app(GroupWaitlist::class)->free($this->group()) ?? '∞']))
            ->modifyQueryUsing(function (Builder $query) {
                $query->whereIn('status', GroupWaitlistEntry::ACTIVE);
                $bindings = [];
                foreach (array_keys($this->places()) as $i => $id) {
                    array_push($bindings, $id, $i + 1);
                }
                if ($bindings !== []) { // offers first, then the order of the rules
                    $query->orderByRaw('CASE'.str_repeat(' WHEN group_waitlist_entries.id = ? THEN ?', count($this->places())).' ELSE 0 END', $bindings);
                }

                return $query;
            })
            ->columns([
                TextColumn::make('position')->label('#')->state(fn (GroupWaitlistEntry $record) => $this->places()[$record->id]['position'] ?? '–'),
                TextColumn::make('person.last_name')->label(__('admin.person.singular'))->formatStateUsing(fn (GroupWaitlistEntry $record) => $record->person?->fullName()),
                TextColumn::make('status')->label(__('admin.event.status'))->badge()
                    ->state(fn (GroupWaitlistEntry $record) => $record->status === 'offered' ? __('events.offered_until', ['date' => $record->offer_expires_at?->isoFormat('L') ?? '–']) : ($record->skipped_at !== null ? __('events.waitlist_skipped') : __('events.status.waitlist')))
                    ->tooltip(fn (GroupWaitlistEntry $record) => $record->skip_reason),
                TextColumn::make('why')->label(__('events.waitlist_why'))->state(fn (GroupWaitlistEntry $record) => WaitlistActions::score($this->places()[$record->id] ?? null))->description(fn (GroupWaitlistEntry $record) => $record->waitlist_note)->wrap(),
                TextColumn::make('listed_at')->label(__('admin.event.registered_at'))->dateTime(),
            ])
            ->headerActions([
                Action::make('add')->label(__('admin.group.add'))->icon('heroicon-o-plus')->visible(fn () => self::mayEdit())
                    ->schema([
                        Select::make('person_id')->label(__('admin.person.singular'))->searchable()->required()
                            ->getSearchResultsUsing(fn (string $search) => Person::query()->whereNull('anonymised_at')->where(fn ($q) => $q->where('last_name', 'like', "%{$search}%")->orWhere('first_name', 'like', "%{$search}%"))->limit(30)->get()->mapWithKeys(fn (Person $p) => [$p->id => $p->fullName()])->all())
                            ->getOptionLabelUsing(fn ($value) => Person::query()->whereKey((int) $value)->first()?->fullName()),
                        TextInput::make('note')->label(__('events.waitlist_note'))->maxLength(250)->helperText(__('events.waitlist_note_hint')),
                    ])
                    ->action(function (array $data) {
                        try {
                            app(GroupWaitlist::class)->add($this->group(), Person::query()->whereKey((int) $data['person_id'])->firstOrFail(), $data['note'] ?? null);
                        } catch (RegistrationException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('offer')->label(__('admin.event.offer_place'))->icon('heroicon-o-gift')
                        ->visible(fn (GroupWaitlistEntry $record) => self::mayEdit() && $record->status === 'waiting')
                        ->schema([DatePicker::make('until')->label(__('admin.event.offer_until'))->required()->minDate(now())->default(now()->addDays(14))])
                        ->action(function (GroupWaitlistEntry $record, array $data) {
                            app(GroupWaitlist::class)->offer($record, Carbon::parse((string) $data['until'])->endOfDay())
                                ? Notification::make()->title(__('events.offered_to', ['name' => $record->person?->fullName()]))->success()->send()
                                : Notification::make()->title(__('events.errors.no_place'))->danger()->send();
                        }),
                    ...WaitlistActions::make(fn () => self::mayEdit()),
                    Action::make('remove')->label(__('admin.group.remove'))->icon('heroicon-o-x-mark')->color('danger')->requiresConfirmation()->visible(fn () => self::mayEdit())
                        ->action(fn (GroupWaitlistEntry $record) => $record->fill(['status' => 'removed', 'offer_expires_at' => null])->save()),
                ]),
            ], RecordActionsPosition::BeforeColumns);
    }
}
