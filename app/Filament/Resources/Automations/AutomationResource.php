<?php

namespace App\Filament\Resources\Automations;

use App\Filament\Resources\AutomationRuns\AutomationRunResource;
use App\Filament\Resources\Automations\Pages\CreateAutomation;
use App\Filament\Resources\Automations\Pages\EditAutomation;
use App\Filament\Resources\Automations\Pages\ListAutomations;
use App\Filament\Resources\Groups\Schemas\GroupForm;
use App\Filament\Support\AreaAccess;
use App\Models\Automation;
use App\Models\Event;
use App\Models\EventSeries;
use App\Models\Group;
use App\Models\MailTemplate;
use App\Models\Role;
use App\Models\User;
use App\Support\Auth\Permissions;
use App\Support\Automations\Actions\Custom;
use App\Support\Automations\Engine;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/**
 * Automations (AD53): mode, trigger, settings, dry run and "run now".
 */
class AutomationResource extends Resource
{
    use AreaAccess;

    public static function area(): string
    {
        return 'automations';
    }

    protected static ?string $model = Automation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static ?int $navigationSort = 10;

    public static function getModelLabel(): string
    {
        return __('automations.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('automations.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.automations');
    }

    /** Shipped automations can be switched off, but not deleted. */
    public static function canDelete(Model $record): bool
    {
        return $record instanceof Automation && ! $record->is_standard && Permissions::current('automations', 'edit');
    }

    public static function form(Schema $schema): Schema
    {
        $action = fn (Get $get) => (string) $get('action');
        $is = fn (string ...$actions) => fn (Get $get) => in_array($action($get), $actions, true);

        return $schema->components([
            Section::make()->columnSpanFull()->columns(['md' => 2, 'xl' => 3])->schema([
                TextInput::make('name')->label(__('automations.name'))->required(),
                Select::make('action')->label(__('automations.action'))->options(__('automations.actions'))->default('custom')->disabled()->dehydrated(false),
                Toggle::make('active')->label(__('automations.active'))->inline(false)->hiddenOn('create'),
                Select::make('mode')->label(__('automations.mode'))->options(__('automations.modes'))->default('confirm')->required()
                    ->hintIcon('heroicon-m-question-mark-circle', tooltip: __('automations.mode_hint')),
                TextInput::make('max_items')->label(__('automations.max_items'))->numeric()->minValue(1)->default(200)->required()
                    ->hintIcon('heroicon-m-question-mark-circle', tooltip: __('automations.max_items_hint')),
                TextInput::make('approvals_needed')->label(__('automations.approvals_needed'))->numeric()->minValue(1)->maxValue(10)->default(1)->required()
                    ->hintIcon('heroicon-m-question-mark-circle', tooltip: __('automations.approvals_needed_hint')),
                CheckboxList::make('approver_roles')->label(__('automations.approver_roles'))->columns(['sm' => 2, 'xl' => 4])->columnSpanFull()
                    ->options(fn () => Role::query()->where('is_admin', true)->get()->mapWithKeys(fn (Role $r) => [$r->key => $r->label()])->all())
                    ->hintIcon('heroicon-m-question-mark-circle', tooltip: __('automations.approver_roles_hint')),
            ]),
            Section::make(__('automations.trigger'))->columnSpanFull()->columns(['md' => 2, 'xl' => 3])->schema([
                Select::make('trigger')->label(__('automations.trigger'))->options(__('automations.triggers'))->required()->live(),
                TextInput::make('trigger_config.time')->label(__('automations.time'))->regex('/^\d{2}:\d{2}$/')->placeholder('08:00')
                    ->visible(fn (Get $get) => in_array($get('trigger'), ['daily', 'weekly', 'monthly', 'once'], true)),
                Select::make('trigger_config.weekday')->label(__('automations.weekday'))->options(__('automations.weekdays'))->default(1)->visible(fn (Get $get) => $get('trigger') === 'weekly')->required(fn (Get $get) => $get('trigger') === 'weekly'),
                TextInput::make('trigger_config.day')->label(__('automations.month_day'))->numeric()->minValue(1)->maxValue(31)->default(1)->visible(fn (Get $get) => $get('trigger') === 'monthly'),
                DatePicker::make('trigger_config.date')->label(__('lists.date'))->visible(fn (Get $get) => $get('trigger') === 'once')->required(fn (Get $get) => $get('trigger') === 'once'),
                Select::make('trigger_config.statuses')->label(__('admin.event.status'))->multiple()->options(__('events.status'))->visible(fn (Get $get) => $get('trigger') === 'on_status')->required(fn (Get $get) => $get('trigger') === 'on_status'),
                Select::make('trigger_config.roles')->label(__('admin.event.role'))->multiple()->options(__('events.roles'))->visible(fn (Get $get) => $get('trigger') === 'on_status'),
                Select::make('trigger_config.group')->label(__('admin.group.singular'))->options(fn () => Group::query()->orderBy('name')->pluck('name', 'id')->all())->visible(fn (Get $get) => in_array($get('trigger'), ['on_group_joined', 'on_group_left'], true))->required(fn (Get $get) => in_array($get('trigger'), ['on_group_joined', 'on_group_left'], true)),
                Select::make('trigger_config.fields')->label(__('automations.fields_label'))->multiple()->options(__('automations.fields'))->visible(fn (Get $get) => $get('trigger') === 'on_person_changed')->required(fn (Get $get) => $get('trigger') === 'on_person_changed'),
                TextInput::make('trigger_config.days')->label(__('automations.days'))->numeric()->minValue(0)
                    ->visible(fn (Get $get) => in_array($get('trigger'), ['before_event', 'after_event'], true)),
                Select::make('trigger_config.series')->label(__('automations.series'))->options(fn () => EventSeries::query()->pluck('name', 'id')->all())
                    ->visible(fn (Get $get) => in_array($get('trigger'), ['before_event', 'after_event', 'on_status'], true)),
            ]),
            Section::make(__('automations.target'))->columnSpanFull()->visible($is('custom'))->columns(['md' => 2])->schema([
                Select::make('config.target.source')->label(__('automations.source'))->options(__('automations.sources'))->required()->live()
                    ->rule(fn (Get $get) => function (string $attribute, mixed $value, \Closure $fail) use ($get) {
                        $change = in_array($get('trigger'), ['on_status', 'on_group_joined', 'on_group_left', 'on_person_created', 'on_person_changed'], true);
                        if (($value === 'subject') !== $change) {
                            $fail(__('automations.source_mismatch'));
                        }
                        if ($value === 'event' && ! in_array($get('trigger'), ['manual', 'before_event', 'after_event', 'on_place_freed'], true)) {
                            $fail(__('automations.event_needed'));
                        }
                    }),
                Select::make('config.target.statuses')->label(__('admin.event.status'))->multiple()->options(__('events.status'))->visible(fn (Get $get) => $get('config.target.source') === 'event'),
                Select::make('config.target.roles')->label(__('admin.event.role'))->multiple()->options(__('events.roles'))->visible(fn (Get $get) => $get('config.target.source') === 'event'),
                Select::make('config.target.group_id')->label(__('admin.group.singular'))->options(fn () => Group::query()->orderBy('name')->pluck('name', 'id')->all())->visible(fn (Get $get) => $get('config.target.source') === 'group')->required(fn (Get $get) => $get('config.target.source') === 'group'),
                Select::make('config.conditions.match')->label(__('admin.group.match'))->options(__('admin.group.matches'))->default('all'),
                Toggle::make('config.once')->label(__('automations.once'))->inline(false),
                GroupForm::rules('config.conditions.rules')->label(__('automations.conditions'))->hiddenLabel(false)->defaultItems(0)->columnSpanFull()->minItems(fn (Get $get) => $get('config.target.source') === 'people' ? 1 : 0),
            ]),
            Section::make(__('automations.steps_title'))->columnSpanFull()->visible($is('custom'))->schema([self::steps()]),
            Section::make(__('automations.settings'))->columnSpanFull()->columns(['md' => 2, 'xl' => 3])->visible(fn (Get $get) => $get('action') !== 'custom')->schema([
                Select::make('config.template')->label(__('automations.template'))->visible($is('send_template'))
                    ->options(fn () => MailTemplate::query()->orderBy('key')->get()->mapWithKeys(fn (MailTemplate $t) => [$t->key => $t->label()])->all()),
                CheckboxList::make('config.statuses')->label(__('automations.participant_statuses'))->visible($is('send_template'))
                    ->options(collect(['registered', 'confirmed', 'offered', 'waitlist', 'attended'])->mapWithKeys(fn ($s) => [$s => __('events.status.'.$s)])->all()),
                Select::make('config.to')->label(__('automations.to'))->options(__('automations.to_options'))->visible($is('send_template')),
                TextInput::make('config.installment')->label(__('automations.installment'))->visible($is('invoice_run')),
                Toggle::make('config.send')->label(__('automations.send'))->inline(false)->visible($is('invoice_run', 'invoice_corrections', 'membership_invoices')),
                TextInput::make('config.days')->label(__('automations.release_days'))->numeric()->minValue(0)->placeholder('14')->visible($is('payment_followup')),
                TextInput::make('config.year')->label(__('automations.year'))->numeric()->minValue(2000)->maxValue(2100)->placeholder(now()->format('Y'))->visible($is('membership_invoices')),
                TextInput::make('config.offer_days')->label(__('automations.offer_days'))->numeric()->minValue(1)->visible($is('waitlist_offer', 'group_waitlist_offer')),
                TextInput::make('config.form')->label(__('automations.form'))->visible($is('safety_sheet_reminder')),
                TextInput::make('config.interval_days')->label(__('automations.interval_days'))->numeric()->minValue(1)->visible($is('safety_sheet_reminder')),
                TextInput::make('config.max')->label(__('automations.max'))->numeric()->minValue(1)->visible($is('safety_sheet_reminder')),
                TextInput::make('config.window_days')->label(__('automations.window_days'))->numeric()->minValue(1)->visible($is('safety_sheet_reminder', 'age_notice')),
                TextInput::make('config.age')->label(__('automations.age'))->numeric()->minValue(1)->visible($is('age_notice')),
                Select::make('config.role')->label(__('automations.role'))->options(__('events.roles'))->visible($is('start_season')),
                Select::make('config.next_role')->label(__('automations.next_role'))->options(__('events.roles'))->visible($is('start_season')),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('automations.name'))->state(fn (Automation $record) => $record->label())->wrap()
                    ->description(fn (Automation $record) => $record->description),
                TextColumn::make('trigger')->label(__('automations.trigger'))->formatStateUsing(fn (string $state) => __('automations.triggers.'.$state))->toggleable(),
                TextColumn::make('mode')->label(__('automations.mode'))->badge()->formatStateUsing(fn (string $state) => __('automations.modes.'.$state))
                    ->color(fn (string $state) => $state === 'automatic' ? 'warning' : 'gray'),
                IconColumn::make('active')->label(__('automations.active'))->boolean(),
                TextColumn::make('last_run_at')->label(__('automations.last_run'))->since()->placeholder('–')->toggleable(),
            ])
            ->recordActions([
                ActionGroup::make([
                    self::dryRunAction(), self::runNowAction(), EditAction::make(),
                    DeleteAction::make()->visible(fn (Automation $record) => self::canDelete($record)),
                ]),
            ], RecordActionsPosition::BeforeColumns);
    }

    private static function steps(): Repeater
    {
        $is = fn (string ...$types) => fn (Get $get) => in_array($get('type'), $types, true);

        return Repeater::make('config.steps')->label(__('automations.steps_title'))->hiddenLabel()->minItems(1)->reorderable()->columns(['md' => 2])->addActionLabel(__('automations.add_step'))
            ->schema([
                Select::make('type')->label(__('automations.step'))->required()->live()
                    // Invoices only by people who may make them by hand, too.
                    ->options(fn () => collect(__('automations.steps'))->when(! Permissions::current('finance', 'edit'), fn ($c) => $c->except('invoice'))->all())
                    ->rule(fn () => fn (string $attribute, mixed $value, \Closure $fail) => $value === 'invoice' && ! Permissions::current('finance', 'edit') ? $fail(__('automations.invoice_rights')) : null),
                Select::make('template')->label(__('automations.template'))->visible($is('send_mail'))->required($is('send_mail'))
                    ->options(fn () => MailTemplate::query()->orderBy('key')->get()->mapWithKeys(fn (MailTemplate $t) => [$t->key => $t->label()])->all()),
                Select::make('to')->label(__('automations.to'))->visible($is('send_mail'))->options(__('automations.step_to'))->default('template'),
                Select::make('group_id')->label(__('admin.group.singular'))->visible($is('add_group', 'remove_group'))->required($is('add_group', 'remove_group'))
                    ->options(fn () => Group::query()->where('kind', 'manual')->orderBy('name')->pluck('name', 'id')->all()),
                Select::make('status')->label(__('admin.event.status'))->visible($is('set_status'))->required($is('set_status'))->options(fn () => collect(__('events.status'))->only(Custom::STATUSES)->all()),
                TextInput::make('text')->label(__('automations.task'))->visible($is('notify_team'))->required($is('notify_team'))->maxLength(250),
                TextInput::make('name')->label(__('automations.webhook_name'))->visible($is('webhook'))->required($is('webhook'))->alphaDash()->maxLength(60),
                TextInput::make('description')->label(__('finance.description'))->visible($is('invoice'))->required($is('invoice'))->maxLength(250),
                TextInput::make('amount')->label(__('finance.amount'))->visible($is('invoice'))->required($is('invoice'))->numeric()->minValue(0.01)->suffix('€'),
            ]);
    }

    public static function eventSelect(): Select
    {
        return Select::make('event_id')->label(__('automations.event'))
            ->options(fn () => Event::query()->orderByDesc('starts_at')->limit(50)->pluck('title', 'id')->all());
    }

    public static function dryRunAction(): Action
    {
        return Action::make('dry_run')->label(__('automations.dry_run'))->icon('heroicon-o-eye')->color('gray')
            ->schema(fn (Automation $record) => Engine::needsEvent($record) ? [self::eventSelect()->required()] : [])
            ->modalSubmitActionLabel(__('automations.dry_run'))
            ->action(function (Automation $record, array $data) {
                $event = isset($data['event_id']) ? Event::query()->find((int) $data['event_id']) : null;
                $plan = app(Engine::class)->preview($record, $event);
                $body = $plan === [] ? __('automations.dry_run_empty')
                    : '<ul>'.collect(array_slice($plan, 0, 30))->map(fn ($i) => '<li>'.e($i['label']).(isset($i['details']) ? ' – '.e((string) $i['details']) : '').'</li>')->implode('').'</ul>'
                        .(count($plan) > 30 ? '<p>… +'.(count($plan) - 30).'</p>' : '');
                Notification::make()->title(__('automations.dry_run_title', ['count' => count($plan)]))->body(new HtmlString($body))->info()->persistent()->send();
            });
    }

    public static function runNowAction(): Action
    {
        return Action::make('run_now')->label(__('automations.run_now'))->icon('heroicon-o-play')->requiresConfirmation()
            ->modalDescription(__('automations.run_now_hint'))
            ->visible(fn () => Permissions::current('automations', 'edit'))
            ->schema(fn (Automation $record) => Engine::needsEvent($record) ? [self::eventSelect()->required()] : [])
            ->action(function (Automation $record, array $data, $livewire) {
                /** @var User $user */
                $user = auth()->user();
                $event = isset($data['event_id']) ? Event::query()->find((int) $data['event_id']) : null;
                $run = app(Engine::class)->trigger($record, 'manual', $event, null, $user);
                if ($run === null) {
                    Notification::make()->title(__('automations.nothing'))->info()->send();

                    return;
                }
                Notification::make()->title(__('automations.started', ['status' => __('automations.statuses.'.$run->status)]))->success()->send();
                $livewire->redirect(AutomationRunResource::getUrl('view', ['record' => $run]));
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAutomations::route('/'),
            'create' => CreateAutomation::route('/create'),
            'edit' => EditAutomation::route('/{record}/edit'),
        ];
    }
}
