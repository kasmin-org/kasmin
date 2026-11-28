<?php

namespace App\Filament\Support;

use App\Models\Account;
use App\Models\CostCenter;
use App\Support\Settings;
use Filament\Forms\Components\Select;

/**
 * The income account and the cost centre that invoices of an event, an instalment or a membership type are booked on
 * (E1). The cost centre is only shown while cost centres are used (E1e.5); empty means the next rule applies.
 */
class AccountingFields
{
    public static function revenueAccount(string $hint): Select
    {
        return Select::make('revenue_account_id')->label(__('accounting.fields.revenue_account'))
            ->options(fn () => Account::query()->where('type', 'income')->where('active', true)->where(fn ($q) => $q->whereNull('system_key')->orWhere('system_key', '!=', 'discounts'))
                ->orderBy('sort')->orderBy('name')->get()->mapWithKeys(fn (Account $a) => [$a->id => $a->label()])->all())
            ->searchable()->hintIcon('heroicon-m-question-mark-circle', tooltip: $hint);
    }

    public static function costCenter(string $hint): Select
    {
        return Select::make('cost_center_id')->label(__('accounting.fields.cost_center'))
            ->options(fn () => CostCenter::query()->orderBy('sort')->orderBy('name')->pluck('name', 'id')->all())
            ->searchable()->visible(fn () => Settings::bool('use_cost_centers'))->hintIcon('heroicon-m-question-mark-circle', tooltip: $hint);
    }
}
