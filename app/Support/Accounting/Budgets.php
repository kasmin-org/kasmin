<?php

namespace App\Support\Accounting;

use App\Models\Budget;
use App\Models\Period;
use Illuminate\Support\Facades\DB;

/**
 * The budget of a period: the planned result per cost centre – income minus costs, so planned costs are negative. The
 * cost centre report compares it with the result after the allocation. It is a plan and books nothing, so it can be
 * changed also in a closed period.
 */
final class Budgets
{
    /** @return array<int, int> cost centre id => planned result in cents */
    public function of(Period $period): array
    {
        $budgets = [];
        foreach (Budget::query()->where('period_id', $period->id)->whereNull('account_id')->get() as $budget) {
            $budgets[(int) $budget->cost_center_id] = (int) $budget->amount_cents;
        }

        return $budgets;
    }

    /**
     * Sets the planned result of the given cost centres; 0 removes it. Other cost centres keep theirs.
     *
     * @param  array<int, int>  $amounts  cost centre id => planned result in cents
     */
    public function save(Period $period, array $amounts): void
    {
        DB::transaction(function () use ($period, $amounts) {
            Period::query()->whereKey($period->id)->lockForUpdate()->value('id'); // two saves at once give one row per cost centre
            foreach ($amounts as $center => $cents) {
                $budget = Budget::query()->where('period_id', $period->id)->where('cost_center_id', $center)->whereNull('account_id')->first();
                if ($cents === 0) {
                    $budget?->delete();
                } elseif ($budget === null) {
                    Budget::query()->create(['period_id' => $period->id, 'cost_center_id' => $center, 'account_id' => null, 'amount_cents' => $cents]);
                } elseif ($budget->amount_cents !== $cents) {
                    $budget->update(['amount_cents' => $cents]);
                }
            }
        });
    }
}
