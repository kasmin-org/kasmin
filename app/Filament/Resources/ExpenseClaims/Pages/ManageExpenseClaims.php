<?php

namespace App\Filament\Resources\ExpenseClaims\Pages;

use App\Filament\Resources\CostCenters\CostCenterResource;
use App\Filament\Resources\ExpenseClaims\ExpenseClaimResource;
use App\Filament\Support\ListTools;
use App\Filament\Support\Money as MoneyInput;
use App\Http\Controllers\PortalExpensesController;
use App\Models\Account;
use App\Models\Advance;
use App\Models\CostCenter;
use App\Models\Person;
use App\Models\User;
use App\Support\Accounting\Accounting;
use App\Support\Accounting\ExpenseClaims;
use App\Support\Accounting\Money;
use App\Support\Finance\Iban;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\DB;

class ManageExpenseClaims extends ManageRecords
{
    use ListTools;

    protected static string $resource = ExpenseClaimResource::class;

    protected function getHeaderActions(): array
    {
        return [...$this->listTools(), $this->enterAction()];
    }

    /** A claim entered centrally for a person (E1d.3): the receipt is optional, approval and payout follow as usual. */
    private function enterAction(): Action
    {
        $flat = fn (Get $get) => filled($get('flat_rate'));

        return Action::make('enter')->label(__('accounting.expenses.admin.enter'))->icon('heroicon-o-plus')->visible(fn () => ExpenseClaimResource::canCreate())
            ->modalDescription(__('accounting.expenses.admin.enter_hint'))->modalWidth('3xl')
            ->schema([
                Select::make('person_id')->label(__('accounting.expenses.admin.person'))->searchable()->required()->live()
                    ->getSearchResultsUsing(fn (string $search) => Person::query()->whereNull('anonymised_at')->where(fn ($q) => $q->where('last_name', 'like', "%{$search}%")->orWhere('first_name', 'like', "%{$search}%"))->limit(30)->get()->mapWithKeys(fn (Person $p) => [$p->id => $p->fullName()])->all())
                    ->getOptionLabelUsing(fn ($value) => Person::query()->whereKey((int) $value)->first()?->fullName()),
                DatePicker::make('date')->label(__('accounting.expenses.admin.date'))->required()->default(now())->maxDate(now()),
                Select::make('flat_rate')->label(__('accounting.expenses.admin.flat_rate'))->live()->visible(fn () => ExpenseClaims::flatRates() !== [])
                    ->options(fn () => collect(ExpenseClaims::flatRates())->mapWithKeys(fn (array $r) => [$r['name'] => $r['name'].' ('.Money::format($r['cents']).')'])->all()),
                TextInput::make('quantity')->label(__('accounting.expenses.admin.quantity'))->numeric()->minValue(0.01)->default(1)->visible($flat),
                MoneyInput::input('amount_cents', __('accounting.expenses.admin.amount'))->required(fn (Get $get) => ! $flat($get))->visible(fn (Get $get) => ! $flat($get)),
                TextInput::make('purpose')->label(__('accounting.expenses.admin.purpose'))->maxLength(250)->required(fn (Get $get) => ! $flat($get)),
                Select::make('account_id')->label(__('accounting.expenses.admin.account'))->default(fn () => ExpenseClaims::defaultAccount()?->id)
                    ->options(fn () => ExpenseClaims::expenseAccounts()->mapWithKeys(fn (Account $a) => [$a->id => $a->label()])->all()),
                Select::make('event_id')->label(__('accounting.expenses.admin.event'))->searchable()->options(fn () => PortalExpensesController::events()->all()),
                Select::make('cost_center_id')->label(__('accounting.expenses.admin.cost_center'))->visible(fn () => Accounting::costCenters())
                    ->options(fn () => CostCenter::query()->orderBy('sort')->orderBy('name')->pluck('name', 'id')->all()),
                Select::make('advance_id')->label(__('accounting.expenses.admin.advance'))
                    ->visible(fn (Get $get) => Advance::query()->where('person_id', (int) $get('person_id'))->whereNull('settled_on')->exists())
                    ->options(fn (Get $get) => Advance::query()->where('person_id', (int) $get('person_id'))->whereNull('settled_on')->get()
                        ->mapWithKeys(fn (Advance $a) => [$a->id => $a->paid_on->isoFormat('L').' · '.Money::format($a->amount_cents).($a->purpose ? ' · '.$a->purpose : '')])->all()),
                FileUpload::make('receipt')->label(__('accounting.expenses.admin.receipt'))->disk('local')->directory(ExpenseClaims::RECEIPTS)->visibility('private')
                    ->acceptedFileTypes(ExpenseClaims::RECEIPT_TYPES)->maxSize(ExpenseClaims::RECEIPT_MAX_KB),
                Toggle::make('waived')->label(__('accounting.expenses.admin.waived')),
                TextInput::make('iban')->label(__('accounting.expenses.admin.iban'))->maxLength(42)->helperText(__('accounting.expenses.admin.iban_keep'))
                    ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail) {
                        if (filled($value) && ! Iban::valid((string) $value)) {
                            $fail(__('accounting.errors.iban'));
                        }
                    }),
            ])
            ->action(function (array $data) {
                $person = Person::query()->whereNull('anonymised_at')->findOrFail((int) $data['person_id']);
                $user = auth()->user();
                CostCenterResource::attempt(fn () => DB::transaction(function () use ($person, $data, $user) {
                    if (filled($data['iban'] ?? null)) {
                        app(ExpenseClaims::class)->setIban($person, (string) $data['iban']);
                    }

                    return app(ExpenseClaims::class)->submit($person, [
                        'date' => (string) $data['date'], 'amount_cents' => isset($data['amount_cents']) ? (int) $data['amount_cents'] : null, 'purpose' => $data['purpose'] ?? null,
                        'flat_rate' => $data['flat_rate'] ?? null, 'quantity' => $data['quantity'] ?? null, 'account_id' => $data['account_id'] ?? null,
                        'event_id' => $data['event_id'] ?? null, 'cost_center_id' => $data['cost_center_id'] ?? null, 'advance_id' => $data['advance_id'] ?? null,
                        'waived' => (bool) ($data['waived'] ?? false), 'receipt' => is_string($data['receipt'] ?? null) ? $data['receipt'] : null,
                    ], $user instanceof User ? $user : null);
                }));
            });
    }
}
