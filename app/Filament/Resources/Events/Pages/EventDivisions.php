<?php

namespace App\Filament\Resources\Events\Pages;

use App\Filament\Resources\Events\EventResource;
use App\Models\Event;
use App\Models\EventDivision;
use App\Models\EventUnit;
use App\Models\Participation;
use App\Support\Audit\Audit;
use App\Support\Auth\Permissions;
use App\Support\Events\Divisions;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;

/**
 * Groups, rooms and duties of an event (EC04): divisions with units, a proposal that spreads the people, and moving
 * people by hand.
 */
class EventDivisions extends Page
{
    use InteractsWithRecord;

    protected static string $resource = EventResource::class;

    protected string $view = 'filament.pages.event-divisions';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(Permissions::currentEvent($this->event()->id), 403);
    }

    public function getTitle(): string
    {
        return __('events.divisions.title').': '.$this->event()->title;
    }

    public function mayEdit(): bool
    {
        return Permissions::currentEvent($this->event()->id, 'edit');
    }

    protected function getHeaderActions(): array
    {
        $defaults = fn (string $key) => fn ($get) => EventDivision::DEFAULTS[$get('kind') ?: 'groups'][$key];

        return [
            Action::make('create')->label(__('events.divisions.new'))->icon('heroicon-o-plus')->visible(fn () => $this->mayEdit())
                ->schema([
                    Select::make('kind')->label(__('events.divisions.kind'))->options(__('events.divisions.kinds'))->default('groups')->required()->live()
                        ->afterStateUpdated(function ($state, $set) {
                            foreach (EventDivision::DEFAULTS[$state ?: 'groups'] as $key => $value) {
                                $set($key, $value);
                            }
                        }),
                    TextInput::make('name')->label(__('events.divisions.name'))->required()->maxLength(120)->placeholder(__('events.divisions.name_example')),
                    TextInput::make('units')->label(__('events.divisions.units'))->numeric()->minValue(1)->maxValue(100)->default(4)->required(),
                    TextInput::make('capacity')->label(__('events.divisions.capacity'))->numeric()->minValue(1)->maxValue(500)->helperText(__('events.divisions.capacity_hint')),
                    CheckboxList::make('roles')->label(__('events.divisions.roles'))->options(__('events.roles'))->default($defaults('roles'))->columns(2)->required(),
                    Toggle::make('balance_age')->label(__('events.divisions.balance_age'))->default($defaults('balance_age')),
                    Toggle::make('balance_gender')->label(__('events.divisions.balance_gender'))->default($defaults('balance_gender'))->disabled(fn ($get) => (bool) $get('separate_gender')),
                    Toggle::make('separate_gender')->label(__('events.divisions.separate_gender'))->default($defaults('separate_gender'))->live(),
                    Select::make('siblings')->label(__('events.divisions.siblings'))->options(__('events.divisions.siblings_options'))->default($defaults('siblings'))->required(),
                ])
                ->action(function (array $data) {
                    $options = ['balance_age' => (bool) $data['balance_age'], 'balance_gender' => (bool) ($data['balance_gender'] ?? false) && ! $data['separate_gender'],
                        'separate_gender' => (bool) $data['separate_gender'], 'siblings' => (string) $data['siblings'], 'roles' => array_values((array) $data['roles'])];
                    $division = app(Divisions::class)->create($this->event(), (string) $data['name'], (string) $data['kind'], (int) $data['units'], filled($data['capacity'] ?? null) ? (int) $data['capacity'] : null, $options);
                    Audit::record('event.division_created', $division);
                }),
            Action::make('lists')->label(__('events.divisions.print'))->icon('heroicon-o-printer')->color('gray')
                ->url(fn () => route('admin.lists.export', ['event' => $this->event()->id, 'key' => 'groups', 'format' => 'pdf']))->openUrlInNewTab()
                ->visible(fn () => EventDivision::query()->where('event_id', $this->event()->id)->exists()),
            Action::make('back')->label(__('admin.event.singular'))->icon('heroicon-o-arrow-left')->color('gray')->url(fn () => EventResource::getUrl('edit', ['record' => $this->getRecord()]))
                ->visible(fn () => $this->mayEdit()),
        ];
    }

    public function propose(int $divisionId, bool $restart = false): void
    {
        $division = $this->division($divisionId);
        $count = app(Divisions::class)->propose($division, $restart);
        Audit::record('event.division_proposed', $division, meta: ['placed' => $count, 'restart' => $restart]);
        Notification::make()->title(trans_choice('events.divisions.placed', $count, ['count' => $count]))->success()->send();
    }

    public function clear(int $divisionId): void
    {
        app(Divisions::class)->clear($this->division($divisionId));
    }

    public function remove(int $divisionId): void
    {
        $division = $this->division($divisionId);
        Audit::record('event.division_deleted', $division);
        $division->delete();
    }

    /** $unitId comes from a selection in the page: the id as text, or empty for "not placed". */
    public function move(int $divisionId, int $participationId, int|string|null $unitId): void
    {
        $unitId = is_numeric($unitId) ? (int) $unitId : null;
        $division = $this->division($divisionId);
        $participation = Participation::query()->where('event_id', $this->event()->id)->findOrFail($participationId);
        app(Divisions::class)->move($division, $participation, $unitId ? EventUnit::query()->where('event_division_id', $division->id)->findOrFail($unitId) : null);
    }

    public function unit(int $unitId, string $field, ?string $value): void
    {
        abort_unless($this->mayEdit() && in_array($field, ['name', 'note', 'capacity'], true), 403);
        $unit = EventUnit::query()->whereHas('division', fn ($q) => $q->where('event_id', $this->event()->id))->findOrFail($unitId);
        $value = trim((string) $value);
        if ($field === 'capacity') {
            $unit->update(['capacity' => ctype_digit($value) && (int) $value > 0 ? min(500, (int) $value) : null]);
        } elseif ($field === 'name') {
            $unit->update(['name' => $value !== '' ? mb_substr($value, 0, 120) : $unit->name]);
        } else {
            $unit->update(['note' => $value !== '' ? mb_substr($value, 0, 250) : null]);
        }
    }

    public function addUnit(int $divisionId): void
    {
        app(Divisions::class)->addUnit($this->division($divisionId));
    }

    public function removeUnit(int $unitId): void
    {
        abort_unless($this->mayEdit(), 403);
        EventUnit::query()->whereHas('division', fn ($q) => $q->where('event_id', $this->event()->id))->findOrFail($unitId)->delete();
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $service = app(Divisions::class);
        $event = $this->event();
        $divisions = [];
        foreach (EventDivision::query()->where('event_id', $event->id)->orderBy('sort')->with('units')->get() as $division) {
            $people = $service->people($division);
            $placed = $service->placed($division);
            $row = fn (Participation $p) => ['id' => $p->id, 'name' => $p->person?->fullName(), 'age' => $p->person?->ageAt($event->starts_at), 'gender' => (string) $p->person?->getAttribute('gender'), 'role' => $p->role_key, 'unit' => $placed[$p->id] ?? null];
            $divisions[] = [
                'model' => $division,
                'units' => $division->units->map(fn (EventUnit $u) => ['model' => $u, 'people' => $people->filter(fn (Participation $p) => ($placed[$p->id] ?? null) === $u->id)->map($row)->values()->all()])->all(),
                'open' => $people->reject(fn (Participation $p) => isset($placed[$p->id]))->map($row)->values()->all(),
                'warnings' => $service->warnings($division),
            ];
        }

        return ['event' => $event, 'divisions' => $divisions, 'edit' => $this->mayEdit()];
    }

    private function division(int $id): EventDivision
    {
        abort_unless($this->mayEdit(), 403);

        return EventDivision::query()->where('event_id', $this->event()->id)->findOrFail($id);
    }

    private function event(): Event
    {
        /** @var Event $event */
        $event = $this->getRecord();

        return $event;
    }
}
