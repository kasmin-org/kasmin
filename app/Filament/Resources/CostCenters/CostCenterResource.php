<?php

namespace App\Filament\Resources\CostCenters;

use App\Filament\Resources\CostCenters\Pages\ManageCostCenters;
use App\Filament\Support\AreaAccess;
use App\Filament\Support\Areas;
use App\Filament\Support\InArea;
use App\Models\CostCenter;
use App\Models\Fund;
use App\Models\Period;
use App\Support\Accounting\Accounting;
use App\Support\Accounting\AccountingException;
use App\Support\Accounting\CostCenters;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Cost centres (E1a, E1e, E1f, E1j): what a booking was for. One over all years, active or not per period; carries its
 * result into a fund or is allocated to others.
 */
class CostCenterResource extends Resource
{
    use AreaAccess;
    use InArea;

    protected static ?string $model = CostCenter::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    public static function area(): string
    {
        return 'finance';
    }

    public static function shouldRegisterNavigation(): bool
    {
        return Accounting::costCenters() && Areas::current() === 'finance';
    }

    public static function getModelLabel(): string
    {
        return __('accounting.cost_center');
    }

    public static function getPluralModelLabel(): string
    {
        return __('accounting.cost_centers.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('admin.form.name'))->required()->maxLength(120)->unique(ignoreRecord: true),
            Radio::make('carries')->label(__('accounting.cost_centers.kind'))->boolean(__('accounting.cost_centers.carries'), __('accounting.cost_centers.allocated'))
                ->default(true)->live()->helperText(__('accounting.cost_centers.kind_hint'))->disabledOn('edit'),
            Select::make('fund_id')->label(__('accounting.fund'))->options(fn () => Fund::query()->orderByDesc('is_default')->orderBy('name')->pluck('name', 'id')->all())
                ->visible(fn ($get) => Accounting::funds() && (bool) $get('carries'))->placeholder(__('accounting.funds.default')),
            TextInput::make('sort')->label(__('accounting.sort'))->numeric()->default(0),
        ]);
    }

    public static function table(Table $table): Table
    {
        $period = fn () => Period::query()->where('status', 'open')->orderBy('starts_on')->first();

        return $table->defaultSort('sort')->columns([
            TextColumn::make('name')->label(__('admin.form.name'))->searchable()->description(fn (CostCenter $record) => $record->is_default ? __('accounting.cost_centers.standard') : null),
            TextColumn::make('carries')->label(__('accounting.cost_centers.kind'))->badge()
                ->formatStateUsing(fn (bool $state) => $state ? __('accounting.cost_centers.carries') : __('accounting.cost_centers.allocated')),
            TextColumn::make('fund.name')->label(__('accounting.fund'))->placeholder('–')->visible(fn () => Accounting::funds()),
            IconColumn::make('active_now')->label(__('accounting.cost_centers.active_in', ['period' => ($period()->name ?? '–')]))->boolean()
                ->state(fn (CostCenter $record) => ($p = $period()) !== null && $p->costCenters()->whereKey($record->id)->exists()),
        ])->recordActions([
            EditAction::make(),
            Action::make('kind')->label(__('accounting.cost_centers.change_kind'))->icon('heroicon-o-arrows-right-left')->color('gray')
                ->visible(fn () => self::canCreate())
                ->schema(fn (CostCenter $record) => [
                    Text::make($record->carries ? __('accounting.cost_centers.to_allocated_hint') : __('accounting.cost_centers.to_carries_hint')),
                    Select::make('fund_id')->label(__('accounting.fund'))->visible(! $record->carries && Accounting::funds())
                        ->options(fn () => Fund::query()->orderByDesc('is_default')->orderBy('name')->pluck('name', 'id')->all()),
                ])
                ->action(fn (CostCenter $record, array $data) => self::attempt(fn () => app(CostCenters::class)->setCarries($record, ! $record->carries, isset($data['fund_id']) ? Fund::query()->whereKey((int) $data['fund_id'])->first() : null))),
            Action::make('active')->label(__('accounting.cost_centers.active_periods'))->icon('heroicon-o-calendar')->color('gray')
                ->visible(fn () => self::canCreate())
                ->schema(fn (CostCenter $record) => Period::query()->where('status', 'open')->orderBy('starts_on')->get()
                    ->map(fn (Period $p) => Toggle::make('p'.$p->id)->label($p->name)->default($p->costCenters()->whereKey($record->id)->exists())->disabled($record->is_default))->all())
                ->action(function (CostCenter $record, array $data) {
                    $warnings = [];
                    foreach (Period::query()->where('status', 'open')->get() as $p) {
                        if (array_key_exists('p'.$p->id, $data)) {
                            $warnings = [...$warnings, ...self::attempt(fn () => app(CostCenters::class)->setActive($record, $p, (bool) $data['p'.$p->id])) ?? []];
                        }
                    }
                    foreach ($warnings as $warning) {
                        Notification::make()->title($warning)->warning()->send();
                    }
                }),
            Action::make('default')->label(__('accounting.cost_centers.make_default'))->icon('heroicon-o-star')->color('gray')->requiresConfirmation()
                ->visible(fn (CostCenter $record) => self::canCreate() && ! $record->is_default)
                ->action(fn (CostCenter $record) => app(CostCenters::class)->makeDefault($record)),
            self::deleteAction(),
        ]);
    }

    private static function deleteAction(): Action
    {
        return Action::make('delete')->label(__('accounting.cost_centers.delete'))->icon('heroicon-o-trash')->color('danger')
            ->visible(fn (CostCenter $record) => self::canCreate() && ! $record->is_default)
            ->modalDescription(fn (CostCenter $record) => implode(' ', app(CostCenters::class)->whyNotDelete($record)) ?: null)
            ->schema(function (CostCenter $record) {
                $info = app(CostCenters::class)->deletable($record);
                if ($info['count'] === 0) {
                    return [Text::make(__('accounting.cost_centers.no_bookings'))];
                }

                return [
                    Text::make(trans_choice('accounting.cost_centers.has_bookings', $info['count'], ['count' => $info['count']])),
                    Radio::make('mode')->label(__('accounting.cost_centers.what_bookings'))->required()->live()->default('move')
                        ->options(['move' => __('accounting.cost_centers.move'), 'delete' => __('accounting.cost_centers.delete_bookings')])
                        ->disableOptionWhen(fn (string $value) => $value === 'delete' && ! $info['manual_only'])
                        ->helperText($info['manual_only'] ? null : (string) __('accounting.cost_centers.only_manual')),
                    Select::make('target')->label(__('accounting.cost_centers.move_to'))->visible(fn ($get) => $get('mode') === 'move')->required(fn ($get) => $get('mode') === 'move')
                        ->options(fn () => CostCenter::query()->whereKeyNot($record->id)->orderBy('name')->pluck('name', 'id')->all()),
                    TextInput::make('typed')->label(__('accounting.cost_centers.type_sentence', ['sentence' => CostCenters::confirmation($info['count'])]))
                        ->visible(fn ($get) => $get('mode') === 'delete')->required(fn ($get) => $get('mode') === 'delete'),
                ];
            })
            ->action(function (CostCenter $record, array $data) {
                $target = ($data['mode'] ?? 'move') === 'move' && isset($data['target']) ? CostCenter::query()->whereKey((int) $data['target'])->first() : null;
                self::attempt(fn () => app(CostCenters::class)->delete($record, $target, ($data['mode'] ?? null) === 'delete', $data['typed'] ?? null));
            });
    }

    /**
     * Runs a change of the accounting and shows a refusal as a message instead of an error page.
     *
     * @template T
     *
     * @param  callable(): T  $change
     * @return T|null
     */
    public static function attempt(callable $change): mixed
    {
        try {
            return $change();
        } catch (AccountingException $e) {
            Notification::make()->title($e->getMessage())->danger()->persistent()->send();

            return null;
        }
    }

    public static function getPages(): array
    {
        return ['index' => ManageCostCenters::route('/')];
    }
}
