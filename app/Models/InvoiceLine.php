<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $invoice_id
 * @property int|null $participation_id
 * @property int|null $person_id
 * @property string $description
 * @property int $amount_cents
 * @property int $discount_cents
 * @property string|null $discount_kind
 * @property string|null $discount_label
 * @property int|null $discount_position
 * @property int|null $membership_type_id
 * @property int|null $period
 */
class InvoiceLine extends Model
{
    protected $guarded = ['id'];

    /** Lines of issued invoices never change (IN10). */
    protected static function booted(): void
    {
        $guard = function (InvoiceLine $line): void {
            if ($line->invoice()->value('status') !== 'draft') {
                throw new \RuntimeException('Issued invoices cannot be changed; use a correction.');
            }
        };
        static::saving($guard);
        static::deleting($guard);
    }

    /** What is charged before the discount. */
    public function grossCents(): int
    {
        return $this->amount_cents + $this->discount_cents;
    }

    /** The discount as its own position (B10); false for lines made before, which show it in the same line. */
    public function hasOwnDiscountLine(): bool
    {
        return $this->discount_cents > 0 && $this->discount_label !== null;
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<Participation, $this> */
    public function participation(): BelongsTo
    {
        return $this->belongsTo(Participation::class);
    }

    /** @return BelongsTo<Person, $this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
