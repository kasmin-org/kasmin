<?php

namespace App\Support\Accounting;

use App\Models\Account;
use App\Models\CostCenter;
use App\Models\Fund;
use App\Support\Settings;

/**
 * The standard chart of accounts, the cost centre "General" and the "General fund" (E1). Every installation gets them;
 * the names are in the language of the installation and can be changed. System accounts (receivables, open expenses,
 * funds, allocations …) are known to the code by an internal key that is never shown.
 */
class AccountingDefaults
{
    /** key => [type, money, system] in the order of the chart */
    public const ACCOUNTS = [
        'bank' => ['asset', 'bank', true], 'cash' => ['asset', 'cash', true], 'receivables' => ['asset', null, true], 'advances' => ['asset', null, true],
        'open_expenses' => ['liability', null, true], 'credits' => ['liability', null, true], 'payables' => ['liability', null, false],
        'funds' => ['equity', null, true],
        'membership_fees' => ['income', null, true], 'event_income' => ['income', null, true], 'donations' => ['income', null, true],
        'reminder_fees' => ['income', null, true], 'cancellation_fees' => ['income', null, true], 'interest' => ['income', null, false],
        'other_income' => ['income', null, true], 'discounts' => ['income', null, true],
        'accommodation' => ['expense', null, false], 'food' => ['expense', null, false], 'travel' => ['expense', null, false],
        'material' => ['expense', null, false], 'activities' => ['expense', null, false], 'team' => ['expense', null, false],
        'advertising' => ['expense', null, false], 'insurance' => ['expense', null, false], 'software' => ['expense', null, false],
        'postage' => ['expense', null, false], 'bank_fees' => ['expense', null, false], 'other_expenses' => ['expense', null, false],
        'allocations' => ['expense', null, true],
    ];

    /** Creates what is missing; names in the language of the installation. Safe to call again. */
    public static function install(): void
    {
        $previous = app()->getLocale();
        $locale = Settings::get('default_locale');
        if (in_array($locale, ['de', 'en'], true)) {
            app()->setLocale($locale);
        }
        try {
            $fund = Fund::query()->where('is_default', true)->first()
                ?? Fund::query()->create(['name' => self::free(Fund::class, __('accounting.defaults.fund')), 'is_default' => true]);
            if (! CostCenter::query()->where('is_default', true)->exists()) {
                CostCenter::query()->create(['name' => self::free(CostCenter::class, __('accounting.defaults.cost_center')), 'is_default' => true, 'carries' => true, 'fund_id' => $fund->id]);
            }
            // A new chart gets all suggestions; an existing one only the system accounts it misses.
            $fresh = ! Account::query()->exists();
            $sort = 0;
            foreach (self::ACCOUNTS as $key => [$type, $money, $system]) {
                $sort += 10;
                if (Account::query()->where('system_key', $key)->exists() || (! $system && ! $fresh)) {
                    continue;
                }
                Account::query()->create(['name' => self::free(Account::class, __('accounting.defaults.accounts.'.$key)), 'type' => $type, 'money' => $money, 'system_key' => $system ? $key : null, 'sort' => $sort]);
            }
        } finally {
            app()->setLocale($previous);
        }
    }

    /** While only the standard records exist, their names follow the language chosen at the installation. */
    public static function rename(): void
    {
        foreach (self::ACCOUNTS as $key => $definition) {
            foreach (['de', 'en'] as $from) {
                $old = trans('accounting.defaults.accounts.'.$key, [], $from);
                Account::query()->where('name', $old)->where('id', '>', 0)->update(['name' => __('accounting.defaults.accounts.'.$key)]);
            }
        }
        foreach ([[Fund::class, 'fund'], [CostCenter::class, 'cost_center']] as [$class, $text]) {
            foreach (['de', 'en'] as $from) {
                $class::query()->where('name', trans('accounting.defaults.'.$text, [], $from))->update(['name' => __('accounting.defaults.'.$text)]);
            }
        }
    }

    /** A name that is not taken yet (names are unique). */
    private static function free(string $class, string $name): string
    {
        $candidate = $name;
        for ($n = 2; $class::query()->where('name', $candidate)->exists(); $n++) {
            $candidate = $name.' '.$n;
        }

        return $candidate;
    }
}
