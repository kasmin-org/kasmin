<?php

namespace App\Filament\Resources\Events\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class EventForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('admin.event.singular'))->columns(2)->schema([
                TextInput::make('title')->label(__('admin.event.title'))->required()->maxLength(200)->columnSpanFull(),
                Select::make('event_series_id')->label(__('admin.event.series'))->relationship('series', 'name')->createOptionForm([
                    TextInput::make('name')->label(__('admin.event.title'))->required(),
                    Select::make('type')->options(__('events.series_types'))->default('camp')->required(),
                ]),
                Select::make('status')->label(__('admin.event.status'))->options(__('events.event_status'))->default('draft')->required(),
                DateTimePicker::make('starts_at')->label(__('admin.event.starts_at'))->required()->seconds(false),
                DateTimePicker::make('ends_at')->label(__('admin.event.ends_at'))->required()->seconds(false)->afterOrEqual('starts_at'),
                TextInput::make('location')->label(__('admin.event.location'))->maxLength(200),
                TextInput::make('meeting_point')->label(__('admin.event.meeting_point'))->maxLength(200),
                Toggle::make('registration_binding')->label(__('admin.event.binding'))->default(true)
                    ->hintIcon('heroicon-m-question-mark-circle', tooltip: __('admin.event.binding_hint')),
                DatePicker::make('age_reference_date')->label(__('admin.event.age_reference'))
                    ->hintIcon('heroicon-m-question-mark-circle', tooltip: __('admin.event.age_reference_hint')),
                Textarea::make('description')->label(__('admin.event.description'))->columnSpanFull(),
            ]),
            Section::make(__('admin.event.roles'))->schema([
                Repeater::make('roles')->relationship()->label('')->columns(3)->defaultItems(1)->addActionLabel(__('admin.event.add_role'))
                    ->schema([
                        Select::make('role_key')->label(__('admin.event.role'))->options(__('events.roles'))->required()->distinct(),
                        TextInput::make('capacity')->label(__('admin.event.capacity'))->numeric()->minValue(0),
                        TextInput::make('reserve_places')->label(__('admin.event.reserve_places'))->numeric()->minValue(0)->default(0)
                            ->hintIcon('heroicon-m-question-mark-circle', tooltip: __('admin.event.reserve_hint')),
                        TextInput::make('min_age')->label(__('admin.event.min_age'))->numeric()->minValue(0)->maxValue(120),
                        TextInput::make('max_age')->label(__('admin.event.max_age'))->numeric()->minValue(0)->maxValue(120),
                        Select::make('registered_by')->label(__('admin.event.registered_by'))->options(__('admin.event.registered_by_options'))->default('guardian')->required(),
                    ]),
            ]),
        ]);
    }
}
