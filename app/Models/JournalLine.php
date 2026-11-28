<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A line of a booking: account, debit or credit, and for income and expenses the cost centre; for the equity the fund.
 *
 * @property int $id
 * @property int $journal_entry_id
 * @property int $account_id
 * @property int $debit_cents
 * @property int $credit_cents
 * @property int|null $cost_center_id
 * @property int|null $fund_id
 * @property int|null $person_id
 * @property string|null $text
 */
class JournalLine extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    /** @return BelongsTo<JournalEntry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<CostCenter, $this> */
    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    /** @return BelongsTo<Fund, $this> */
    public function fund(): BelongsTo
    {
        return $this->belongsTo(Fund::class);
    }

    /** @return BelongsTo<Person, $this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
