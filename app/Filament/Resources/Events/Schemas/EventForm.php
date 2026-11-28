<?php

namespace App\Filament\Resources\Events\Schemas;

use App\Filament\Support\Money;
use App\Models\Event;
use App\Models\EventDocument;
use App\Models\Form;
use App\Models\Group;
use App\Models\MembershipType;
use App\Support\Auth\Permissions;
use App\Support\Events\Bus;
use App\Support\Events\Waitlist;
use App\Support\Records\Records;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class EventForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('admin.event.singular'))->disabled(fn (?Event $record) => ! self::mayEdit($record))->columnSpanFull()->columns(['md' => 2])->schema([
                TextInput::make('title')->label(__('admin.event.title'))->required()->maxLength(200)->columnSpanFull(),
                Select::make('event_series_id')->label(__('admin.event.series'))->relationship('series', 'name')->createOptionForm([
                    TextInput::make('name')->label(__('admin.form.name'))->required()->maxLength(200),
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
                TextInput::make('max_guests')->label(__('public.guests'))->numeric()->minValue(0)->maxValue(10)->default(0)->required(),
                TextInput::make('address')->label(__('admin.event.address'))->maxLength(250),
                TextInput::make('map_url')->label(__('admin.event.map'))->url()->startsWith(['https://'])->maxLength(500),
                TextInput::make('return_point')->label(__('admin.event.return_point'))->maxLength(200),
                TextInput::make('contact_name')->label(__('admin.event.contact'))->maxLength(150),
                TextInput::make('contact_email')->label(__('lists.email'))->email()->maxLength(254),
                TextInput::make('contact_phone')->label(__('lists.phone'))->maxLength(40),
                DateTimePicker::make('announce_at')->label(__('admin.event.announce_at'))->seconds(false)->hintIcon('heroicon-m-question-mark-circle', tooltip: __('admin.event.dates_hint')),
                DateTimePicker::make('registration_opens_at')->label(__('admin.event.opens_at'))->seconds(false),
                DateTimePicker::make('registration_closes_at')->label(__('admin.event.closes_at'))->seconds(false)->after('registration_opens_at'),
                Toggle::make('public_page')->label(__('admin.event.public_page'))->inline(false)
                    ->helperText(fn (?Event $record) => $record !== null && ! $record->is_template && $record->public_page ? new HtmlString('<span style="overflow-wrap:anywhere">'.e(route('public.event', $record->public_id)).'</span>') : null),
                FileUpload::make('image_path')->label(__('admin.event.image'))->image()->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])->maxSize(5120)->disk('local')->directory('event-images')->visibility('private'),
                Repeater::make('documents')->relationship()->label(__('admin.event.documents'))->columnSpanFull()->columns(['md' => 3])->orderColumn('sort')->defaultItems(0)->addActionLabel(__('admin.event.add_document'))
                    ->schema([
                        TextInput::make('title')->label(__('admin.event.title'))->required()->maxLength(150),
                        Select::make('visibility')->label(__('admin.event.visibility'))->options(__('admin.event.visibilities'))->default('participants')->required(),
                        FileUpload::make('path')->label(__('admin.finance.file'))->required()->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])->maxSize(10240)->disk('local')->directory(EventDocument::DIRECTORY)->visibility('private'),
                    ]),
                DatePicker::make('answers_until')->label(__('admin.event.answers_until'))->hintIcon('heroicon-m-question-mark-circle', tooltip: __('admin.event.answers_until_hint')),
                DatePicker::make('reserve_reminder_on')->label(__('events.reserve_reminder_on'))->hintIcon('heroicon-m-question-mark-circle', tooltip: __('events.reserve_reminder_hint')),
                Toggle::make('reserve_auto_release')->label(__('events.reserve_auto_release'))->inline(false),
                TextInput::make('safety_confirm_days')->label(__('admin.event.safety_confirm_days'))->numeric()->minValue(1)->maxValue(365)->hintIcon('heroicon-m-question-mark-circle', tooltip: __('admin.event.safety_confirm_hint')),
                Textarea::make('description')->label(__('admin.event.description'))->columnSpanFull(),
            ]),
            Section::make(__('events.bus.title'))->disabled(fn (?Event $record) => ! self::mayEdit($record))->columnSpanFull()->columns(2)
                ->description(fn (?Event $record) => $record !== null ? Bus::summary($record) : null)->schema([
                    Toggle::make('bus_booked')->label(__('events.bus.booked'))->inline(false)->live()->helperText(__('events.bus.booked_hint')),
                    TextInput::make('bus_seats')->label(__('events.bus.seats'))->numeric()->minValue(1)->maxValue(1000)->visible(fn ($get) => (bool) $get('bus_booked'))->required(fn ($get) => (bool) $get('bus_booked'))
                        ->helperText(__('events.bus.seats_hint')),
                ]),
            Section::make(__('admin.event.roles'))->disabled(fn (?Event $record) => ! self::mayEdit($record))->columnSpanFull()->schema([
                Repeater::make('roles')->relationship()->label(__('admin.event.roles'))->hiddenLabel()->columns(['md' => 3])->defaultItems(1)->addActionLabel(__('admin.event.add_role'))
                    ->schema([
                        Select::make('role_key')->label(__('admin.event.role'))->options(__('events.roles'))->required()->distinct(),
                        TextInput::make('capacity')->label(__('admin.event.capacity'))->numeric()->minValue(0),
                        TextInput::make('reserve_places')->label(__('admin.event.reserve_places'))->numeric()->minValue(0)->default(0)->dehydrateStateUsing(fn ($state) => (int) $state)
                            ->hintIcon('heroicon-m-question-mark-circle', tooltip: __('admin.event.reserve_hint')),
                        TextInput::make('min_age')->label(__('admin.event.min_age'))->numeric()->minValue(0)->maxValue(120),
                        TextInput::make('max_age')->label(__('admin.event.max_age'))->numeric()->minValue(0)->maxValue(120),
                        Select::make('registered_by')->label(__('admin.event.registered_by'))->options(__('admin.event.registered_by_options'))->default('guardian')->required(),
                        Money::input('price_cents', __('admin.finance.price')),
                        Money::input('role_discount_cents', __('admin.finance.role_discount'))
                            ->hintIcon('heroicon-m-question-mark-circle', tooltip: __('admin.finance.role_discount_hint')),
                        Toggle::make('counts_as_sibling')->label(__('admin.finance.counts_as_sibling'))->default(true)->inline(false)
                            ->hintIcon('heroicon-m-question-mark-circle', tooltip: __('admin.finance.counts_as_sibling_hint')),
                        Select::make('waitlist_order')->label(__('events.waitlist_order'))->multiple()->reorderable()->options(collect(__('events.waitlist_criteria'))->only(Waitlist::CRITERIA)->all())->columnSpanFull()
                            ->hintIcon('heroicon-m-question-mark-circle', tooltip: __('events.waitlist_order_hint')),
                        Select::make('registration_form_id')->label(__('admin.event.registration_form'))->options(fn () => Form::query()->where('kind', 'registration')->get()->mapWithKeys(fn (Form $f) => [$f->id => $f->label()])->all())
                            ->columnSpanFull(),
                    ]),
            ]),
            Section::make(__('admin.finance.pricing'))->disabled(fn (?Event $record) => ! self::mayEdit($record) && ! Permissions::current('finance', 'edit'))->collapsible()->columnSpanFull()->columns(['md' => 3])->schema([
                Select::make('sibling_mode')->label(__('admin.finance.sibling_mode'))->options(__('finance.sibling_mode'))->default('none')->required()->live()->columnSpan(2),
                Select::make('sibling_order')->label(__('admin.finance.sibling_order'))->options(__('finance.sibling_order'))->default('oldest')->required(),
                Money::input('sibling_amount_cents', __('admin.finance.sibling_amount'))
                    ->hintIcon('heroicon-m-question-mark-circle', tooltip: __('admin.finance.sibling_amount_hint')),
                TextInput::make('sibling_cap_position')->label(__('admin.finance.sibling_cap'))->numeric()->minValue(2)
                    ->hintIcon('heroicon-m-question-mark-circle', tooltip: __('admin.finance.sibling_cap_hint'))
                    ->visible(fn ($get) => $get('sibling_mode') === 'staggered'),
                TextInput::make('sibling_min_children')->label(__('admin.finance.sibling_min'))->numeric()->minValue(2)->default(2)->dehydrateStateUsing(fn ($state) => max(2, (int) $state))
                    ->visible(fn ($get) => $get('sibling_mode') === 'all'),
                TagsInput::make('reminder_days_before')->label(__('admin.finance.reminder_days'))->placeholder('3')
                    ->hintIcon('heroicon-m-question-mark-circle', tooltip: __('admin.finance.reminder_days_hint'))
                    ->dehydrateStateUsing(fn ($state) => is_array($state) && $state !== [] ? array_values(array_map('intval', $state)) : null),
                Money::input('cancellation_fee_cents', __('admin.finance.cancellation_fee'))->hintIcon('heroicon-m-question-mark-circle', tooltip: __('admin.finance.cancellation_fee_hint')),
                Textarea::make('cancellation_text')->label(__('admin.finance.cancellation_text'))->rows(2),
                Repeater::make('prices')->relationship()->label(__('admin.finance.prices'))->columnSpanFull()->columns(['md' => 3, 'xl' => 6])
                    ->orderColumn('sort')->addActionLabel(__('admin.finance.add_price'))->defaultItems(0)->helperText(__('admin.finance.prices_hint'))
                    ->schema([
                        Select::make('role_key')->label(__('admin.event.role'))->options(__('events.roles'))->default('child')->required(),
                        TextInput::make('min_age')->label(__('admin.event.min_age'))->numeric()->minValue(0)->maxValue(120),
                        TextInput::make('max_age')->label(__('admin.event.max_age'))->numeric()->minValue(0)->maxValue(120),
                        DatePicker::make('from_date')->label(__('admin.finance.from_date')),
                        DatePicker::make('until_date')->label(__('admin.finance.until_date')),
                        Money::input('price_cents', __('admin.finance.price'))->required(),
                    ]),
                Repeater::make('installments')->relationship()->label(__('admin.finance.installments'))->columnSpanFull()->columns(['md' => 2, 'xl' => 4])
                    ->orderColumn('sort')->addActionLabel(__('admin.finance.add_installment'))->defaultItems(0)
                    ->schema([
                        TextInput::make('label')->label(__('admin.form.name'))->required()->maxLength(200)->rule(static fn (Field $component) => self::uniqueInPlan($component)),
                        Select::make('kind')->label(__('admin.finance.installment_kind'))->options(__('finance.installment_kind'))->default('fixed')->required()->live(),
                        Money::input('amount_cents', __('admin.finance.amount'))->visible(fn ($get) => $get('kind') === 'fixed'),
                        DatePicker::make('due_date')->label(__('admin.finance.due_date'))
                            ->hintIcon('heroicon-m-question-mark-circle', tooltip: __('admin.finance.due_date_hint')),
                        TextInput::make('due_days')->label(__('admin.finance.due_days'))->numeric()->minValue(0),
                        Select::make('membership_type_id')->label(__('admin.finance.membership_type'))->options(fn () => MembershipType::query()->orderBy('sort')->get()->mapWithKeys(fn (MembershipType $t) => [$t->id => $t->label()])->all())
                            ->visible(fn ($get) => $get('kind') === 'member')->required(fn ($get) => $get('kind') === 'member'),
                        Toggle::make('refundable')->label(__('admin.finance.refundable'))->inline(false)->live(),
                        TextInput::make('refund_until_days')->label(__('admin.finance.refund_until_days'))->numeric()->minValue(0)->visible(fn ($get) => (bool) $get('refundable'))
                            ->hintIcon('heroicon-m-question-mark-circle', tooltip: __('admin.finance.refund_until_hint')),
                        Toggle::make('confirms_place')->label(__('admin.finance.confirms_place'))->inline(false),
                        Select::make('paid_add_group_id')->label(__('admin.finance.paid_add_group'))->options(fn () => Group::query()->where('kind', 'manual')->orderBy('name')->pluck('name', 'id')->all()),
                        Select::make('paid_remove_group_id')->label(__('admin.finance.paid_remove_group'))->options(fn () => Group::query()->where('kind', 'manual')->orderBy('name')->pluck('name', 'id')->all()),
                        Toggle::make('discounts_apply')->label(__('admin.finance.discounts_apply'))->default(true)->inline(false)
                            ->hintIcon('heroicon-m-question-mark-circle', tooltip: __('admin.finance.discounts_apply_hint')),
                    ]),
            ]),
            Section::make(__('admin.event.rounds'))->disabled(fn (?Event $record) => ! self::mayEdit($record))->collapsible()->collapsed(fn (?Event $record) => $record === null || ! $record->rounds()->exists())->columnSpanFull()->description(__('admin.event.rounds_hint'))->schema([
                Repeater::make('rounds')->relationship()->label(__('admin.event.rounds'))->hiddenLabel()->columns(['md' => 3])->orderColumn('sort')->defaultItems(0)->addActionLabel(__('admin.event.add_round'))
                    ->schema([
                        Select::make('role_key')->label(__('admin.event.role'))->options(__('events.roles'))->default('child')->required(),
                        Select::make('kind')->label(__('admin.event.round_kind'))->options(__('admin.event.round_kinds'))->required()->live(),
                        Select::make('mode')->label(__('admin.event.round_mode'))->options(__('admin.event.round_modes'))->default('accept')->required()->visible($offers = fn ($get) => in_array($get('kind'), ['returning', 'selection'], true)),
                        DateTimePicker::make('opens_at')->label(__('public.opens_at'))->seconds(false)->required(),
                        DateTimePicker::make('closes_at')->label(__('public.closes_at'))->seconds(false)->after('opens_at')->required($offers),
                        TextInput::make('min_events')->label(__('admin.event.round_min_events'))->numeric()->minValue(0)->maxValue(20)->default(0)->visible(fn ($get) => $get('kind') === 'selection')->dehydrateStateUsing(fn ($state) => (int) $state),
                        Select::make('group_id')->label(__('admin.event.round_group'))->options(fn () => Group::query()->orderBy('name')->pluck('name', 'id')->all())->visible($offers),
                    ]),
            ]),
            Section::make(__('admin.finance.costs'))->collapsible()->collapsed()->columnSpanFull()->schema([
                Repeater::make('costs')->relationship()->label(__('admin.finance.costs'))->hiddenLabel()->columns(['md' => 3])->orderColumn('sort')->defaultItems(0)->addActionLabel(__('admin.finance.add_cost'))
                    ->schema([
                        TextInput::make('label')->label(__('admin.finance.cost_label'))->required()->maxLength(200),
                        Select::make('category')->label(__('admin.finance.cost_category'))->options(__('admin.finance.cost_categories')),
                        Money::input('amount_cents', __('admin.finance.amount')),
                    ]),
            ]),
        ]);
    }

    /**
     * Two instalments of a payment plan never have the same name (B8), regardless of upper and lower case.
     */
    private static function uniqueInPlan(Field $component): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($component): void {
            $repeater = $component->getParentRepeater();
            if ($repeater === null || ! is_string($value) || trim($value) === '') {
                return;
            }
            $item = (string) str($component->getStatePath())->after($repeater->getStatePath().'.')->before('.');
            foreach ((array) $repeater->getRawState() as $key => $row) {
                if ((string) $key !== $item && is_array($row) && Records::normalise((string) ($row['label'] ?? '')) === Records::normalise($value)) {
                    $fail((string) __('admin.finance.installment_name_taken'));

                    return;
                }
            }
        };
    }

    private static function mayEdit(?Event $event): bool
    {
        return $event?->exists ? Permissions::currentEvent($event->id, 'edit') : Permissions::current('events', 'edit');
    }
}
