<?php

namespace App\Support\Accounting;

use App\Models\CostCenter;
use App\Models\Fund;
use App\Models\JournalLine;
use App\Models\Period;
use App\Support\Settings;

/**
 * Switches of the accounting (E1e.5, E1f.1, E1h.2): "use cost centres" and "use funds". Internally every booking always
 * has a cost centre and every fund line a fund; the switches only show or hide them.
 */
final class Accounting
{
    public static function costCenters(): bool
    {
        return Settings::bool('use_cost_centers');
    }

    public static function funds(): bool
    {
        return self::costCenters() && Settings::bool('use_funds');
    }

    /** Why "use cost centres" cannot be switched off now, or null. */
    public static function whyCostCentersStay(): ?string
    {
        if (self::funds()) {
            return (string) __('accounting.switch.funds_first');
        }
        if (CostCenter::query()->count() > 1) {
            return (string) __('accounting.switch.one_cost_center');
        }
        if (CostCenter::query()->where('carries', false)->exists()) {
            return (string) __('accounting.switch.must_carry');
        }

        return null;
    }

    /** Why "use funds" cannot be switched off now, or null. */
    public static function whyFundsStay(): ?string
    {
        return Fund::query()->count() > 1 ? (string) __('accounting.switch.one_fund') : null;
    }

    /** Whether the accounting has started (a first period exists). */
    public static function started(): bool
    {
        return Period::query()->exists();
    }

    /** Whether there are lines for a cost centre in closed periods (they cannot be moved without reopening). */
    public static function hasClosedLines(CostCenter $center): bool
    {
        return JournalLine::query()->where('cost_center_id', $center->id)->whereHas('entry', function ($q) {
            $q->whereExists(fn ($p) => $p->selectRaw('1')->from('periods')->where('periods.status', 'closed')
                ->whereColumn('periods.starts_on', '<=', 'journal_entries.date')->whereColumn('periods.ends_on', '>=', 'journal_entries.date'));
        })->exists();
    }
}
