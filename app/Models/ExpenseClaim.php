<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An expense somebody paid for the organisation and gets back (Auslage, E1d.3): submitted in the portal or entered by
 * the treasurer, approved, then paid out (or given as a donation).
 *
 * @property int $id
 * @property int $person_id
 * @property int|null $submitted_by_id
 * @property Carbon $date
 * @property int $amount_cents
 * @property string $purpose
 * @property int|null $account_id
 * @property int|null $cost_center_id
 * @property int|null $event_id
 * @property string|null $receipt_path
 * @property bool $flat
 * @property string $status submitted, approved, rejected, paid, donated, settled
 * @property string|null $iban
 * @property string|null $account_holder
 * @property int|null $advance_id
 * @property int|null $decided_by_id
 * @property Carbon|null $decided_at
 * @property string|null $reason
 * @property int|null $paid_from_account_id
 * @property Carbon|null $paid_on
 */
class ExpenseClaim extends Model
{
    use Auditable;

    public const STATUSES = ['submitted', 'approved', 'rejected', 'paid', 'donated', 'settled'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'decided_at' => 'datetime', 'paid_on' => 'date', 'flat' => 'boolean', 'iban' => 'encrypted'];
    }

    /** @return BelongsTo<Person, $this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
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

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<Advance, $this> */
    public function advance(): BelongsTo
    {
        return $this->belongsTo(Advance::class);
    }
}
