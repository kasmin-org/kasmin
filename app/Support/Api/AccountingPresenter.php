<?php

namespace App\Support\Api;

use App\Models\Account;
use App\Models\CostCenter;
use App\Models\Fund;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Period;
use App\Support\Accounting\Balances;
use App\Support\Accounting\CostCenters;

/**
 * Plain arrays of the accounting (E1) for the REST API and MCP. Amounts are decimals in the currency of the
 * installation; every record that can be referenced has its id and its name.
 */
final class AccountingPresenter
{
    /** What booking() shows, to load with the bookings. */
    public const BOOKING_WITH = ['lines.account', 'lines.costCenter', 'lines.fund', 'lines.person', 'reversal'];

    /** @return array{id: int, name: string}|null */
    public static function ref(Account|CostCenter|Fund|Period|null $model): ?array
    {
        return $model === null ? null : ['id' => (int) $model->id, 'name' => (string) $model->name];
    }

    public static function amount(int $cents): float|int
    {
        return $cents / 100;
    }

    /** @return array<string, mixed> */
    public static function account(Account $a): array
    {
        $balances = app(Balances::class);
        $raw = $balances->account($a, now());
        $booked = $a->lines()->exists();
        $data = [
            'id' => $a->id, 'name' => $a->name, 'number' => $a->number, 'type' => $a->type, 'money' => $a->money, 'iban' => $a->iban,
            'default_cost_center' => self::ref($a->defaultCostCenter), 'active' => $a->active, 'sort' => $a->sort,
            // Assets and expenses: debit minus credit; the others the other way round, as on the page.
            'balance' => self::amount(in_array($a->type, ['asset', 'expense'], true) ? $raw : -$raw),
            'system' => $a->system_key !== null, 'type_locked' => $a->system_key !== null || $booked, 'deletable' => $a->system_key === null && ! $booked,
        ];
        if ($a->money !== null) {
            // A bank or cash account with the funds whose money lies on it (page "Bank and cash accounts").
            $funds = Fund::query()->where('account_id', $a->id)->orderBy('sort')->orderBy('name')->get()
                ->map(fn (Fund $f) => ['id' => $f->id, 'name' => $f->name, 'cents' => $balances->fund($f, now())]);
            $data['funds'] = $funds->map(fn (array $f) => ['id' => $f['id'], 'name' => $f['name'], 'balance' => self::amount($f['cents'])])->values()->all();
            $data['funds_difference'] = $funds->isEmpty() ? null : self::amount($raw - (int) $funds->sum('cents'));
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public static function costCenter(CostCenter $c): array
    {
        $entries = fn () => JournalEntry::query()->whereHas('lines', fn ($q) => $q->where('cost_center_id', $c->id));

        return [
            'id' => $c->id, 'name' => $c->name, 'default' => $c->is_default, 'kind' => $c->carries ? 'carries' : 'allocated',
            'fund' => self::ref($c->resultFund()), 'sort' => $c->sort,
            'active_periods' => $c->periods()->orderBy('starts_on')->get()->map(fn (Period $p) => self::ref($p))->values()->all(),
            'bookings' => $entries()->count(), 'bookings_manual_only' => ! $entries()->where('kind', '!=', 'manual')->exists(),
            'cannot_delete' => app(CostCenters::class)->whyNotDelete($c),
        ];
    }

    /** @return array<string, mixed> */
    public static function fund(Fund $f): array
    {
        $balances = app(Balances::class);
        $difference = null;
        if ($f->account !== null) {
            // The account compared with all funds on it (E1e.1).
            $onAccount = (int) Fund::query()->where('account_id', $f->account->id)->get()->sum(fn (Fund $x) => $balances->fund($x, now()));
            $difference = self::amount($balances->account($f->account, now()) - $onAccount);
        }

        return [
            'id' => $f->id, 'name' => $f->name, 'default' => $f->is_default, 'balance' => self::amount($balances->fund($f, now())),
            'account' => self::ref($f->account), 'account_difference' => $difference, 'note' => $f->note, 'sort' => $f->sort,
            'cost_centers' => $f->costCenters()->orderBy('sort')->orderBy('name')->get()->map(fn (CostCenter $c) => self::ref($c))->values()->all(),
            'deletable' => ! $f->is_default && $balances->fund($f, '9999-12-31') === 0 && ! $f->costCenters()->exists(),
        ];
    }

    /** @return array<string, mixed> */
    public static function period(Period $p): array
    {
        return [
            'id' => $p->id, 'name' => $p->name, 'starts_on' => $p->starts_on->toDateString(), 'ends_on' => $p->ends_on->toDateString(), 'status' => $p->status,
            'closed_at' => $p->closed_at?->toIso8601String(), 'reopened_reason' => $p->reopened_reason,
            'active_cost_centers' => $p->costCenters()->orderBy('sort')->orderBy('name')->get()->map(fn (CostCenter $c) => self::ref($c))->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public static function booking(JournalEntry $e, bool $details = false): array
    {
        $data = [
            'id' => $e->id, 'number' => $e->number, 'date' => $e->date->toDateString(), 'text' => $e->text, 'receipt' => $e->receipt,
            'has_receipt_file' => $e->receipt_path !== null, 'kind' => $e->kind, 'automatic' => $e->isAutomatic(), 'total' => self::amount($e->totalCents()),
            'reverses' => $e->reverses_id, 'reversed_by' => $e->reversal?->id,
            'lines' => $e->lines->map(fn (JournalLine $l) => [
                'account' => self::ref($l->account), 'debit' => self::amount($l->debit_cents), 'credit' => self::amount($l->credit_cents),
                'cost_center' => self::ref($l->costCenter), 'fund' => self::ref($l->fund),
                'person' => $l->person !== null ? ['id' => $l->person->public_id, 'name' => $l->person->fullName()] : null, 'text' => $l->text,
            ])->values()->all(),
        ];
        if ($details) {
            $data['period'] = self::ref(Period::of($e->date));
            $data['created_at'] = $e->getAttribute('created_at')?->toIso8601String();
        }

        return $data;
    }

    /**
     * The preview of a closing (Closing::preview) with amounts as decimals.
     *
     * @param  array{rows: list<array{cost_center: CostCenter, before: int, allocation: int, after: int}>, allocations: array<int, array{cost: int, amounts: array<int, int>, cut: array<int, array{0: int, 1: int}>}>, funds: list<array{fund: Fund, before: int, result: int, after: int}>, errors: list<string>, warnings: list<string>}  $preview
     * @return array<string, mixed>
     */
    public static function closing(array $preview): array
    {
        $centers = CostCenter::query()->get()->keyBy('id');
        $center = fn (int $id) => self::ref($centers->get($id));
        $allocations = [];
        foreach ($preview['allocations'] as $source => $allocation) {
            $allocations[] = [
                'cost_center' => $center($source), 'cost' => self::amount($allocation['cost']),
                'amounts' => array_map(fn (int $target, int $amount) => ['cost_center' => $center($target), 'amount' => self::amount($amount)], array_keys($allocation['amounts']), $allocation['amounts']),
                'cut' => array_map(fn (int $target, array $cut) => ['cost_center' => $center($target), 'configured' => self::amount($cut[0]), 'used' => self::amount($cut[1])], array_keys($allocation['cut']), $allocation['cut']),
            ];
        }

        return [
            'can_close' => $preview['errors'] === [],
            'rows' => array_map(fn (array $r) => ['cost_center' => self::ref($r['cost_center']), 'kind' => $r['cost_center']->carries ? 'carries' : 'allocated',
                'before' => self::amount($r['before']), 'allocation' => self::amount($r['allocation']), 'after' => self::amount($r['after'])], $preview['rows']),
            'allocations' => $allocations,
            'funds' => array_map(fn (array $f) => ['fund' => self::ref($f['fund']), 'before' => self::amount($f['before']), 'result' => self::amount($f['result']), 'after' => self::amount($f['after'])], $preview['funds']),
            'errors' => $preview['errors'], 'warnings' => $preview['warnings'],
        ];
    }
}
