<?php

namespace App\Filament\Resources\Automations;

use App\Filament\Resources\AutomationRuns\AutomationRunResource;
use App\Filament\Resources\Automations\Pages\EditAutomation;
use App\Filament\Resources\Automations\Pages\ListAutomations;
use App\Filament\Support\AreaAccess;
use App\Models\Automation;
use App\Models\Event;
use App\Models\EventSeries;
use App\Models\MailTemplate;
use App\Models\Role;
use App\Models\User;
use App\Support\Auth\Permissions;
use App\Support\Automations\Engine;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
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

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        $action = fn (Get $get) => (string) $get('action');
        $is = fn (string ...$actions) => fn (Get $get) => in_array($action($get), $actions, true);

        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('name.de')->label(__('automations.name').' (DE)')->required(),
                TextInput::make('name.en')->label(__('automations.name').' (EN)')->required(),
                Select::make('action')->label(__('automations.action'))->options(__('automations.actions'))->disabled()->dehydrated(false),
                Toggle::make('active')->label(__('automations.active'))->inline(false),
                Select::make('mode')->label(__('automations.mode'))->options(__('automations.modes'))->required()
                    ->hintIcon('heroicon-m-question-mark-circle', tooltip: __('automations.mode_hint')),
                TextInput::make('max_items')->label(__('automations.max_items'))->numeric()->minValue(1)->required()
                    ->hintIcon('heroicon-m-question-mark-circle', tooltip: __('automations.max_items_hint')),
                CheckboxList::make('approver_roles')->label(__('automations.approver_roles'))->columns(3)->columnSpanFull()
                    ->options(fn () => Role::query()->where('is_admin', true)->get()->mapWithKeys(fn (Role $r) => [$r->key => $r->name[app()->getLocale()] ?? $r->key])->all())
                    ->hintIcon('heroicon-m-question-mark-circle', tooltip: __('automations.approver_roles_hint')),
            ]),
            Section::make(__('automations.trigger'))->columns(3)->schema([
                Select::make('trigger')->label(__('automations.trigger'))->options(__('automations.triggers'))->required()->live(),
                TextInput::make('trigger_config.time')->label(__('automations.time'))->regex('/^\d{2}:\d{2}$/')->placeholder('08:00')
                    ->visible(fn (Get $get) => $get('trigger') === 'daily'),
                TextInput::make('trigger_config.days')->label(__('automations.days'))->numeric()->minValue(0)
                    ->visible(fn (Get $get) => in_array($get('trigger'), ['before_event', 'after_event'], true)),
                Select::make('trigger_config.series')->label(__('automations.series'))->options(fn () => EventSeries::query()->pluck('name', 'id')->all())
                    ->visible(fn (Get $get) => in_array($get('trigger'), ['before_event', 'after_event'], true)),
            ]),
            Section::make(__('automations.settings'))->columns(3)->schema([
                Select::make('config.template')->label(__('automations.template'))->visible($is('send_template'))
                    ->options(fn () => MailTemplate::query()->orderBy('key')->get()->mapWithKeys(fn (MailTemplate $t) => [$t->key => $t->name[app()->getLocale()] ?? $t->key])->all()),
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
                    ->description(fn (Automation $record) => $record->description[app()->getLocale()] ?? null),
                TextColumn::make('trigger')->label(__('automations.trigger'))->formatStateUsing(fn (string $state) => __('automations.triggers.'.$state))->toggleable(),
                TextColumn::make('mode')->label(__('automations.mode'))->badge()->formatStateUsing(fn (string $state) => __('automations.modes.'.$state))
                    ->color(fn (string $state) => $state === 'automatic' ? 'warning' : 'gray'),
                IconColumn::make('active')->label(__('automations.active'))->boolean(),
                TextColumn::make('last_run_at')->label(__('automations.last_run'))->since()->placeholder('–')->toggleable(),
            ])
            ->recordActions([
                ActionGroup::make([self::dryRunAction(), self::runNowAction(), EditAction::make()]),
            ], RecordActionsPosition::BeforeColumns);
    }

    public static function eventSelect(): Select
    {
        return Select::make('event_id')->label(__('automations.event'))
            ->options(fn () => Event::query()->orderByDesc('starts_at')->limit(50)->pluck('title', 'id')->all());
    }

    public static function dryRunAction(): Action
    {
        return Action::make('dry_run')->label(__('automations.dry_run'))->icon('heroicon-o-eye')->color('gray')
            ->schema(fn (Automation $record) => in_array($record->action, Engine::NEEDS_EVENT, true) ? [self::eventSelect()->required()] : [])
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
            ->schema(fn (Automation $record) => in_array($record->action, Engine::NEEDS_EVENT, true) ? [self::eventSelect()->required()] : [])
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
            'edit' => EditAutomation::route('/{record}/edit'),
        ];
    }
}
