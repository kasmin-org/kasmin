<?php

namespace App\Support\Accounting;

use App\Models\Account;
use App\Models\Fund;
use App\Models\JournalLine;
use App\Models\Period;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Balances computed from the lines (E1): nothing is stored, so they are always right after a change (opening values of
 * later periods included). Income and expenses are reported without the closing bookings.
 */
class Balances
{
    /** @return Builder<JournalLine> */
    private function lines(?Carbon $from, ?Carbon $to, bool $withClosing = true): Builder
    {
        return JournalLine::query()->whereHas('entry', function (Builder $q) use ($from, $to, $withClosing) {
            $q->when($from !== null, fn ($q) => $q->whereDate('date', '>=', $from?->toDateString()))
                ->when($to !== null, fn ($q) => $q->whereDate('date', '<=', $to?->toDateString()))
                ->when(! $withClosing, fn ($q) => $q->where('kind', '!=', 'closing'));
        });
    }

    /** Debit minus credit of an account up to a day (inclusive): positive for assets and costs. */
    public function account(Account $account, \DateTimeInterface|string $upTo, ?int $costCenterId = null): int
    {
        $q = $this->lines(null, Carbon::parse($upTo))->where('account_id', $account->id)->when($costCenterId !== null, fn ($q) => $q->where('cost_center_id', $costCenterId));

        return (int) $q->sum('debit_cents') - (int) $q->sum('credit_cents');
    }

    /** What a fund holds at the end of a day: credits minus debits on the equity of the funds. */
    public function fund(Fund $fund, \DateTimeInterface|string $upTo): int
    {
        $q = $this->lines(null, Carbon::parse($upTo))->where('account_id', Account::system('funds')->id)->where('fund_id', $fund->id);

        return (int) $q->sum('credit_cents') - (int) $q->sum('debit_cents');
    }

    /**
     * Result per cost centre in a period (income minus expenses), without the closing; with or without the internal
     * allocations.
     *
     * @return array<int, int> cost centre id => result in cents
     */
    public function results(Period $period, bool $withAllocations = true): array
    {
        $rows = $this->lines($period->starts_on, $period->ends_on, false)
            ->whereHas('account', fn ($q) => $q->whereIn('type', ['income', 'expense']))
            ->when(! $withAllocations, fn ($q) => $q->whereHas('entry', fn ($e) => $e->where('kind', '!=', 'allocation')))
            ->selectRaw('cost_center_id, SUM(credit_cents) - SUM(debit_cents) AS result')->groupBy('cost_center_id')->pluck('result', 'cost_center_id');

        return $rows->map(fn ($v) => (int) $v)->all();
    }

    /**
     * Net income per cost centre (income accounts after discounts, without allocations) – the base for "share".
     *
     * @return array<int, int>
     */
    public function income(Period $period): array
    {
        return $this->lines($period->starts_on, $period->ends_on, false)
            ->whereHas('account', fn ($q) => $q->where('type', 'income'))
            ->whereHas('entry', fn ($e) => $e->where('kind', '!=', 'allocation'))
            ->selectRaw('cost_center_id, SUM(credit_cents) - SUM(debit_cents) AS income')->groupBy('cost_center_id')->pluck('income', 'cost_center_id')
            ->map(fn ($v) => (int) $v)->all();
    }

    /**
     * Balance per income/expense account and cost centre in a period, with allocations, without the closing.
     *
     * @return list<array{account_id: int, cost_center_id: int, balance: int}> credit minus debit
     */
    public function resultLines(Period $period): array
    {
        $rows = [];
        $grouped = $this->lines($period->starts_on, $period->ends_on, false)
            ->whereHas('account', fn ($q) => $q->whereIn('type', ['income', 'expense']))
            ->selectRaw('account_id, cost_center_id, SUM(credit_cents) - SUM(debit_cents) AS balance')->groupBy('account_id', 'cost_center_id')->get();
        foreach ($grouped as $r) {
            $balance = (int) $r->getAttribute('balance');
            if ($balance !== 0) {
                $rows[] = ['account_id' => (int) $r->getAttribute('account_id'), 'cost_center_id' => (int) $r->getAttribute('cost_center_id'), 'balance' => $balance];
            }
        }

        return $rows;
    }
}
