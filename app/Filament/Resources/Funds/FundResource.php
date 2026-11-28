<?php

namespace App\Filament\Resources\Funds;

use App\Filament\Resources\CostCenters\CostCenterResource;
use App\Filament\Resources\Funds\Pages\ManageFunds;
use App\Filament\Support\AreaAccess;
use App\Filament\Support\Areas;
use App\Filament\Support\InArea;
use App\Filament\Support\Money as MoneyInput;
use App\Models\Account;
use App\Models\Fund;
use App\Support\Accounting\Accounting;
use App\Support\Accounting\Balances;
use App\Support\Accounting\Journal;
use App\Support\Accounting\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Funds (Fonds, also called reserves or "Rücklagen"; pots of the equity, E1e.1, E1g): they collect the results of
 * their cost centres over the years and money moved between them by a decision of the board.
 */
class FundResource extends Resource
{
    use AreaAccess;
    use InArea;

    protected static ?string $model = Fund::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    public static function area(): string
    {
        return 'finance';
    }

    public static function shouldRegisterNavigation(): bool
    {
        return Accounting::funds() && Areas::current() === 'finance';
    }

    public static function getModelLabel(): string
    {
        return __('accounting.fund');
    }

    public static function getPluralModelLabel(): string
    {
        return __('accounting.funds.plural');
    }

    public static function canDelete(Model $record): bool
    {
        return parent::canDelete($record) && $record instanceof Fund && ! $record->is_default
            && app(Balances::class)->fund($record, '9999-12-31') === 0 && ! $record->costCenters()->exists();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('admin.form.name'))->required()->maxLength(120)->unique(ignoreRecord: true),
            Select::make('account_id')->label(__('accounting.funds.account'))->helperText(__('accounting.funds.account_hint'))
                ->options(fn () => Account::query()->whereNotNull('money')->orderBy('sort')->pluck('name', 'id')->all())->placeholder('–'),
            Textarea::make('note')->label(__('accounting.funds.note'))->rows(2),
            TextInput::make('sort')->label(__('accounting.sort'))->numeric()->default(0),
        ]);
    }

    public static function table(Table $table): Table
    {
        $balances = app(Balances::class);

        return $table->defaultSort('sort')->columns([
            TextColumn::make('name')->label(__('admin.form.name'))->description(fn (Fund $record) => $record->is_default ? __('accounting.funds.default') : null),
            TextColumn::make('balance')->label(__('accounting.balance_today'))->alignEnd()->state(fn (Fund $record) => Money::format($balances->fund($record, now()))),
            TextColumn::make('account.name')->label(__('accounting.funds.account'))->placeholder('–')
                ->description(fn (Fund $record) => self::difference($record)),
        ])->recordActions([
            EditAction::make(),
            Action::make('transfer')->label(__('accounting.funds.transfer'))->icon('heroicon-o-arrows-right-left')->color('gray')->visible(fn () => self::canCreate())
                ->schema(fn (Fund $record) => [
                    Select::make('to')->label(__('accounting.funds.transfer_to'))->required()->options(fn () => Fund::query()->whereKeyNot($record->id)->orderBy('name')->pluck('name', 'id')->all()),
                    MoneyInput::input('amount', __('accounting.amount'))->required(),
                    DatePicker::make('date')->label(__('accounting.date'))->required()->default(now()),
                    TextInput::make('text')->label(__('accounting.funds.decision'))->required()->maxLength(200),
                ])
                ->action(fn (Fund $record, array $data) => CostCenterResource::attempt(fn () => app(Journal::class)->post(
                    ['date' => $data['date'], 'text' => $data['text'], 'kind' => 'fund_transfer'],
                    [['account' => 'funds', 'debit' => (int) $data['amount'], 'fund' => $record->id], ['account' => 'funds', 'credit' => (int) $data['amount'], 'fund' => (int) $data['to']]],
                ))),
            DeleteAction::make(),
        ]);
    }

    /** Fund(s) on a bank or cash account compared with what is on it (E1e.1, E1g). */
    public static function difference(Fund $fund): ?string
    {
        $account = $fund->account;
        if ($account === null) {
            return null;
        }
        $balances = app(Balances::class);
        $funds = (int) Fund::query()->where('account_id', $account->id)->get()->sum(fn (Fund $f) => $balances->fund($f, now()));
        $difference = $balances->account($account, now()) - $funds;

        return $difference === 0 ? __('accounting.funds.matches') : __('accounting.funds.difference', ['amount' => Money::format($difference)]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageFunds::route('/')];
    }
}
