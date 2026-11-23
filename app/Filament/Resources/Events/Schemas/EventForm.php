<?php

namespace App\Filament\Resources\Events\Schemas;

use App\Filament\Support\Money;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
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
                        Money::input('price_cents', __('admin.finance.price')),
                        Money::input('role_discount_cents', __('admin.finance.role_discount'))
                            ->hintIcon('heroicon-m-question-mark-circle', tooltip: __('admin.finance.role_discount_hint')),
                        Toggle::make('counts_as_sibling')->label(__('admin.finance.counts_as_sibling'))->default(true)->inline(false)
                            ->hintIcon('heroicon-m-question-mark-circle', tooltip: __('admin.finance.counts_as_sibling_hint')),
                    ]),
            ]),
            Section::make(__('admin.finance.pricing'))->collapsible()->columns(3)->schema([
                Select::make('sibling_mode')->label(__('admin.finance.sibling_mode'))->options(__('finance.sibling_mode'))->default('none')->required()->live()->columnSpan(2),
                Select::make('sibling_order')->label(__('admin.finance.sibling_order'))->options(__('finance.sibling_order'))->default('oldest')->required(),
                Money::input('sibling_amount_cents', __('admin.finance.sibling_amount'))
                    ->hintIcon('heroicon-m-question-mark-circle', tooltip: __('admin.finance.sibling_amount_hint')),
                TextInput::make('sibling_cap_position')->label(__('admin.finance.sibling_cap'))->numeric()->minValue(2)
                    ->hintIcon('heroicon-m-question-mark-circle', tooltip: __('admin.finance.sibling_cap_hint'))
                    ->visible(fn ($get) => $get('sibling_mode') === 'staggered'),
                TextInput::make('sibling_min_children')->label(__('admin.finance.sibling_min'))->numeric()->minValue(2)->default(2)
                    ->visible(fn ($get) => $get('sibling_mode') === 'all'),
                TagsInput::make('reminder_days_before')->label(__('admin.finance.reminder_days'))->placeholder('3')
                    ->hintIcon('heroicon-m-question-mark-circle', tooltip: __('admin.finance.reminder_days_hint'))
                    ->dehydrateStateUsing(fn ($state) => is_array($state) && $state !== [] ? array_values(array_map('intval', $state)) : null),
                Repeater::make('installments')->relationship()->label(__('admin.finance.installments'))->columnSpanFull()->columns(4)
                    ->orderColumn('sort')->addActionLabel(__('admin.finance.add_installment'))->defaultItems(0)
                    ->schema([
                        TextInput::make('key')->label(__('admin.finance.installment_key'))->required()->alphaDash()->maxLength(32)->distinct(),
                        TextInput::make('label.de')->label(__('admin.finance.label_de'))->required(),
                        TextInput::make('label.en')->label(__('admin.finance.label_en'))->required(),
                        Select::make('kind')->label(__('admin.finance.installment_kind'))->options(__('finance.installment_kind'))->default('fixed')->required()->live(),
                        Money::input('amount_cents', __('admin.finance.amount'))->visible(fn ($get) => $get('kind') === 'fixed'),
                        DatePicker::make('due_date')->label(__('admin.finance.due_date'))
                            ->hintIcon('heroicon-m-question-mark-circle', tooltip: __('admin.finance.due_date_hint')),
                        TextInput::make('due_days')->label(__('admin.finance.due_days'))->numeric()->minValue(0),
                        Toggle::make('refundable')->label(__('admin.finance.refundable'))->inline(false),
                        Toggle::make('discounts_apply')->label(__('admin.finance.discounts_apply'))->default(true)->inline(false)
                            ->hintIcon('heroicon-m-question-mark-circle', tooltip: __('admin.finance.discounts_apply_hint')),
                    ]),
            ]),
        ]);
    }
}
