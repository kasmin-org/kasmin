<?php

namespace App\Filament\Resources\Advances;

use App\Filament\Resources\Advances\Pages\ManageAdvances;
use App\Filament\Resources\CostCenters\CostCenterResource;
use App\Filament\Support\AreaAccess;
use App\Filament\Support\InArea;
use App\Filament\Support\Money as MoneyInput;
use App\Models\Account;
use App\Models\Advance;
use App\Models\User;
use App\Support\Accounting\Advances;
use App\Support\Accounting\ExpenseClaims;
use App\Support\Accounting\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Advances (E1d.3): cash or a transfer handed to a person before (e.g. for the shopping of a camp), settled with the
 * receipts the person submits against it; the rest comes back or becomes an expense claim to pay out.
 */
class AdvanceResource extends Resource
{
    use AreaAccess;
    use InArea;

    protected static ?string $model = Advance::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHandRaised;

    public static function area(): string
    {
        return 'finance';
    }

    public static function getModelLabel(): string
    {
        return __('accounting.expenses.advance');
    }

    public static function getPluralModelLabel(): string
    {
        return __('accounting.expenses.advances');
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('paid_on', 'desc')->modifyQueryUsing(fn (Builder $query) => $query->with('person', 'account'))->columns([
            TextColumn::make('paid_on')->label(__('accounting.date'))->date()->sortable(),
            TextColumn::make('person.last_name')->label(__('accounting.expenses.admin.person'))->formatStateUsing(fn (Advance $record) => $record->person->fullName())
                ->searchable(['first_name', 'last_name'])->description(fn (Advance $record) => $record->purpose),
            TextColumn::make('amount_cents')->label(__('accounting.expenses.admin.amount'))->alignEnd()->formatStateUsing(fn (Advance $record) => Money::format($record->amount_cents))
                ->description(fn (Advance $record) => $record->account?->name),
            TextColumn::make('receipts')->label(__('accounting.expenses.admin.receipts'))->alignEnd()
                ->state(fn (Advance $record) => Money::format((int) $record->claims()->where('advance_rest', false)->where('status', 'settled')->sum('amount_cents'))),
            TextColumn::make('open')->label(__('accounting.expenses.admin.open'))->alignEnd()->state(fn (Advance $record) => Money::format($record->openCents())),
            TextColumn::make('settled_on')->label(__('accounting.expenses.admin.settled_on'))->date()->placeholder('–')
                ->description(fn (Advance $record) => $record->returned_cents > 0 ? __('accounting.expenses.admin.returned').' '.Money::format($record->returned_cents) : null),
        ])->filters([
            TernaryFilter::make('settled')->label(__('accounting.expenses.admin.settled_on'))->nullable()->attribute('settled_on')->default(false),
        ])->recordActions([
            Action::make('settle')->label(__('accounting.expenses.admin.settle'))->icon('heroicon-o-check-badge')->color('success')
                ->visible(fn (Advance $record) => self::canCreate() && ! $record->isSettled())
                ->modalDescription(fn (Advance $record) => __('accounting.expenses.admin.settle_hint', ['amount' => Money::format($record->openCents())]))
                ->fillForm(fn (Advance $record) => ['returned' => max(0, $record->openCents()), 'account_id' => $record->account?->money === 'cash' ? $record->account_id : ExpenseClaims::moneyAccounts()->firstWhere('money', 'cash')?->id])
                ->schema([
                    MoneyInput::input('returned', __('accounting.expenses.admin.returned'))->required(),
                    Select::make('account_id')->label(__('accounting.expenses.admin.into'))->options(fn () => self::moneyAccounts()),
                    DatePicker::make('date')->label(__('accounting.date'))->required()->default(now()),
                ])
                ->action(function (Advance $record, array $data) {
                    $into = Account::query()->find((int) ($data['account_id'] ?? 0));
                    if (CostCenterResource::attempt(fn () => app(Advances::class)->settle($record, (int) $data['returned'], $into, (string) $data['date'], self::user())) !== null) {
                        Notification::make()->title(__('accounting.expenses.admin.settled'))->success()->send();
                    }
                }),
        ]);
    }

    /** @return array<int, string> */
    public static function moneyAccounts(): array
    {
        return ExpenseClaims::moneyAccounts()->mapWithKeys(fn (Account $a) => [$a->id => $a->label()])->all();
    }

    public static function user(): User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : abort(403);
    }

    public static function getPages(): array
    {
        return ['index' => ManageAdvances::route('/')];
    }
}
