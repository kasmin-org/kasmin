<?php

namespace App\Support\Accounting;

use App\Models\Account;
use App\Models\Advance;
use App\Models\Person;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Advances (Vorschüsse, E1d.3): money handed to a person before, in cash or by transfer (advances of the person to
 * the money account). The receipts are submitted as expense claims linked to the advance and booked against it; at
 * the settlement the rest comes back (money account to advances), and what the receipts are more becomes an open
 * expense of the person.
 */
class Advances
{
    public function __construct(private readonly Journal $journal, private readonly ExpenseClaims $claims) {}

    public function handOut(Person $person, int $cents, Account $from, \DateTimeInterface|string $on, ?string $purpose = null, ?User $by = null): Advance
    {
        if ($cents <= 0) {
            throw AccountingException::because('expense_amount');
        }
        $this->moneyAccount($from);
        $date = Carbon::parse($on)->startOfDay();

        return DB::transaction(function () use ($person, $cents, $from, $date, $purpose, $by) {
            $advance = Advance::query()->create([
                'person_id' => $person->id, 'amount_cents' => $cents, 'paid_on' => $date->toDateString(), 'account_id' => $from->id,
                'purpose' => $purpose !== null && trim($purpose) !== '' ? mb_substr(trim($purpose), 0, 250) : null, 'created_by_id' => $by?->id,
            ]);
            $this->journal->post($this->entry($advance, 'advance', $date, 'advance', $by), [
                ['account' => 'advances', 'debit' => $cents, 'person_id' => $person->id],
                ['account' => $from, 'credit' => $cents],
            ]);

            return $advance;
        });
    }

    /**
     * Settles an advance once all its receipts are decided: what comes back is booked into the money account; if the
     * receipts are more than the advance, the difference becomes an approved claim of the person. Nothing may stay
     * open – the person returns the whole rest.
     */
    public function settle(Advance $advance, int $returned, ?Account $into, \DateTimeInterface|string $on, ?User $by = null): Advance
    {
        if ($advance->isSettled()) {
            throw AccountingException::because('advance_closed');
        }
        if ($advance->claims()->where('status', 'submitted')->exists()) {
            throw AccountingException::because('advance_undecided');
        }
        $open = $advance->openCents();
        if ($returned < 0 || $returned !== max(0, $open)) {
            throw AccountingException::because('advance_rest', ['amount' => Money::format(max(0, $open))]);
        }
        if ($returned > 0) {
            $this->moneyAccount($into);
        }
        $date = Carbon::parse($on)->startOfDay();

        return DB::transaction(function () use ($advance, $returned, $into, $date, $open, $by) {
            if ($returned > 0 && $into !== null) {
                $this->journal->post($this->entry($advance, 'advance_return', $date, 'advance_return', $by), [
                    ['account' => $into, 'debit' => $returned],
                    ['account' => 'advances', 'credit' => $returned, 'person_id' => $advance->person_id],
                ]);
            }
            if ($open < 0) {
                $this->claims->restOf($advance, -$open, $date, $by);
            }
            $advance->forceFill(['returned_cents' => $advance->returned_cents + $returned, 'settled_on' => $date->toDateString()])->save();

            return $advance;
        });
    }

    private function moneyAccount(?Account $account): void
    {
        if ($account === null || $account->money === null || ! $account->active) {
            throw AccountingException::because('money_account');
        }
    }

    /** @return array{date: Carbon, text: string, kind: string, source: Advance, created_by_id: int|null} */
    private function entry(Advance $advance, string $kind, Carbon $date, string $text, ?User $by): array
    {
        return [
            'date' => $date, 'text' => (string) __('accounting.expenses.entry.'.$text, ['name' => $advance->person->fullName(), 'purpose' => (string) $advance->purpose]),
            'kind' => $kind, 'source' => $advance, 'created_by_id' => $by->id ?? (auth()->id() !== null ? (int) auth()->id() : null),
        ];
    }
}
