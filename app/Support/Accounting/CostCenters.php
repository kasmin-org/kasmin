<?php

namespace App\Support\Accounting;

use App\Models\Account;
use App\Models\AllocationRule;
use App\Models\Budget;
use App\Models\CostCenter;
use App\Models\Fund;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Period;
use App\Support\Audit\Audit;
use App\Support\Backup\DatabaseBackup;
use Illuminate\Support\Facades\DB;

/**
 * Rules of cost centres (E1e.2, E1e.6, E1f.1, E1f.4, E1j):
 * - one over all years, active or not per period; a new period takes over the active ones;
 * - "carries its result" (into a fund) or "is allocated" (no fund, must be allocated, at 0 after the closing);
 *   allocations only go to cost centres that carry their result (no chains);
 * - deleting: never the standard one, never while it receives an allocation in an open period, never with bookings in
 *   closed periods (reopen first); bookings are moved to another cost centre, or – only manual bookings in open
 *   periods – deleted after typing the confirmation sentence; a backup is made before.
 */
class CostCenters
{
    /**
     * Reasons that keep a cost centre from being deleted (empty = may be deleted).
     *
     * @return list<string>
     */
    public function whyNotDelete(CostCenter $center): array
    {
        $reasons = [];
        if ($center->is_default) {
            $reasons[] = (string) __('accounting.cost_centers.is_default');
        }
        $receiving = $this->receivingIn($center);
        if ($receiving !== []) {
            $reasons[] = (string) __('accounting.cost_centers.receives', ['periods' => implode(', ', $receiving)]);
        }
        if (Accounting::hasClosedLines($center)) {
            $reasons[] = (string) __('accounting.cost_centers.closed_lines');
        }

        return $reasons;
    }

    /** @return list<string> names of open periods whose key lets this cost centre receive an allocation */
    public function receivingIn(CostCenter $center): array
    {
        $names = AllocationRule::query()->where('target_cost_center_id', $center->id)
            ->whereHas('period', fn ($q) => $q->where('status', 'open'))->get()
            ->map(fn (AllocationRule $r) => (string) Period::query()->whereKey($r->period_id)->value('name'))->unique()->all();

        return array_values($names);
    }

    /**
     * Number of bookings that would be deleted with the cost centre, and whether all of them may be.
     *
     * @return array{count: int, manual_only: bool}
     */
    public function deletable(CostCenter $center): array
    {
        $entries = JournalEntry::query()->whereHas('lines', fn ($q) => $q->where('cost_center_id', $center->id))->get();

        return ['count' => $entries->count(), 'manual_only' => $entries->every(fn (JournalEntry $e) => $e->kind === 'manual')];
    }

    public static function confirmation(int $count): string
    {
        return (string) __('accounting.cost_centers.confirm_sentence', ['count' => $count]);
    }

    /** Deletes a cost centre; its bookings move to $target or (manual only) are deleted. */
    public function delete(CostCenter $center, ?CostCenter $target, bool $deleteBookings = false, ?string $typed = null): void
    {
        $reasons = $this->whyNotDelete($center);
        if ($reasons !== []) {
            throw new AccountingException(implode(' ', $reasons));
        }
        $info = $this->deletable($center);
        if ($target === null && $info['count'] > 0) {
            if (! $deleteBookings || ! $info['manual_only']) {
                throw AccountingException::because('cost_center_bookings');
            }
            if (trim((string) $typed) !== self::confirmation($info['count'])) {
                throw AccountingException::because('confirmation');
            }
        }
        if ($target !== null && $target->is($center)) {
            throw AccountingException::because('cost_center_target');
        }
        if ($info['count'] > 0) {
            app(DatabaseBackup::class)->create('before-deleting-cost-centre');
        }
        DB::transaction(function () use ($center, $target, $info) {
            if ($target !== null) {
                JournalLine::query()->where('cost_center_id', $center->id)->update(['cost_center_id' => $target->id]);
                foreach (['events', 'event_series', 'event_installments', 'membership_types'] as $table) {
                    DB::table($table)->where('cost_center_id', $center->id)->update(['cost_center_id' => $target->id]);
                }
                Account::query()->where('default_cost_center_id', $center->id)->update(['default_cost_center_id' => $target->id]);
                foreach (Budget::query()->where('cost_center_id', $center->id)->get() as $budget) {
                    $existing = Budget::query()->where(['period_id' => $budget->period_id, 'cost_center_id' => $target->id, 'account_id' => $budget->account_id])->first();
                    $existing !== null ? $existing->increment('amount_cents', $budget->amount_cents) : $budget->update(['cost_center_id' => $target->id]);
                    $existing?->exists && $budget->delete();
                }
                foreach ($center->periods()->pluck('periods.id') as $periodId) {
                    $target->periods()->syncWithoutDetaching([$periodId]);
                }
            } else {
                JournalEntry::query()->whereHas('lines', fn ($q) => $q->where('cost_center_id', $center->id))->get()
                    ->each(function (JournalEntry $e) {
                        $e->lines()->delete();
                        $e->delete();
                    });
                foreach (['events', 'event_series', 'event_installments', 'membership_types'] as $table) {
                    DB::table($table)->where('cost_center_id', $center->id)->update(['cost_center_id' => null]);
                }
                Account::query()->where('default_cost_center_id', $center->id)->update(['default_cost_center_id' => null]);
            }
            AllocationRule::query()->where('source_cost_center_id', $center->id)->delete();
            Audit::record('cost_center.deleted', $center, null, ['moved_to' => $target?->name, 'bookings' => $info['count'], 'deleted_bookings' => $target === null]);
            $center->delete();
        });
    }

    /** "carries its result" ↔ "is allocated" (E1f.1). */
    public function setCarries(CostCenter $center, bool $carries, ?Fund $fund = null): void
    {
        if (! $carries) {
            if ($this->receivingIn($center) !== []) {
                throw AccountingException::because('receives_allocation');
            }
            if (! CostCenter::query()->whereKeyNot($center->id)->where('carries', true)->exists()) {
                throw AccountingException::because('one_must_carry');
            }
            $center->update(['carries' => false, 'fund_id' => null]);
        } else {
            AllocationRule::query()->where('source_cost_center_id', $center->id)->whereHas('period', fn ($q) => $q->where('status', 'open'))->delete();
            $center->update(['carries' => true, 'fund_id' => $fund->id ?? $center->fund_id ?? Fund::default()->id]);
        }
    }

    public function makeDefault(CostCenter $center): void
    {
        DB::transaction(function () use ($center) {
            CostCenter::query()->where('is_default', true)->update(['is_default' => false]);
            $center->update(['is_default' => true]);
        });
    }

    /**
     * Active or not in a period. Warnings when switching off: money left in its fund, an allocation it receives.
     *
     * @return list<string> warnings
     */
    public function setActive(CostCenter $center, Period $period, bool $active): array
    {
        if ($period->isClosed()) {
            throw AccountingException::because('closed', ['period' => $period->name]);
        }
        $warnings = [];
        if (! $active) {
            if ($center->is_default) {
                throw AccountingException::because('default_active');
            }
            $fund = $center->carries ? $center->resultFund() : null;
            if ($fund !== null && ! $fund->is_default && app(Balances::class)->fund($fund, $period->starts_on->copy()->subDay()) !== 0) {
                $warnings[] = (string) __('accounting.cost_centers.fund_has_money', ['fund' => $fund->name]);
            }
            if (AllocationRule::query()->where('period_id', $period->id)->where('target_cost_center_id', $center->id)->exists()) {
                $warnings[] = (string) __('accounting.cost_centers.in_key');
            }
            $period->costCenters()->detach($center->id);
        } else {
            $period->costCenters()->syncWithoutDetaching([$center->id]);
        }
        Audit::record($active ? 'cost_center.activated' : 'cost_center.deactivated', $center, null, ['period' => $period->name]);

        return $warnings;
    }
}
