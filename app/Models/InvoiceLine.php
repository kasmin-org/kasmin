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
 */
class InvoiceLine extends Model
{
    protected $guarded = ['id'];

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
