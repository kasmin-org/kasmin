<?php

namespace App\Support\Accounting;

use App\Models\Account;
use App\Models\Advance;
use App\Models\CostCenter;
use App\Models\Event;
use App\Models\ExpenseClaim;
use App\Models\Period;
use App\Models\Person;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Finance\Iban;
use App\Support\Finance\InvoicePdf;
use App\Support\Mail\Composer;
use App\Support\Mail\Notifier;
use App\Support\Mail\Placeholders;
use App\Support\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Expense claims (Auslagen, E1d.3): somebody pays for the organisation and gets the money back. Submitted in the
 * portal or entered centrally by the treasurer, approved (expense to "expenses to pay back" of the person), paid out
 * by bank transfer (SEPA file, payment code) or in cash, or waived as a donation. A claim against an advance is booked
 * against the advance instead (see Advances).
 */
class ExpenseClaims
{
    public const RECEIPTS = 'expense-receipts';

    /** Photos from phones and PDFs, as the portal offers them. */
    public const RECEIPT_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif', 'application/pdf'];

    public const RECEIPT_MAX_KB = 10240;

    /** A line of the setting of the flat rates: "name: amount". */
    public const FLAT_RATE_LINE = '/^\s*(.+?)\s*:\s*(\d+(?:[.,]\d{1,2})?)\s*(?:€|EUR)?\s*$/u';

    public function __construct(private readonly Journal $journal) {}

    /**
     * Flat rates from the setting, one per line as "name: amount" (e.g. "Travel per event: 90"). Empty by default.
     *
     * @return list<array{name: string, cents: int}>
     */
    public static function flatRates(): array
    {
        $rates = [];
        foreach (preg_split('/\R/', (string) Settings::get('expense_flat_rates')) ?: [] as $line) {
            if (preg_match(self::FLAT_RATE_LINE, $line, $m) === 1) {
                $rates[] = ['name' => mb_substr($m[1], 0, 80), 'cents' => (int) round(((float) str_replace(',', '.', $m[2])) * 100)];
            }
        }

        return $rates;
    }

    /** @return Collection<int, Account> expense accounts a claim can be booked on (no internal ones) */
    public static function expenseAccounts(): Collection
    {
        return Account::query()->where('type', 'expense')->where('active', true)->whereNull('system_key')->orderBy('sort')->orderBy('name')->get();
    }

    /** @return Collection<int, Account> bank and cash accounts */
    public static function moneyAccounts(): Collection
    {
        return Account::query()->whereNotNull('money')->where('active', true)->orderBy('sort')->orderBy('name')->get();
    }

    /** "Other expenses" of the standard chart (in either language), else the first expense account. */
    public static function defaultAccount(): ?Account
    {
        $names = [trans('accounting.defaults.accounts.other_expenses', [], 'de'), trans('accounting.defaults.accounts.other_expenses', [], 'en')];
        $accounts = self::expenseAccounts();

        return $accounts->first(fn (Account $a) => in_array($a->name, $names, true)) ?? $accounts->first();
    }

    /**
     * A new claim, by the person in the portal or entered centrally for them ($by is then the treasurer). A flat rate
     * gives the amount (rate × quantity) and needs no receipt.
     *
     * @param  array{date: \DateTimeInterface|string, amount_cents?: int|null, purpose?: string|null, account_id?: int|null, cost_center_id?: int|null, event_id?: int|null, flat_rate?: string|null, quantity?: float|int|string|null, advance_id?: int|null, waived?: bool|null, receipt?: UploadedFile|string|null}  $data  receipt: an upload, or the path of a file already stored in the receipts folder
     */
    public function submit(Person $person, array $data, ?User $by = null): ExpenseClaim
    {
        if ($person->getAttribute('anonymised_at') !== null) {
            throw AccountingException::because('expense_person');
        }
        [$cents, $purpose, $rate] = $this->amountAndPurpose($data);
        $date = Carbon::parse($data['date'])->startOfDay();
        if ($date->isAfter(today())) {
            throw AccountingException::because('expense_date');
        }
        $event = isset($data['event_id']) ? Event::query()->find((int) $data['event_id']) : null;
        if ($rate !== null && $event !== null && ExpenseClaim::query()->where('person_id', $person->id)->where('event_id', $event->id)->where('flat_rate', $rate['name'])->whereNot('status', 'rejected')->exists()) {
            throw AccountingException::because('flat_twice', ['name' => $rate['name'], 'event' => $event->title]);
        }
        $account = $this->account($data['account_id'] ?? null);
        $advance = $this->openAdvance($person, $data['advance_id'] ?? null);
        $path = $this->storeReceipt($data['receipt'] ?? null);

        $claim = DB::transaction(function () use ($person, $by, $date, $cents, $purpose, $account, $event, $data, $path, $rate, $advance) {
            // A receipt against an advance: the advance is locked, so it cannot be settled at the same moment.
            if ($advance !== null && Advance::query()->whereKey($advance->id)->lockForUpdate()->value('settled_on') !== null) {
                throw AccountingException::because('advance_closed');
            }

            return ExpenseClaim::query()->create([
                'person_id' => $person->id, 'submitted_by_id' => $by?->id, 'date' => $date->toDateString(), 'amount_cents' => $cents,
                'purpose' => $purpose, 'account_id' => $account?->id, 'cost_center_id' => $this->costCenterFor($event, $data['cost_center_id'] ?? null, $account),
                'event_id' => $event?->id, 'receipt_path' => $path, 'flat' => $rate !== null, 'flat_rate' => $rate['name'] ?? null,
                'advance_id' => $advance?->id, 'waived' => $advance === null && (bool) ($data['waived'] ?? false), 'status' => 'submitted',
            ]);
        });
        app(Notifier::class)->event('expense_claims', self::text('notify_submitted', ['name' => $person->fullName(), 'amount' => $claim->amount(), 'purpose' => $claim->purpose]), url('/admin/expense-claims'));

        return $claim;
    }

    private function storeReceipt(UploadedFile|string|null $receipt): ?string
    {
        if ($receipt instanceof UploadedFile) {
            $path = $receipt->store(self::RECEIPTS, 'local');

            return is_string($path) ? $path : null;
        }

        // Only files that the upload of the admin interface put into the folder of the receipts.
        return is_string($receipt) && str_starts_with($receipt, self::RECEIPTS.'/') && ! str_contains($receipt, '..') ? $receipt : null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: int, 1: string, 2: array{name: string, cents: int}|null}
     */
    private function amountAndPurpose(array $data): array
    {
        $purpose = trim((string) ($data['purpose'] ?? ''));
        $name = trim((string) ($data['flat_rate'] ?? ''));
        if ($name === '') {
            $cents = (int) ($data['amount_cents'] ?? 0);
            if ($cents <= 0 || $purpose === '') {
                throw AccountingException::because($cents <= 0 ? 'expense_amount' : 'expense_purpose');
            }

            return [$cents, mb_substr($purpose, 0, 250), null];
        }
        $rate = collect(self::flatRates())->firstWhere('name', $name) ?? throw AccountingException::because('flat_rate');
        $quantity = round((float) str_replace(',', '.', (string) ($data['quantity'] ?? 1)), 2);
        if ($quantity <= 0 || $quantity > 100000) {
            throw AccountingException::because('expense_amount');
        }
        $label = $quantity === 1.0 ? $rate['name'] : $rate['name'].' ('.rtrim(rtrim(number_format($quantity, 2, ',', ''), '0'), ',').' × '.Money::format($rate['cents']).')';

        return [(int) round($rate['cents'] * $quantity), mb_substr($purpose === '' ? $label : $label.': '.$purpose, 0, 250), $rate];
    }

    private function account(mixed $id): ?Account
    {
        if ($id === null || $id === '') {
            return self::defaultAccount();
        }

        return self::expenseAccounts()->firstWhere('id', (int) $id) ?? throw AccountingException::because('expense_account');
    }

    private function openAdvance(Person $person, mixed $id): ?Advance
    {
        if ($id === null || $id === '') {
            return null;
        }

        return Advance::query()->where('person_id', $person->id)->whereNull('settled_on')->find((int) $id) ?? throw AccountingException::because('advance_closed');
    }

    /** The cost centre of the event (or its series), else the chosen one, else the account's, else the standard one. */
    public function costCenterFor(?Event $event, mixed $chosen, ?Account $account = null): int
    {
        $id = $event?->getAttribute('cost_center_id') ?? $event?->series?->getAttribute('cost_center_id');
        $id ??= $chosen !== null && $chosen !== '' ? (int) $chosen : null;
        $id ??= $account?->default_cost_center_id;

        return $id !== null && CostCenter::query()->whereKey((int) $id)->exists() ? (int) $id : CostCenter::default()->id;
    }

    /** With "approval by a second person" on, nobody approves a claim they entered or that is their own. */
    public function mayApprove(ExpenseClaim $claim, User $user): bool
    {
        if (! $user->canManageFinance()) {
            return false;
        }

        return ! Settings::bool('expense_four_eyes') || ($claim->submitted_by_id !== $user->id && $user->person_id !== $claim->person_id);
    }

    /**
     * Approves: expense (account, cost centre) to "expenses to pay back" of the person – or to the advance of the person
     * if the receipt settles one. Account and cost centre can be corrected on the way.
     */
    public function approve(ExpenseClaim $claim, User $by, mixed $accountId = null, mixed $costCenterId = null): ExpenseClaim
    {
        $this->expect($claim, ['submitted']);
        if (! $this->mayApprove($claim, $by)) {
            throw AccountingException::because('four_eyes');
        }
        $account = $accountId !== null && $accountId !== '' ? $this->account($accountId) : ($claim->account ?? self::defaultAccount());
        if ($account === null) {
            throw AccountingException::because('expense_account');
        }
        $center = $costCenterId !== null && $costCenterId !== '' ? $this->costCenterFor(null, $costCenterId) : ($claim->cost_center_id ?? $this->costCenterFor($claim->event, null, $account));
        $against = $claim->advance_id !== null ? 'advances' : 'open_expenses';

        DB::transaction(function () use ($claim, $by, $account, $center, $against) {
            $this->lockAndExpect($claim, ['submitted']);
            $claim->forceFill(['account_id' => $account->id, 'cost_center_id' => $center, 'status' => $against === 'advances' ? 'settled' : 'approved', 'decided_by_id' => $by->id, 'decided_at' => now()])->save();
            $this->journal->post($this->entry($claim, 'expense', $this->bookingDate($claim->date), 'approved', $by), [
                ['account' => $account, 'debit' => $claim->amount_cents, 'cost_center' => $center, 'text' => $claim->purpose],
                ['account' => $against, 'credit' => $claim->amount_cents, 'person_id' => $claim->person_id],
            ]);
        });
        if ($claim->waived && $claim->status === 'approved') {
            return $this->donate($claim, $by);
        }
        $this->mail($claim, 'approved');

        return $claim;
    }

    /**
     * Inside a transaction: the claim read again and locked, so that two people cannot decide, pay or waive it at the
     * same time (the second one gets the new status and is refused).
     *
     * @param  list<string>  $statuses
     */
    private function lockAndExpect(ExpenseClaim $claim, array $statuses): void
    {
        $fresh = ExpenseClaim::query()->whereKey($claim->id)->lockForUpdate()->firstOrFail();
        $claim->setRawAttributes($fresh->getAttributes(), true);
        $this->expect($claim, $statuses);
    }

    public function reject(ExpenseClaim $claim, User $by, string $reason): ExpenseClaim
    {
        $this->expect($claim, ['submitted']);
        $reason = trim($reason);
        if ($reason === '') {
            throw AccountingException::because('reason');
        }
        DB::transaction(function () use ($claim, $by, $reason) {
            $this->lockAndExpect($claim, ['submitted']);
            $claim->forceFill(['status' => 'rejected', 'reason' => mb_substr($reason, 0, 1000), 'decided_by_id' => $by->id, 'decided_at' => now()])->save();
        });
        $this->mail($claim, 'rejected');

        return $claim;
    }

    /**
     * Pays approved claims out of a bank or cash account: "expenses to pay back" of the person to the money account,
     * one booking per claim. From a bank account every person needs an IBAN; it is copied into the claim and the
     * answer holds the SEPA file (one transfer per claim, or per person).
     *
     * @param  iterable<ExpenseClaim>  $claims
     * @return array{claims: list<ExpenseClaim>, sepa: string|null}
     */
    public function pay(iterable $claims, Account $from, \DateTimeInterface|string $on, bool $perPerson = false, ?User $by = null): array
    {
        $list = collect($claims)->values();
        if ($list->isEmpty() || $from->money === null || ! $from->active) {
            throw AccountingException::because($list->isEmpty() ? 'expense_none' : 'money_account');
        }
        $list->each(fn (ExpenseClaim $c) => $this->expect($c, ['approved']));
        $date = Carbon::parse($on)->startOfDay();
        $bank = $from->money === 'bank';
        $missing = $bank ? $list->filter(fn (ExpenseClaim $c) => ! Iban::valid((string) $c->person->payout_iban))->map(fn (ExpenseClaim $c) => $c->person->fullName())->unique() : collect();
        if ($missing->isNotEmpty()) {
            throw AccountingException::because('no_iban', ['names' => $missing->implode(', ')]);
        }
        $sepa = DB::transaction(function () use ($list, $from, $date, $bank, $perPerson, $by) {
            // All claims locked first, in the order of their ids, then booked: two payouts at once never wait for each other in a
            // circle, and the second one is refused because the claims are paid already.
            foreach ($list->sortBy('id') as $claim) {
                $this->lockAndExpect($claim, ['approved']);
            }
            foreach ($list as $claim) {
                $claim->forceFill(['status' => 'paid', 'paid_on' => $date->toDateString(), 'paid_from_account_id' => $from->id,
                    'iban' => $bank ? Iban::normalise((string) $claim->person->payout_iban) : null, 'account_holder' => $bank ? mb_substr($claim->person->fullName(), 0, 120) : null])->save();
                $this->journal->post($this->entry($claim, 'expense_payout', $date, 'paid', $by), [
                    ['account' => 'open_expenses', 'debit' => $claim->amount_cents, 'person_id' => $claim->person_id],
                    ['account' => $from, 'credit' => $claim->amount_cents],
                ]);
            }

            return $bank ? $this->sepa($list, $from, $date, $perPerson) : null;
        });
        Audit::record('expense_claims.paid', null, null, ['count' => $list->count(), 'total' => $list->sum('amount_cents'), 'account' => $from->id]);
        $list->each(fn (ExpenseClaim $c) => $this->mail($c, 'paid'));

        return ['claims' => array_values($list->all()), 'sepa' => $sepa];
    }

    /** The person waives the refund: "expenses to pay back" to donations, in the cost centre of the expense. */
    public function donate(ExpenseClaim $claim, ?User $by = null): ExpenseClaim
    {
        $this->expect($claim, ['approved']);
        $date = $this->bookingDate(today());
        DB::transaction(function () use ($claim, $by, $date) {
            $this->lockAndExpect($claim, ['approved']);
            $claim->forceFill(['status' => 'donated', 'paid_on' => $date->toDateString(), 'waived' => true])->save();
            $this->journal->post($this->entry($claim, 'donation', $date, 'donated', $by), [
                ['account' => 'open_expenses', 'debit' => $claim->amount_cents, 'person_id' => $claim->person_id],
                ['account' => 'donations', 'credit' => $claim->amount_cents, 'cost_center' => $claim->cost_center_id, 'person_id' => $claim->person_id],
            ]);
        });
        $this->mail($claim, 'donated');

        return $claim;
    }

    /** In the portal: before the approval the claim is marked, after it the donation is booked at once. */
    public function waive(ExpenseClaim $claim, User $by): ExpenseClaim
    {
        if ($claim->status === 'submitted' && $claim->advance_id === null) {
            $marked = DB::transaction(function () use ($claim) {
                $this->lockAndExpect($claim, ['submitted', 'approved']);
                if ($claim->status !== 'submitted') {
                    return false; // approved in the meantime: the donation is booked below
                }
                $claim->forceFill(['waived' => true])->save();

                return true;
            });
            if ($marked) {
                return $claim;
            }
        }

        return $this->donate($claim, $by);
    }

    /**
     * What the receipts are more than an advance: an approved claim of the person (advance to "expenses to pay back"),
     * paid out or waived like every other.
     */
    public function restOf(Advance $advance, int $cents, Carbon $date, ?User $by): ExpenseClaim
    {
        $center = $advance->claims()->where('status', 'settled')->latest('id')->value('cost_center_id');
        $claim = ExpenseClaim::query()->create([
            'person_id' => $advance->person_id, 'submitted_by_id' => $by?->id, 'date' => $date->toDateString(), 'amount_cents' => $cents,
            'purpose' => mb_substr(self::text('rest_purpose', ['date' => $advance->paid_on->isoFormat('L'), 'purpose' => (string) $advance->purpose]), 0, 250),
            'cost_center_id' => $center ?? CostCenter::default()->id, 'advance_id' => $advance->id, 'advance_rest' => true,
            'status' => 'approved', 'decided_by_id' => $by?->id, 'decided_at' => now(),
        ]);
        $this->journal->post($this->entry($claim, 'expense', $date, 'rest', $by), [
            ['account' => 'advances', 'debit' => $cents, 'person_id' => $claim->person_id],
            ['account' => 'open_expenses', 'credit' => $cents, 'person_id' => $claim->person_id],
        ]);

        return $claim;
    }

    /** The EPC payment code for one approved claim, to pay it with a banking app (null without IBAN). */
    public function qrCode(ExpenseClaim $claim): ?string
    {
        $iban = (string) $claim->person->payout_iban;
        if ($claim->status !== 'approved' || ! Iban::valid($iban)) {
            return null;
        }
        $payload = InvoicePdf::epc('', $claim->person->fullName(), $iban, $claim->amount_cents, '', $this->transferText($claim->purpose));

        return $payload === null ? null : InvoicePdf::qrImage($payload);
    }

    /** Stores the IBAN for refunds of the person (encrypted); empty removes it. */
    public function setIban(Person $person, ?string $iban): void
    {
        $iban = Iban::normalise((string) $iban);
        if ($iban !== '' && ! Iban::valid($iban)) {
            throw AccountingException::because('iban');
        }
        $person->forceFill(['payout_iban' => $iban === '' ? null : $iban])->save();
    }

    /**
     * Texts of bookings, transfers and notifications are in the language of the installation, whoever triggers them.
     *
     * @param  array<string, string|int|null>  $values
     */
    public static function text(string $key, array $values = []): string
    {
        $locale = Settings::get('default_locale');

        return (string) trans('accounting.expenses.'.$key, $values, in_array($locale, ['de', 'en'], true) ? $locale : app()->getLocale());
    }

    /** @param  list<string>  $statuses */
    private function expect(ExpenseClaim $claim, array $statuses): void
    {
        if (! in_array($claim->status, $statuses, true)) {
            throw AccountingException::because('expense_status', ['purpose' => $claim->purpose, 'status' => __('accounting.expenses.status.'.$claim->status)]);
        }
    }

    /** The date of the receipt, or today if its period is closed or before the first one. */
    private function bookingDate(Carbon $date): Carbon
    {
        $period = Period::of($date);
        $first = Period::query()->min('starts_on');
        if (($period !== null && $period->isClosed()) || ($period === null && $first !== null && $date->lt(Carbon::parse((string) $first)))) {
            return today();
        }

        return $date->copy();
    }

    /** @return array{date: Carbon, text: string, receipt: string, receipt_path: string|null, kind: string, source: ExpenseClaim, created_by_id: int|null} */
    private function entry(ExpenseClaim $claim, string $kind, Carbon $date, string $text, ?User $by): array
    {
        return [
            'date' => $date, 'text' => self::text('entry.'.$text, ['name' => $claim->person->fullName(), 'purpose' => $claim->purpose]),
            'receipt' => self::text('receipt_number', ['id' => $claim->id]), 'receipt_path' => $claim->receipt_path,
            'kind' => $kind, 'source' => $claim, 'created_by_id' => $by->id ?? (auth()->id() !== null ? (int) auth()->id() : null),
        ];
    }

    private function transferText(string $purpose): string
    {
        return self::text('transfer_text', ['purpose' => $purpose]);
    }

    /** @param  Collection<int, ExpenseClaim>  $claims */
    private function sepa(Collection $claims, Account $from, Carbon $date, bool $perPerson): string
    {
        $org = InvoicePdf::organisation();
        $own = Iban::normalise((string) $from->iban);
        $debtor = ['name' => $org['name'], 'iban' => $own !== '' ? $own : $org['iban'], 'bic' => $own === '' || $own === $org['iban'] ? $org['bic'] : ''];
        $groups = $perPerson ? $claims->groupBy('person_id')->values() : $claims->map(fn (ExpenseClaim $c) => collect([$c]));
        $payments = $groups->map(fn (Collection $group) => [
            'id' => ($perPerson ? 'KASMIN-P'.$group->first()?->person_id.'-' : 'KASMIN-A').$group->pluck('id')->implode('-'),
            'name' => (string) $group->first()?->account_holder, 'iban' => (string) $group->first()?->iban,
            'cents' => (int) $group->sum('amount_cents'), 'text' => $this->transferText($group->pluck('purpose')->implode(', ')),
        ])->all();

        return app(SepaCreditTransfer::class)->render($debtor, array_values($payments), $date->isPast() ? today() : $date);
    }

    /** Mail to the person about the decision or the payout; never with the IBAN. */
    private function mail(ExpenseClaim $claim, string $kind): void
    {
        $person = $claim->person;
        $address = $person->primaryEmail();
        if ($address === null) {
            return;
        }
        $locale = $person->locale;
        $values = ['date' => $claim->date->isoFormat('L'), 'amount' => $claim->amount(), 'purpose' => $claim->purpose,
            'way' => __('accounting.expenses.way.'.(Account::query()->whereKey($claim->paid_from_account_id)->value('money') ?? 'bank'), [], $locale), 'organisation' => Placeholders::organisationName()];
        $html = '<p>'.e(app(Placeholders::class)->salutation($person, $locale)).'</p><p>'.e(__('accounting.expenses.mail.'.$kind, $values, $locale)).'</p>'
            .($kind === 'rejected' ? '<p>'.e(__('accounting.expenses.mail.reason', [], $locale)).' '.e((string) $claim->reason).'</p>' : '')
            .'<p><a href="'.e(route('portal.expenses')).'">'.e(__('accounting.expenses.portal.title', [], $locale)).'</a></p>';
        app(Composer::class)->queueRaw($address, $person->fullName(), (string) __('accounting.expenses.mail.'.$kind.'_subject', $values, $locale), $html, $locale, 'transactional', $person->id);
    }
}
