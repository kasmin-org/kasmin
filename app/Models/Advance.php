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
 * @property int $amount_cents
 * @property Carbon $paid_on
 * @property int $account_id
 * @property string|null $purpose
 * @property int $returned_cents
 * @property Carbon|null $settled_on
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

    /** What is still open: handed out minus receipts minus what came back. */
    public function openCents(): int
    {
        return $this->amount_cents - (int) $this->claims()->whereIn('status', ['approved', 'settled'])->sum('amount_cents') - $this->returned_cents;
    }
}
