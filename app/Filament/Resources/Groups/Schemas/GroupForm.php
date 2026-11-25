<?php

namespace App\Filament\Resources\Groups\Schemas;

use App\Models\Event;
use App\Models\Group;
use App\Models\MembershipType;
use App\Models\Role;
use App\Support\Events\Waitlist;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Groups: by hand, by rules, or the participants of an event (GR02, GR03).
 */
class GroupForm
{
    public static function configure(Schema $schema): Schema
    {
        $kind = fn (string ...$kinds) => fn ($get) => in_array($get('kind'), $kinds, true);
        $number = fn ($get) => in_array($get('field'), ['age', 'events'], true);

        return $schema->components([
            Section::make(__('admin.group.singular'))->columns(['md' => 2])->columnSpanFull()->schema([
                TextInput::make('name')->label(__('admin.form.name'))->required()->maxLength(150),
                Select::make('parent_id')->label(__('admin.group.parent'))->options(fn (?Group $record) => Group::query()->when($record, fn ($q) => $q->whereKeyNot($record?->id))->orderBy('name')->pluck('name', 'id')->all()),
                Select::make('kind')->label(__('admin.form.kind'))->options(__('admin.group.kinds'))->default('manual')->required()->live(),
                Select::make('event_id')->label(__('admin.event.singular'))->options(fn () => Event::query()->orderByDesc('starts_at')->limit(100)->pluck('title', 'id')->all())
                    ->visible($kind('dynamic', 'event'))->required($kind('event'))->helperText(fn ($get) => $get('kind') === 'dynamic' ? __('admin.group.event_hint') : null),
                Select::make('filter.statuses')->label(__('admin.event.status'))->multiple()->options(__('events.status'))->default(['registered', 'confirmed', 'attended'])->visible($kind('event')),
                Select::make('filter.roles')->label(__('admin.event.role'))->multiple()->options(__('events.roles'))->visible($kind('event')),
                Textarea::make('description')->label(__('admin.form.description'))->rows(2)->columnSpanFull(),
                Toggle::make('has_waitlist')->label(__('events.group_waitlist'))->inline(false)->live()->visible($kind('manual')),
                TextInput::make('capacity')->label(__('admin.event.capacity'))->numeric()->minValue(1)->visible(fn ($get) => $get('kind') === 'manual' && $get('has_waitlist')),
                Select::make('waitlist_order')->label(__('events.waitlist_order'))->multiple()->reorderable()->options(collect(__('events.waitlist_criteria'))->only(Waitlist::CRITERIA)->all())
                    ->visible(fn ($get) => $get('kind') === 'manual' && $get('has_waitlist'))->hintIcon('heroicon-m-question-mark-circle', tooltip: __('events.waitlist_order_hint')),
            ]),
            Section::make(__('admin.group.rules'))->visible($kind('dynamic'))->columnSpanFull()->schema([
                Select::make('filter.match')->label(__('admin.group.match'))->options(__('admin.group.matches'))->default('all')->required(),
                Repeater::make('filter.rules')->label('')->columns(['md' => 4])->minItems(1)->addActionLabel(__('admin.group.add_rule'))
                    ->schema([
                        Select::make('field')->label(__('admin.group.field'))->options(__('admin.group.fields'))->required()->live(),
                        Select::make('value')->label(__('admin.group.value'))->visible(fn ($get) => filled($get('field')) && $get('field') !== 'age')->required(fn ($get) => ! in_array($get('field'), ['age', 'events'], true))
                            ->options(fn ($get) => match ($get('field')) {
                                'membership' => MembershipType::query()->get()->mapWithKeys(fn (MembershipType $t) => [$t->id => $t->label()])->all(),
                                'role' => Role::query()->get()->mapWithKeys(fn (Role $r) => [$r->id => $r->label()])->all(),
                                'group' => Group::query()->orderBy('name')->pluck('name', 'id')->all(),
                                'events' => (array) __('events.roles'),
                                default => [],
                            }),
                        TextInput::make('min')->label(__('admin.group.min'))->numeric()->visible($number),
                        TextInput::make('max')->label(__('admin.group.max'))->numeric()->visible($number),
                        Toggle::make('not')->label(__('admin.group.not'))->inline(false),
                    ]),
            ]),
        ]);
    }
}
