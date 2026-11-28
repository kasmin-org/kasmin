<?php

namespace App\Support\Accounting;

use App\Models\Account;
use App\Models\CostCenter;
use App\Models\Fund;
use App\Models\JournalEntry;
use App\Models\Period;
use App\Support\Audit\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Books entries (E1): balanced lines, the period of the date must be open, income and expenses get a cost centre (the
 * one given, else the account's standard one, else the standard cost centre), the equity account of the funds gets a
 * fund. Numbers run over all years. Entries are reversed, never changed.
 */
class Journal
{
    public function __construct(private readonly Periods $periods) {}

    /**
     * @param  array{date: \DateTimeInterface|string, text: string, receipt?: string|null, receipt_path?: string|null, kind?: string, source?: Model|null, period_closing_id?: int|null, created_by_id?: int|null}  $entry
     * @param  list<array{account: Account|string|int, debit?: int, credit?: int, cost_center?: CostCenter|int|null, fund?: Fund|int|null, person_id?: int|null, text?: string|null}>  $lines
     */
    public function post(array $entry, array $lines, bool $closing = false): JournalEntry
    {
        $kind = $entry['kind'] ?? 'manual';
        $date = Carbon::parse($entry['date'])->startOfDay();
        $source = $entry['source'] ?? null;
        if ($source !== null) {
            $existing = JournalEntry::query()->where('kind', $kind)->where('source_type', $source->getMorphClass())->where('source_id', $source->getKey())
                ->whereDoesntHave('reversal')->first();
            if ($existing !== null) {
                return $existing; // already booked (documents are booked once)
            }
        }

        return DB::transaction(function () use ($entry, $lines, $kind, $date, $source, $closing) {
            $period = $kind === 'manual' ? Period::of($date) : $this->periods->ensureFor($date);
            if ($period === null) {
                throw AccountingException::because('no_period', ['date' => $date->isoFormat('L')]);
            }
            if ($period->isClosed() && ! $closing) {
                throw AccountingException::because('closed', ['period' => $period->name]);
            }
            $rows = $this->lines($lines, $period, $kind === 'manual');
            $debit = array_sum(array_column($rows, 'debit_cents'));
            $credit = array_sum(array_column($rows, 'credit_cents'));
            if (count($rows) < 2 || $debit !== $credit || $debit <= 0) {
                throw AccountingException::because('unbalanced', ['debit' => Money::format($debit), 'credit' => Money::format($credit)]);
            }
            $number = (int) JournalEntry::query()->lockForUpdate()->max('number') + 1;
            $model = JournalEntry::query()->create([
                'number' => $number, 'date' => $date->toDateString(), 'text' => mb_substr(trim($entry['text']), 0, 250),
                'receipt' => $entry['receipt'] ?? null, 'receipt_path' => $entry['receipt_path'] ?? null, 'kind' => $kind,
                'source_type' => $source?->getMorphClass(), 'source_id' => $source?->getKey(),
                'period_closing_id' => $entry['period_closing_id'] ?? null, 'created_by_id' => $entry['created_by_id'] ?? auth()->id(),
            ]);
            foreach ($rows as $row) {
                $model->lines()->create($row);
            }
            if ($kind === 'manual') {
                Audit::record('journal.posted', $model, null, ['number' => $number, 'total' => $debit]);
            }

            return $model->load('lines');
        });
    }

    /** A counter-entry with the opposite sides; dated on the original date while its period is open, else today. */
    public function reverse(JournalEntry $entry, ?string $text = null, \DateTimeInterface|string|null $date = null): JournalEntry
    {
        if ($entry->reverses_id !== null || $entry->reversal()->exists()) {
            throw AccountingException::because('reversed');
        }
        $original = Period::of($entry->date);
        $date ??= $original !== null && ! $original->isClosed() ? $entry->date : now();
        $lines = [];
        foreach ($entry->lines as $l) {
            $lines[] = ['account' => $l->account_id, 'debit' => $l->credit_cents, 'credit' => $l->debit_cents, 'cost_center' => $l->cost_center_id,
                'fund' => $l->fund_id, 'person_id' => $l->person_id, 'text' => $l->text];
        }
        $reversal = DB::transaction(function () use ($entry, $text, $date, $lines) {
            $reversal = $this->post(['date' => $date, 'text' => $text ?? __('accounting.reversal_of', ['number' => $entry->number, 'text' => $entry->text]),
                'kind' => $entry->kind === 'manual' ? 'manual' : $entry->kind], $lines);
            $reversal->forceFill(['reverses_id' => $entry->id])->save();

            return $reversal;
        });
        Audit::record('journal.reversed', $entry, null, ['reversal' => $reversal->number]);

        return $reversal;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array{account_id: int, debit_cents: int, credit_cents: int, cost_center_id: int|null, fund_id: int|null, person_id: int|null, text: string|null}>
     */
    private function lines(array $lines, Period $period, bool $manual): array
    {
        $rows = [];
        foreach ($lines as $line) {
            $account = $line['account'] instanceof Account ? $line['account'] : (is_int($line['account']) || ctype_digit((string) $line['account'])
                ? Account::query()->find((int) $line['account']) : Account::query()->where('system_key', $line['account'])->first());
            if ($account === null || ($manual && ! $account->active)) {
                throw AccountingException::because('account');
            }
            $debit = max(0, (int) ($line['debit'] ?? 0));
            $credit = max(0, (int) ($line['credit'] ?? 0));
            if (($debit > 0) === ($credit > 0)) {
                if ($debit === 0 && $credit === 0) {
                    continue; // empty lines are left out
                }
                throw AccountingException::because('both_sides');
            }
            $costCenter = null;
            if ($account->isResult()) {
                $id = $line['cost_center'] ?? null;
                $costCenter = $id instanceof CostCenter ? $id : ($id !== null ? CostCenter::query()->find((int) $id) : null);
                $costCenter ??= $account->defaultCostCenter ?? CostCenter::default();
                if (! $period->costCenters()->whereKey($costCenter->id)->exists()) {
                    if ($manual) {
                        throw AccountingException::because('inactive_cost_center', ['name' => $costCenter->name, 'period' => $period->name]);
                    }
                    $period->costCenters()->syncWithoutDetaching([$costCenter->id]); // a document of a project brings it back into the period
                }
            }
            $fund = null;
            if ($account->system_key === 'funds') {
                $id = $line['fund'] ?? null;
                $fund = $id instanceof Fund ? $id : ($id !== null ? Fund::query()->find((int) $id) : null);
                $fund ??= Fund::default();
            }
            $rows[] = [
                'account_id' => $account->id, 'debit_cents' => $debit, 'credit_cents' => $credit, 'cost_center_id' => $costCenter?->id,
                'fund_id' => $fund?->id, 'person_id' => isset($line['person_id']) ? (int) $line['person_id'] : null,
                'text' => isset($line['text']) ? mb_substr((string) $line['text'], 0, 250) : null,
            ];
        }

        return $rows;
    }
}
