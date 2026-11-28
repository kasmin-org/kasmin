<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Money handed to a person in advance (Vorschuss, E1d.3/E1f): in cash or by transfer; settled with receipts, the rest
 * is returned.
 *
 * @property int $id
 * @property int $person_id
 * @property-read Person $person the person always exists (deleting is restricted)
 * @property int $amount_cents
 * @property Carbon $paid_on
 * @property int $account_id
 * @property string|null $purpose
 * @property int $returned_cents
 * @property Carbon|null $settled_on
 * @property int|null $created_by_id
 */
class Advance extends Model
{
    use Auditable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['paid_on' => 'date', 'settled_on' => 'date'];
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

    /** @return HasMany<ExpenseClaim, $this> */
    public function claims(): HasMany
    {
        return $this->hasMany(ExpenseClaim::class);
    }

    /**
     * What the person still has to account for: handed out minus the receipts settled against it minus what came back,
     * plus what the organisation took over as an open expense when the receipts were more (negative while that is
     * not done yet).
     */
    public function openCents(): int
    {
        $receipts = (int) $this->claims()->where('advance_rest', false)->where('status', 'settled')->sum('amount_cents');
        $rest = (int) $this->claims()->where('advance_rest', true)->whereNot('status', 'rejected')->sum('amount_cents');

        return $this->amount_cents - $receipts - $this->returned_cents + $rest;
    }

    public function isSettled(): bool
    {
        return $this->settled_on !== null;
    }
}
