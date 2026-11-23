<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $invoice_id
 * @property int $amount_cents
 * @property Carbon $paid_on
 * @property string $method
 * @property string|null $reference
 * @property string|null $payer_name
 * @property string|null $iban
 * @property string $match
 * @property int|null $suggested_invoice_id
 */
class Payment extends Model
{
    use Auditable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['paid_on' => 'date'];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<Invoice, $this> */
    public function suggestedInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'suggested_invoice_id');
    }
}
