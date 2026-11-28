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
 * @property string|null $flat_rate name of the flat rate (claimed once per person and event)
 * @property bool $waived the person waives the refund already when submitting: a donation at the approval
 * @property bool $advance_rest what the organisation still owes after an advance was settled
 * @property Carbon $created_at
 */
class ExpenseClaim extends Model
{
    use Auditable;

    public const STATUSES = ['submitted', 'approved', 'rejected', 'paid', 'donated', 'settled'];

    /** Claims that wait for a decision, and those that wait for the payout. */
    public const OPEN = ['submitted', 'approved'];

    protected $guarded = ['id'];

    /** The IBAN copied at the payout is confidential: never in arrays, JSON or the audit log. */
    protected $hidden = ['iban'];

    protected function casts(): array
    {
        return ['date' => 'date', 'decided_at' => 'datetime', 'paid_on' => 'date', 'flat' => 'boolean', 'iban' => 'encrypted', 'waived' => 'boolean', 'advance_rest' => 'boolean'];
    }

    /** @return BelongsTo<User, $this> */
    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_id');
    }

    /** @return BelongsTo<User, $this> */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_id');
    }

    /** @return BelongsTo<Account, $this> */
    public function paidFrom(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'paid_from_account_id');
    }

    /** Entered by somebody else than the person (the treasurer), not submitted in the portal. */
    public function enteredCentrally(): bool
    {
        return $this->submitted_by_id !== null && $this->submittedBy?->person_id !== $this->person_id;
    }

    public function amount(): string
    {
        return Invoice::money($this->amount_cents);
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
