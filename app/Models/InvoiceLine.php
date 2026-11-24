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

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<Person, $this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
