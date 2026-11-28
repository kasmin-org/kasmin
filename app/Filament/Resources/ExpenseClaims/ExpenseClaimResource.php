<?php

namespace App\Filament\Resources\ExpenseClaims;

use App\Filament\Resources\CostCenters\CostCenterResource;
use App\Filament\Resources\ExpenseClaims\Pages\ManageExpenseClaims;
use App\Filament\Support\AreaAccess;
use App\Filament\Support\InArea;
use App\Models\Account;
use App\Models\CostCenter;
use App\Models\ExpenseClaim;
use App\Models\User;
use App\Support\Accounting\Accounting;
use App\Support\Accounting\ExpenseClaims;
use App\Support\Finance\Iban;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Expense claims (E1d.3): check the receipt, approve or reject, pay out one or many (SEPA file or payment code, or in
 * cash), book a waived refund as a donation; enter a claim for a person who does not use the portal.
 */
class ExpenseClaimResource extends Resource
{
    use AreaAccess;
    use InArea;

    protected static ?string $model = ExpenseClaim::class;

    protected static ?string $slug = 'expense-claims';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptRefund;

    public static function area(): string
    {
        return 'finance';
    }

    public static function getModelLabel(): string
    {
        return __('accounting.expenses.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('accounting.expenses.plural');
    }

    public static function getNavigationBadge(): ?string
    {
        $open = ExpenseClaim::query()->whereIn('status', ExpenseClaim::OPEN)->count();

        return $open > 0 ? (string) $open : null;
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
        return $table->defaultSort('date', 'desc')->modifyQueryUsing(fn (Builder $query) => $query->with('person', 'account', 'costCenter', 'event', 'submittedBy'))->columns([
            TextColumn::make('date')->label(__('accounting.expenses.admin.date'))->date()->sortable(),
            TextColumn::make('person.last_name')->label(__('accounting.expenses.admin.person'))->formatStateUsing(fn (ExpenseClaim $record) => $record->person->fullName())
                ->searchable(['first_name', 'last_name'])->description(fn (ExpenseClaim $record) => $record->enteredCentrally() ? __('accounting.expenses.admin.centrally') : null),
            TextColumn::make('purpose')->label(__('accounting.expenses.admin.purpose'))->searchable()->wrap()
                ->description(fn (ExpenseClaim $record) => collect([$record->account?->name, $record->event?->title, Accounting::costCenters() ? $record->costCenter?->name : null])->filter()->implode(' · ')),
            TextColumn::make('amount_cents')->label(__('accounting.expenses.admin.amount'))->alignEnd()->sortable()->formatStateUsing(fn (ExpenseClaim $record) => $record->amount()),
            TextColumn::make('status')->label(__('accounting.expenses.admin.status'))->badge()->formatStateUsing(fn (string $state) => __('accounting.expenses.status.'.$state))
                ->color(fn (string $state) => match ($state) {
                    'submitted' => 'warning', 'approved' => 'info', 'rejected' => 'danger', default => 'success',
                })->description(fn (ExpenseClaim $record) => $record->waived && $record->status === 'submitted' ? __('accounting.expenses.admin.waived') : $record->paid_on?->isoFormat('L')),
        ])->filters([
            SelectFilter::make('status')->label(__('accounting.expenses.admin.status'))->multiple()->options(__('accounting.expenses.status'))->default(ExpenseClaim::OPEN),
            SelectFilter::make('event_id')->label(__('accounting.expenses.admin.event'))->relationship('event', 'title'),
        ])->recordActions([
            ActionGroup::make([
                Action::make('receipt')->label(__('accounting.expenses.admin.receipt'))->icon('heroicon-o-paper-clip')->color('gray')
                    ->visible(fn (ExpenseClaim $record) => $record->receipt_path !== null)
                    ->url(fn (ExpenseClaim $record) => route('admin.expense-claims.receipt', $record->id))->openUrlInNewTab(),
                self::approveAction(),
                Action::make('reject')->label(__('accounting.expenses.admin.reject'))->icon('heroicon-o-x-mark')->color('danger')
                    ->visible(fn (ExpenseClaim $record) => self::canCreate() && $record->status === 'submitted')
                    ->schema([Textarea::make('reason')->label(__('accounting.expenses.admin.reason'))->required()->maxLength(1000)])
                    ->action(fn (ExpenseClaim $record, array $data) => CostCenterResource::attempt(fn () => app(ExpenseClaims::class)->reject($record, self::user(), (string) $data['reason']))),
                Action::make('qr')->label(__('accounting.expenses.admin.qr'))->icon('heroicon-o-qr-code')->color('gray')
                    ->visible(fn (ExpenseClaim $record) => self::canCreate() && $record->status === 'approved' && Iban::valid((string) $record->person->payout_iban))
                    ->modalSubmitAction(false)->modalContent(fn (ExpenseClaim $record) => self::qr($record)),
                self::payAction(Action::make('pay')->label(__('accounting.expenses.admin.pay'))->visible(fn (ExpenseClaim $record) => self::canCreate() && $record->status === 'approved'), false)
                    ->action(fn (ExpenseClaim $record, array $data) => self::pay([$record], $data)),
                Action::make('donate')->label(__('accounting.expenses.admin.donate'))->icon('heroicon-o-heart')->color('gray')->requiresConfirmation()
                    ->modalDescription(__('accounting.expenses.admin.donate_hint'))
                    ->visible(fn (ExpenseClaim $record) => self::canCreate() && $record->status === 'approved')
                    ->action(fn (ExpenseClaim $record) => CostCenterResource::attempt(fn () => app(ExpenseClaims::class)->donate($record, self::user()))),
            ]),
        ], RecordActionsPosition::BeforeColumns)->toolbarActions([
            self::payAction(BulkAction::make('pay_selected')->label(__('accounting.expenses.admin.pay_selected'))->visible(fn () => self::canCreate()), true)
                ->action(function (Collection $records, array $data) {
                    $claims = [];
                    foreach ($records as $record) {
                        if ($record instanceof ExpenseClaim && $record->status === 'approved') {
                            $claims[] = $record; // the others are skipped, not an error
                        }
                    }

                    return self::pay($claims, $data);
                }),
        ]);
    }

    private static function approveAction(): Action
    {
        return Action::make('approve')->label(__('accounting.expenses.admin.approve'))->icon('heroicon-o-check')->color('success')
            ->visible(fn (ExpenseClaim $record) => self::canCreate() && $record->status === 'submitted')
            ->modalDescription(__('accounting.expenses.admin.approve_hint'))
            ->fillForm(fn (ExpenseClaim $record) => ['account_id' => $record->account_id ?? ExpenseClaims::defaultAccount()?->id, 'cost_center_id' => $record->cost_center_id])
            ->schema([
                Select::make('account_id')->label(__('accounting.expenses.admin.account'))->required()
                    ->options(fn () => ExpenseClaims::expenseAccounts()->mapWithKeys(fn (Account $a) => [$a->id => $a->label()])->all()),
                Select::make('cost_center_id')->label(__('accounting.expenses.admin.cost_center'))->visible(fn () => Accounting::costCenters())
                    ->options(fn () => CostCenter::query()->orderBy('sort')->orderBy('name')->pluck('name', 'id')->all()),
            ])
            ->action(fn (ExpenseClaim $record, array $data) => CostCenterResource::attempt(fn () => app(ExpenseClaims::class)->approve($record, self::user(), $data['account_id'] ?? null, $data['cost_center_id'] ?? null)));
    }

    /**
     * The form of a payout: from which bank or cash account, on which day; from a bank account a SEPA file.
     *
     * @template T of Action
     *
     * @param  T  $action
     * @return T
     */
    private static function payAction(Action $action, bool $many): Action
    {
        $isBank = fn (Get $get) => Account::query()->whereKey((int) $get('account_id'))->value('money') === 'bank';

        return $action->icon('heroicon-o-banknotes')->color('primary')->modalDescription(__('accounting.expenses.admin.pay_hint'))
            ->schema([
                Select::make('account_id')->label(__('accounting.expenses.admin.from'))->required()->live()
                    ->options(fn () => ExpenseClaims::moneyAccounts()->mapWithKeys(fn (Account $a) => [$a->id => $a->label()])->all())
                    ->default(fn () => ExpenseClaims::moneyAccounts()->firstWhere('money', 'bank')?->id),
                DatePicker::make('paid_on')->label(__('accounting.expenses.admin.paid_on'))->required()->default(now())->maxDate(now()->addMonth()),
                Toggle::make('per_person')->label(__('accounting.expenses.admin.per_person'))->visible(fn (Get $get) => $many && $isBank($get)),
            ]);
    }

    /**
     * @param  iterable<ExpenseClaim>  $claims
     * @param  array<string, mixed>  $data
     */
    private static function pay(iterable $claims, array $data): ?StreamedResponse
    {
        $account = Account::query()->find((int) ($data['account_id'] ?? 0));
        $result = $account === null ? null : CostCenterResource::attempt(fn () => app(ExpenseClaims::class)->pay($claims, $account, (string) $data['paid_on'], (bool) ($data['per_person'] ?? false), self::user()));
        if (! is_array($result)) {
            return null;
        }
        Notification::make()->title(__('accounting.expenses.admin.paid', ['count' => count($result['claims'])]))->success()->send();
        $sepa = $result['sepa'];
        if ($sepa === null) {
            return null;
        }

        return response()->streamDownload(function () use ($sepa) {
            echo $sepa;
        }, 'sepa-'.now()->format('Y-m-d-His').'.xml', ['Content-Type' => 'application/xml', 'Cache-Control' => 'private, no-store']);
    }

    private static function qr(ExpenseClaim $record): HtmlString
    {
        $qr = app(ExpenseClaims::class)->qrCode($record);
        $person = $record->person;

        return new HtmlString('<div class="space-y-2 text-sm" data-qr>'.($qr !== null ? '<img src="'.e($qr).'" alt="'.e(__('accounting.expenses.admin.qr')).'" style="width:14rem;height:14rem">' : '')
            .'<p>'.e(__('accounting.expenses.admin.qr_hint')).'</p><p>'.e($person->fullName()).'<br>IBAN '.e(Iban::format((string) $person->payout_iban)).'<br>'.e($record->amount()).'<br>'
            .e(__('accounting.expenses.transfer_text', ['purpose' => $record->purpose])).'</p></div>');
    }

    private static function user(): User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : abort(403);
    }

    public static function getPages(): array
    {
        return ['index' => ManageExpenseClaims::route('/')];
    }
}
