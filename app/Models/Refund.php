<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Proposal to pay a credit back (RF02).
 *
 * @property int $id
 * @property int|null $household_id
 * @property int|null $payer_id
 * @property int|null $payment_id
 * @property int $amount_cents
 * @property string $currency
 * @property string|null $iban
 * @property string|null $payer_name
 * @property string $reason
 * @property string $status
 * @property Carbon|null $done_on
 */
class Refund extends Model
{
    use Auditable;

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'proposed', 'currency' => 'EUR'];

    protected function casts(): array
    {
        return ['done_on' => 'date'];
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return BelongsTo<Household, $this> */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }
}
