<?php

namespace App\Filament\Resources\Periods;

use App\Filament\Resources\CostCenters\CostCenterResource;
use App\Filament\Resources\Periods\Pages\ManagePeriods;
use App\Filament\Support\AreaAccess;
use App\Filament\Support\InArea;
use App\Filament\Support\Money as MoneyInput;
use App\Models\AllocationRule;
use App\Models\CostCenter;
use App\Models\Period;
use App\Support\Accounting\Allocation;
use App\Support\Accounting\Closing;
use App\Support\Accounting\Periods;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

/**
 * Periods of the accounting (E1d.1, E1f, E1g, E1h): dates (changeable while open – bookings move along), allocation
 * keys, closing with a preview, and reopening, which also reopens all later periods.
 */
class PeriodResource extends Resource
{
    use AreaAccess;
    use InArea;

    protected static ?string $model = Period::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    public static function area(): string
    {
        return 'finance';
    }

    public static function getModelLabel(): string
    {
        return __('accounting.period');
    }

    public static function getPluralModelLabel(): string
    {
        return __('accounting.periods.plural');
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('starts_on', 'desc')->columns([
            TextColumn::make('name')->label(__('admin.form.name')),
            TextColumn::make('starts_on')->label(__('accounting.periods.from'))->date(),
            TextColumn::make('ends_on')->label(__('accounting.periods.to'))->date(),
            TextColumn::make('status')->label(__('admin.event.status'))->badge()->formatStateUsing(fn (string $state) => __('accounting.periods.statuses.'.$state))
                ->color(fn (string $state) => $state === 'closed' ? 'gray' : 'success'),
        ])->recordActions([self::moveAction(), self::keysAction(), self::closeAction(), self::reopenAction()]);
    }

    /** First period, or the next one after the last. */
    public static function createAction(): Action
    {
        $last = fn () => Period::query()->orderByDesc('ends_on')->first();

        return Action::make('create')->label(__('accounting.periods.create'))->icon('heroicon-o-plus')->visible(fn () => self::canCreate())
            ->schema(fn () => [
                TextInput::make('name')->label(__('admin.form.name'))->required()->maxLength(80)->default(fn () => (string) (($last()?->ends_on->year ?? now()->year) + ($last() !== null ? 1 : 0))),
                DatePicker::make('starts_on')->label(__('accounting.periods.from'))->required()->default(fn () => $last()?->ends_on->copy()->addDay())->disabled(fn () => $last() !== null)->dehydrated(),
                DatePicker::make('ends_on')->label(__('accounting.periods.to'))->required()->default(fn () => $last()?->ends_on->copy()->addYear())->afterOrEqual('starts_on'),
                Text::make(fn () => $last() === null ? __('accounting.periods.first_hint') : __('accounting.periods.next_hint')),
            ])
            ->action(fn (array $data) => CostCenterResource::attempt(fn () => app(Periods::class)->create($data['name'], $data['starts_on'], $data['ends_on'])));
    }

    private static function moveAction(): Action
    {
        return Action::make('move')->label(__('accounting.periods.move'))->icon('heroicon-o-calendar')->color('gray')
            ->visible(fn (Period $record) => self::canCreate() && ! $record->isClosed())
            ->fillForm(fn (Period $record) => ['name' => $record->name, 'starts_on' => $record->starts_on, 'ends_on' => $record->ends_on])
            ->schema([
                TextInput::make('name')->label(__('admin.form.name'))->required()->maxLength(80),
                DatePicker::make('starts_on')->label(__('accounting.periods.from'))->required()->live(),
                DatePicker::make('ends_on')->label(__('accounting.periods.to'))->required()->live(),
                Text::make(fn (Period $record, $get) => filled($get('starts_on')) && filled($get('ends_on'))
                    ? trans_choice('accounting.periods.moving', $n = app(Periods::class)->movingEntries($record, Carbon::parse($get('starts_on')), Carbon::parse($get('ends_on')))->count(), ['count' => $n]) : ''),
            ])
            ->action(function (Period $record, array $data) {
                $result = CostCenterResource::attempt(fn () => app(Periods::class)->move($record, $data['starts_on'], $data['ends_on']));
                if ($result !== null) {
                    $record->update(['name' => $data['name']]); // only when the dates could be changed
                    Notification::make()->title(trans_choice('accounting.periods.moved', $result['moved'], ['count' => $result['moved']]))->success()->send();
                }
            });
    }

    /** Allocation key per cost centre that is allocated (E1d.8). */
    private static function keysAction(): Action
    {
        $sources = fn () => CostCenter::query()->where('carries', false)->orderBy('name')->get();

        return Action::make('keys')->label(__('accounting.periods.keys'))->icon('heroicon-o-scale')->color('gray')
            ->visible(fn (Period $record) => self::canCreate() && ! $record->isClosed() && $sources()->isNotEmpty())
            ->fillForm(fn (Period $record) => $sources()->mapWithKeys(fn (CostCenter $s) => ['k'.$s->id => $record->allocationRules()->where('source_cost_center_id', $s->id)->get()
                ->map(fn (AllocationRule $r) => ['target' => $r->target_cost_center_id, 'kind' => $r->kind, 'amount' => $r->kind === 'fixed' ? $r->value : null, 'percent' => $r->kind === 'percent' ? $r->value / 100 : null])->all()])->all())
            ->schema(fn () => [
                Text::make(__('accounting.periods.keys_hint')),
                ...$sources()->map(fn (CostCenter $s) => Section::make(__('accounting.periods.key_of', ['name' => $s->name]))->schema([
                    Repeater::make('k'.$s->id)->hiddenLabel()->addActionLabel(__('accounting.periods.add_target'))->columns(['md' => 3])->schema([
                        Select::make('target')->label(__('accounting.cost_center'))->required()
                            ->options(fn () => CostCenter::query()->where('carries', true)->orderBy('name')->pluck('name', 'id')->all()),
                        Select::make('kind')->label(__('accounting.periods.kind'))->required()->live()->options(__('accounting.periods.kinds')),
                        MoneyInput::input('amount', __('accounting.amount'))->visible(fn ($get) => $get('kind') === 'fixed'),
                        TextInput::make('percent')->label('%')->numeric()->minValue(0)->maxValue(100)->step('0.01')->visible(fn ($get) => $get('kind') === 'percent'),
                    ]),
                ]))->all(),
            ])
            ->action(function (Period $record, array $data) use ($sources) {
                foreach ($sources() as $source) {
                    $rules = [];
                    foreach (array_values((array) ($data['k'.$source->id] ?? [])) as $r) {
                        $rules[] = ['target' => (int) $r['target'], 'kind' => (string) $r['kind'], 'value' => match ($r['kind']) {
                            'fixed' => (int) ($r['amount'] ?? 0), 'percent' => (int) round(((float) ($r['percent'] ?? 0)) * 100), default => 0,
                        }];
                    }
                    $problems = Allocation::validate($rules);
                    if ($problems !== []) {
                        Notification::make()->title($source->name.': '.implode(' ', array_map(fn (string $p) => __('accounting.'.$p), $problems)))->danger()->persistent()->send();

                        continue;
                    }
                    AllocationRule::query()->where('period_id', $record->id)->where('source_cost_center_id', $source->id)->delete();
                    foreach ($rules as $rule) {
                        AllocationRule::query()->create(['period_id' => $record->id, 'source_cost_center_id' => $source->id, 'target_cost_center_id' => $rule['target'], 'kind' => $rule['kind'], 'value' => $rule['value']]);
                    }
                }
            });
    }

    private static function closeAction(): Action
    {
        return Action::make('close')->label(__('accounting.periods.close'))->icon('heroicon-o-lock-closed')->color('warning')
            ->visible(fn (Period $record) => self::canCreate() && ! $record->isClosed())
            ->modalHeading(fn (Period $record) => __('accounting.periods.close_heading', ['name' => $record->name]))
            ->modalWidth('5xl')
            ->modalContent(fn (Period $record) => view('filament.accounting.closing-preview', ['preview' => app(Closing::class)->preview($record)]))
            ->modalSubmitActionLabel(__('accounting.periods.close_confirm'))
            ->action(fn (Period $record) => CostCenterResource::attempt(fn () => app(Closing::class)->close($record)) !== null
                ? Notification::make()->title(__('accounting.periods.closed', ['name' => $record->name]))->success()->send() : null);
    }

    private static function reopenAction(): Action
    {
        return Action::make('reopen')->label(__('accounting.periods.reopen'))->icon('heroicon-o-lock-open')->color('danger')
            ->visible(fn (Period $record) => self::canCreate() && $record->isClosed())
            ->schema(fn (Period $record) => [
                Text::make(new HtmlString(e(__('accounting.periods.reopen_list')).'<ul class="list-disc ps-5">'.app(Periods::class)->reopening($record)->map(fn (Period $p) => '<li>'.e($p->name).'</li>')->implode('').'</ul>'.e(__('accounting.periods.reopen_hint')))),
                Textarea::make('reason')->label(__('accounting.periods.reason'))->required()->maxLength(500),
            ])
            ->action(fn (Period $record, array $data) => app(Periods::class)->reopen($record, $data['reason']));
    }

    public static function getPages(): array
    {
        return ['index' => ManagePeriods::route('/')];
    }
}
