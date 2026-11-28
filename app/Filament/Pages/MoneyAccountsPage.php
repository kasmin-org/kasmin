<?php

namespace App\Filament\Pages;

use App\Filament\Support\InArea;
use App\Models\Account;
use App\Models\Fund;
use App\Support\Accounting\Accounting;
use App\Support\Accounting\Balances;
use App\Support\Accounting\Money;
use App\Support\Auth\Permissions;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Bank and cash accounts (E1d, E1f.5): where the money is, with the balance, a check against the statement of the
 * bank, and – with funds – the funds assigned to the account (E1e.1, E1g).
 */
class MoneyAccountsPage extends Page
{
    use InArea;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static ?string $slug = 'accounting/money';

    protected string $view = 'filament.accounting.money';

    /** @var array<int, string|null> statement balance typed in per account, to compare */
    public array $statement = [];

    public static function getNavigationLabel(): string
    {
        return __('accounting.money_accounts');
    }

    public function getTitle(): string
    {
        return __('accounting.money_accounts');
    }

    public static function canAccess(): bool
    {
        return Permissions::current('finance');
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $balances = app(Balances::class);
        $rows = [];
        foreach (Account::query()->whereNotNull('money')->where('active', true)->orderBy('sort')->get() as $account) {
            $balance = $balances->account($account, now());
            $funds = Accounting::funds() ? Fund::query()->where('account_id', $account->id)->get()->map(fn (Fund $f) => ['name' => $f->name, 'balance' => $balances->fund($f, now())])->all() : [];
            $typed = $this->statement[$account->id] ?? null;
            $statement = $typed !== null && $typed !== '' ? (int) round(((float) str_replace(',', '.', $typed)) * 100) : null;
            $rows[] = ['account' => $account, 'balance' => Money::format($balance), 'difference' => $statement !== null ? Money::format($statement - $balance) : null, 'matches' => $statement === $balance,
                'funds' => $funds, 'funds_difference' => $funds !== [] ? Money::format($balance - array_sum(array_column($funds, 'balance'))) : null, 'money' => fn (int $c) => Money::format($c)];
        }

        return ['rows' => $rows];
    }
}
