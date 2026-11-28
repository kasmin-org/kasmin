<?php

namespace App\Filament\Resources\Advances\Pages;

use App\Filament\Resources\Advances\AdvanceResource;
use App\Filament\Resources\CostCenters\CostCenterResource;
use App\Filament\Support\ListTools;
use App\Filament\Support\Money as MoneyInput;
use App\Models\Account;
use App\Models\Person;
use App\Support\Accounting\Advances;
use App\Support\Accounting\ExpenseClaims;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ManageRecords;

class ManageAdvances extends ManageRecords
{
    use ListTools;

    protected static string $resource = AdvanceResource::class;

    protected function getHeaderActions(): array
    {
        return [...$this->listTools(),
            Action::make('hand_out')->label(__('accounting.expenses.admin.hand_out'))->icon('heroicon-o-plus')->visible(fn () => AdvanceResource::canCreate())
                ->schema([
                    Select::make('person_id')->label(__('accounting.expenses.admin.person'))->searchable()->required()
                        ->getSearchResultsUsing(fn (string $search) => Person::query()->whereNull('anonymised_at')->where(fn ($q) => $q->where('last_name', 'like', "%{$search}%")->orWhere('first_name', 'like', "%{$search}%"))->limit(30)->get()->mapWithKeys(fn (Person $p) => [$p->id => $p->fullName()])->all())
                        ->getOptionLabelUsing(fn ($value) => Person::query()->whereKey((int) $value)->first()?->fullName()),
                    MoneyInput::input('amount_cents', __('accounting.expenses.admin.amount'))->required(),
                    Select::make('account_id')->label(__('accounting.expenses.admin.from'))->required()->options(fn () => AdvanceResource::moneyAccounts())
                        ->default(fn () => ExpenseClaims::moneyAccounts()->firstWhere('money', 'cash')?->id),
                    DatePicker::make('paid_on')->label(__('accounting.date'))->required()->default(now()),
                    TextInput::make('purpose')->label(__('accounting.expenses.admin.purpose'))->maxLength(250),
                ])
                ->action(function (array $data) {
                    $person = Person::query()->whereNull('anonymised_at')->findOrFail((int) $data['person_id']);
                    $from = Account::query()->findOrFail((int) $data['account_id']);
                    CostCenterResource::attempt(fn () => app(Advances::class)->handOut($person, (int) $data['amount_cents'], $from, (string) $data['paid_on'], $data['purpose'] ?? null, AdvanceResource::user()));
                }),
        ];
    }
}
