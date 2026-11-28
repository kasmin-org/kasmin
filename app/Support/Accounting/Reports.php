<?php

namespace App\Support\Accounting;

use App\Models\Account;
use App\Models\Budget;
use App\Models\CostCenter;
use App\Models\Fund;
use App\Models\JournalLine;
use App\Models\Period;
use App\Support\Lists\ListTable;
use Illuminate\Support\Carbon;

/**
 * Reports of the accounting (E1): balance sheet, income statement, cost centres with budget, statement of funds
 * ("Fondsspiegel", E1i), ledger of an account and open items. Every report is a ListTable, so it is shown on the page and
 * exported as Excel, CSV or PDF the same way. Amounts as text in the currency of the installation.
 */
class Reports
{
    public function __construct(private readonly Balances $balances) {}

    /** Assets, liabilities and equity (funds and the result not yet closed) on a day. */
    public function balanceSheet(Carbon $day): ListTable
    {
        $rows = [];
        $sum = ['asset' => 0, 'liability' => 0, 'equity' => 0];
        foreach (['asset', 'liability'] as $type) {
            $rows[] = [(string) __('accounting.types.'.$type), ''];
            foreach (Account::query()->where('type', $type)->orderBy('sort')->get() as $account) {
                $balance = $this->balances->account($account, $day) * ($type === 'asset' ? 1 : -1);
                if ($balance !== 0) {
                    $rows[] = ['  '.$account->label(), Money::format($balance)];
                    $sum[$type] += $balance;
                }
            }
            $rows[] = [(string) __('accounting.reports.total', ['what' => __('accounting.types.'.$type)]), Money::format($sum[$type])];
        }
        $rows[] = [(string) __('accounting.types.equity'), ''];
        foreach (Fund::query()->orderByDesc('is_default')->orderBy('sort')->get() as $fund) {
            $balance = $this->balances->fund($fund, $day);
            if ($balance !== 0) {
                $rows[] = ['  '.$fund->name, Money::format($balance)];
                $sum['equity'] += $balance;
            }
        }
        // Other equity accounts (e.g. one typed in for an opening): everything but the funds account, which the funds show.
        foreach (Account::query()->where('type', 'equity')->where(fn ($query) => $query->whereNull('system_key')->orWhere('system_key', '!=', 'funds'))->orderBy('sort')->get() as $account) {
            $balance = -$this->balances->account($account, $day);
            if ($balance !== 0) {
                $rows[] = ['  '.$account->label(), Money::format($balance)];
                $sum['equity'] += $balance;
            }
        }
        $open = $this->openResult($day);
        if ($open !== 0) {
            $rows[] = ['  '.__('accounting.reports.open_result'), Money::format($open)];
            $sum['equity'] += $open;
        }
        $rows[] = [(string) __('accounting.reports.total', ['what' => __('accounting.types.equity')]), Money::format($sum['equity'])];
        $rows[] = [(string) __('accounting.reports.check'), Money::format($sum['asset'] - $sum['liability'] - $sum['equity'])];

        return new ListTable((string) __('accounting.reports.balance_sheet', ['date' => $day->isoFormat('L')]), [(string) __('accounting.account'), (string) __('accounting.amount')], $rows);
    }

    /** Income minus expenses not yet closed into the funds up to a day. */
    public function openResult(Carbon $day): int
    {
        $q = JournalLine::query()->whereHas('account', fn ($a) => $a->whereIn('type', ['income', 'expense']))
            ->whereHas('entry', fn ($e) => $e->whereDate('date', '<=', $day->toDateString()));

        return (int) $q->sum('credit_cents') - (int) $q->sum('debit_cents');
    }

    /** Income and expenses per account in a period, optionally of one cost centre; internal allocations separate. */
    public function incomeStatement(Period $period, ?CostCenter $center = null): ListTable
    {
        $rows = [];
        $total = 0;
        foreach (['income', 'expense'] as $type) {
            $sum = 0;
            $rows[] = [(string) __('accounting.types.'.$type), ''];
            foreach (Account::query()->where('type', $type)->orderBy('sort')->get() as $account) {
                $q = JournalLine::query()->where('account_id', $account->id)->when($center !== null, fn ($q) => $q->where('cost_center_id', $center?->id))
                    ->whereHas('entry', fn ($e) => $e->whereBetween('date', [$period->starts_on->toDateString(), $period->ends_on->toDateString()])->where('kind', '!=', 'closing'));
                $value = (int) $q->sum('credit_cents') - (int) $q->sum('debit_cents');
                if ($value !== 0) {
                    $rows[] = ['  '.$account->label(), Money::format($type === 'income' ? $value : -$value)];
                    $sum += $value;
                }
            }
            $rows[] = [(string) __('accounting.reports.total', ['what' => __('accounting.types.'.$type)]), Money::format($type === 'income' ? $sum : -$sum)];
            $total += $sum;
        }
        $rows[] = [(string) __('accounting.reports.result'), Money::format($total)];

        return new ListTable((string) __('accounting.reports.income_statement', ['period' => $period->name]).($center !== null ? ' – '.$center->name : ''),
            [(string) __('accounting.account'), (string) __('accounting.amount')], $rows);
    }

    /** Per cost centre: income, expenses, allocation, result, budget and difference. */
    public function costCenters(Period $period): ListTable
    {
        $income = $this->balances->income($period);
        $withAllocation = $this->balances->results($period, true);
        $without = $this->balances->results($period, false);
        $rows = [];
        foreach (CostCenter::query()->orderBy('sort')->orderBy('name')->get() as $center) {
            $in = $income[$center->id] ?? 0;
            $result = $without[$center->id] ?? 0;
            $allocation = ($withAllocation[$center->id] ?? 0) - $result;
            $budget = Budget::query()->where('period_id', $period->id)->where('cost_center_id', $center->id)->sum('amount_cents');
            if ($in === 0 && $result === 0 && $allocation === 0 && (int) $budget === 0) {
                continue;
            }
            $rows[] = [$center->name, Money::format($in), Money::format($in - $result), $allocation === 0 ? '' : Money::format($allocation),
                Money::format($result + $allocation), (int) $budget === 0 ? '' : Money::format((int) $budget), (int) $budget === 0 ? '' : Money::format($result + $allocation - (int) $budget)];
        }

        return new ListTable((string) __('accounting.reports.cost_centers', ['period' => $period->name]), array_map('strval', [
            __('accounting.cost_center'), __('accounting.types.income'), __('accounting.types.expense'), __('accounting.closing.allocation'), __('accounting.reports.result'),
            __('accounting.reports.budget'), __('accounting.reports.difference'),
        ]), $rows);
    }

    /** Statement of funds (Fondsspiegel, E1i): opening, result, transfers, end – per fund. */
    public function funds(Period $period): ListTable
    {
        $rows = [];
        $funds = Account::system('funds');
        $preview = $period->isClosed() ? null : collect(app(Closing::class)->preview($period)['funds']);
        foreach (Fund::query()->orderByDesc('is_default')->orderBy('sort')->get() as $fund) {
            $opening = $this->balances->fund($fund, $period->starts_on->copy()->subDay());
            $lines = JournalLine::query()->where('account_id', $funds->id)->where('fund_id', $fund->id)
                ->whereHas('entry', fn ($e) => $e->whereBetween('date', [$period->starts_on->toDateString(), $period->ends_on->toDateString()]))->with('entry')->get();
            $result = (int) $lines->filter(fn (JournalLine $l) => $l->entry?->kind === 'closing')->sum(fn (JournalLine $l) => $l->credit_cents - $l->debit_cents);
            $transfers = (int) $lines->filter(fn (JournalLine $l) => $l->entry?->kind !== 'closing')->sum(fn (JournalLine $l) => $l->credit_cents - $l->debit_cents);
            if ($preview !== null) {
                // Not closed yet: the result as it would be closed now.
                $result = (int) ($preview->first(fn (array $row) => $row['fund']->is($fund))['result'] ?? 0);
            }
            if ($opening === 0 && $result === 0 && $transfers === 0) {
                continue;
            }
            $rows[] = [$fund->name, Money::format($opening), Money::format($result), Money::format($transfers), Money::format($opening + $result + $transfers)];
        }
        $title = (string) __('accounting.reports.funds', ['period' => $period->name]).($period->isClosed() ? '' : ' ('.__('accounting.reports.provisional').')');

        return new ListTable($title, array_map('strval', [__('accounting.fund'), __('accounting.reports.opening'), __('accounting.reports.result'), __('accounting.reports.transfers'), __('accounting.reports.end')]), $rows);
    }

    /** Ledger of one account: opening balance, every line with running balance. */
    public function ledger(Account $account, Carbon $from, Carbon $to): ListTable
    {
        $sign = in_array($account->type, ['asset', 'expense'], true) ? 1 : -1;
        $balance = $this->balances->account($account, $from->copy()->subDay()) * $sign;
        $rows = [['', '', (string) __('accounting.reports.opening'), '', '', Money::format($balance)]];
        $lines = JournalLine::query()->where('account_id', $account->id)
            ->whereHas('entry', fn ($e) => $e->whereBetween('date', [$from->toDateString(), $to->toDateString()]))->with('entry', 'costCenter')->get()
            ->sortBy(fn (JournalLine $l) => [$l->entry?->date->toDateString(), $l->entry?->number]);
        foreach ($lines as $line) {
            $balance += ($line->debit_cents - $line->credit_cents) * $sign;
            $rows[] = [(string) $line->entry?->number, (string) $line->entry?->date->isoFormat('L'), trim(($line->entry->text ?? '').' '.($line->text ?? '')).($line->costCenter !== null && Accounting::costCenters() ? ' · '.$line->costCenter->name : ''),
                $line->debit_cents ? Money::format($line->debit_cents) : '', $line->credit_cents ? Money::format($line->credit_cents) : '', Money::format($balance)];
        }

        return new ListTable((string) __('accounting.reports.ledger', ['account' => $account->label()]), array_map('strval', [__('accounting.number_short'), __('accounting.date'), __('accounting.text'), __('accounting.debit'), __('accounting.credit'), __('accounting.reports.balance')]), $rows);
    }

    /** What families owe (receivables) and what the organisation owes people (open expenses, credits), per person. */
    public function openItems(Carbon $day): ListTable
    {
        $rows = [];
        foreach (['receivables' => 1, 'open_expenses' => -1, 'credits' => -1, 'advances' => 1] as $key => $sign) {
            $account = Account::query()->where('system_key', $key)->first();
            if ($account === null) {
                continue;
            }
            $perPerson = JournalLine::query()->where('account_id', $account->id)->whereHas('entry', fn ($e) => $e->whereDate('date', '<=', $day->toDateString()))
                ->selectRaw('person_id, SUM(debit_cents) - SUM(credit_cents) AS balance')->groupBy('person_id')->with('person')->get();
            foreach ($perPerson as $row) {
                $balance = (int) $row->getAttribute('balance') * $sign;
                if ($balance !== 0) {
                    $rows[] = [$account->name, $row->person?->fullName() ?? '–', Money::format($balance)];
                }
            }
        }

        return new ListTable((string) __('accounting.reports.open_items', ['date' => $day->isoFormat('L')]), array_map('strval', [__('accounting.account'), __('accounting.reports.person'), __('accounting.amount')]), $rows, confidential: true);
    }
}
