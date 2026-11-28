<?php

namespace App\Support\Accounting;

use App\Models\Account;
use App\Models\CostCenter;
use App\Models\Fund;
use App\Models\Period;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Closing of a period (E1d.8, E1e–E1h): every cost centre that is allocated is spread over others by the key of the
 * period; then the result of every cost centre goes into its fund and income and expense accounts start again at 0.
 * The preview shows everything before; reopening removes these bookings again (Periods::reopen).
 */
class Closing
{
    public function __construct(private readonly Balances $balances, private readonly Journal $journal) {}

    /**
     * @return array{
     *   rows: list<array{cost_center: CostCenter, before: int, allocation: int, after: int}>,
     *   allocations: array<int, array{cost: int, amounts: array<int, int>, cut: array<int, array{0: int, 1: int}>}>,
     *   funds: list<array{fund: Fund, before: int, result: int, after: int}>,
     *   errors: list<string>, warnings: list<string>
     * }
     */
    public function preview(Period $period): array
    {
        $errors = [];
        $warnings = [];
        if ($period->isClosed()) {
            $errors[] = __('accounting.errors.already_closed');
        }
        if (Period::query()->where('ends_on', '<', $period->starts_on)->where('status', 'open')->exists()) {
            $errors[] = __('accounting.errors.close_in_order');
        }
        $results = $this->balances->results($period, false);
        $income = $this->balances->income($period);
        $centers = CostCenter::query()->orderBy('sort')->orderBy('name')->get()->keyBy('id');
        $allocated = array_fill_keys($centers->keys()->all(), 0);
        $allocations = [];
        $inKeys = [];
        foreach ($centers->where('carries', false) as $source) {
            $cost = -($results[$source->id] ?? 0);
            $rules = [];
            foreach ($period->allocationRules()->where('source_cost_center_id', $source->id)->get() as $r) {
                $rules[] = ['target' => (int) $r->target_cost_center_id, 'kind' => (string) $r->kind, 'value' => (int) $r->value];
            }
            $result = Allocation::compute($cost, $rules, $income);
            foreach ($result['errors'] as $error) {
                if ($cost !== 0 || $error !== 'allocation.no_key') {
                    $errors[] = $source->name.': '.__('accounting.'.$error);
                }
            }
            foreach ($rules as $rule) {
                $inKeys[$rule['target']] = true;
                if (! ($centers[$rule['target']]->carries ?? false)) {
                    $errors[] = $source->name.': '.__('accounting.errors.chain', ['name' => $centers[$rule['target']]->name ?? '?']);
                }
            }
            foreach ($result['cut'] as $target => [$configured, $used]) {
                $warnings[] = __('accounting.closing.cut', ['source' => $source->name, 'target' => $centers[$target]->name ?? '?', 'configured' => Money::format($configured), 'used' => Money::format($used), 'cost' => Money::format($cost)]);
            }
            $allocations[$source->id] = ['cost' => $cost, 'amounts' => $result['amounts'], 'cut' => $result['cut']];
            $allocated[$source->id] += $cost; // the allocated cost centre is relieved of its costs
            foreach ($result['amounts'] as $target => $amount) {
                $allocated[$target] = ($allocated[$target] ?? 0) - $amount;
            }
        }
        if ($allocations !== []) {
            foreach ($centers->where('carries', true) as $center) {
                if (($income[$center->id] ?? 0) > 0 && ! isset($inKeys[$center->id])) {
                    $warnings[] = __('accounting.closing.not_in_key', ['name' => $center->name]);
                }
            }
        }

        $rows = [];
        $fundResults = [];
        foreach ($centers as $id => $center) {
            $before = $results[$id] ?? 0;
            $after = $before + ($allocated[$id] ?? 0);
            if ($before === 0 && $after === 0) {
                continue;
            }
            $rows[] = ['cost_center' => $center, 'before' => $before, 'allocation' => $allocated[$id] ?? 0, 'after' => $after];
            if ($center->carries) {
                $fund = $center->resultFund();
                $fundResults[$fund->id ?? 0] = ($fundResults[$fund->id ?? 0] ?? 0) + $after;
            }
        }
        $funds = [];
        $dayBefore = $period->starts_on->copy()->subDay();
        foreach (Fund::query()->orderByDesc('is_default')->orderBy('sort')->orderBy('name')->get() as $fund) {
            $before = $this->balances->fund($fund, $dayBefore);
            $movements = $this->balances->fund($fund, $period->ends_on) - $before; // transfers within the period
            $result = $fundResults[$fund->id] ?? 0;
            $after = $before + $movements + $result;
            if ($before === 0 && $result === 0 && $movements === 0) {
                continue;
            }
            $funds[] = ['fund' => $fund, 'before' => $before + $movements, 'result' => $result, 'after' => $after];
            if ($after < 0) {
                $warnings[] = __('accounting.closing.negative_fund', ['name' => $fund->name, 'amount' => Money::format($after)]);
            }
        }

        return ['rows' => $rows, 'allocations' => $allocations, 'funds' => $funds, 'errors' => array_values(array_unique($errors)), 'warnings' => $warnings];
    }

    /**
     * Books the allocations and the closing and locks the period.
     *
     * @return array<string, mixed> the preview it followed
     */
    public function close(Period $period): array
    {
        $preview = $this->preview($period);
        if ($preview['errors'] !== []) {
            throw new AccountingException(implode(' ', $preview['errors']));
        }
        DB::transaction(function () use ($period, $preview) {
            $allocations = Account::system('allocations');
            foreach ($preview['allocations'] as $sourceId => $allocation) {
                if ($allocation['cost'] === 0) {
                    continue;
                }
                $source = CostCenter::query()->findOrFail($sourceId);
                $lines = [$allocation['cost'] > 0
                    ? ['account' => $allocations, 'credit' => $allocation['cost'], 'cost_center' => $source]
                    : ['account' => $allocations, 'debit' => -$allocation['cost'], 'cost_center' => $source]];
                foreach ($allocation['amounts'] as $target => $amount) {
                    if ($amount !== 0) {
                        $lines[] = $amount > 0 ? ['account' => $allocations, 'debit' => $amount, 'cost_center' => $target] : ['account' => $allocations, 'credit' => -$amount, 'cost_center' => $target];
                    }
                }
                $this->journal->post(['date' => $period->ends_on, 'text' => __('accounting.closing.allocation_text', ['name' => $source->name, 'period' => $period->name]),
                    'kind' => 'allocation', 'period_closing_id' => $period->id], $lines, closing: true);
            }
            // Income and expenses back to 0; the result of every cost centre into its fund.
            $lines = [];
            $perFund = [];
            foreach ($this->balances->resultLines($period) as $row) {
                $lines[] = $row['balance'] > 0
                    ? ['account' => $row['account_id'], 'debit' => $row['balance'], 'cost_center' => $row['cost_center_id']]
                    : ['account' => $row['account_id'], 'credit' => -$row['balance'], 'cost_center' => $row['cost_center_id']];
                $fund = CostCenter::query()->find($row['cost_center_id'])?->resultFund() ?? Fund::default();
                $perFund[$fund->id] = ($perFund[$fund->id] ?? 0) + $row['balance'];
            }
            foreach ($perFund as $fundId => $result) {
                if ($result !== 0) {
                    $lines[] = $result > 0 ? ['account' => 'funds', 'credit' => $result, 'fund' => $fundId] : ['account' => 'funds', 'debit' => -$result, 'fund' => $fundId];
                }
            }
            if ($lines !== []) {
                $this->journal->post(['date' => $period->ends_on, 'text' => __('accounting.closing.closing_text', ['period' => $period->name]),
                    'kind' => 'closing', 'period_closing_id' => $period->id], $lines, closing: true);
            }
            $period->forceFill(['status' => 'closed', 'closed_at' => now(), 'closed_by_id' => auth()->id(), 'reopened_reason' => null])->save();
            Audit::record('period.closed', $period);
        });

        return $preview;
    }
}
