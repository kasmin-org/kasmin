<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string|null $number
 * @property string $type
 * @property int|null $household_id
 * @property int|null $payer_id
 * @property int|null $event_id
 * @property string|null $installment_key
 * @property string $status
 * @property string $currency
 * @property int $total_cents
 * @property int $paid_cents
 * @property string|null $reference
 * @property Carbon|null $issued_on
 * @property Carbon|null $due_on
 * @property string|null $payer_name
 * @property string|null $payer_address
 * @property int|null $fee_for_id
 */
class Invoice extends Model
{
    use Auditable;

    public const STATUSES = ['draft', 'issued', 'partially_paid', 'paid', 'cancelled', 'corrected'];

    protected $guarded = ['id'];

    /** What an issued invoice says never changes; only status, payments and due date (IN10). */
    private const FIXED = ['number', 'type', 'household_id', 'payer_id', 'event_id', 'installment_key', 'currency', 'total_cents', 'reference', 'issued_on', 'payer_name', 'payer_address', 'corrects_id', 'fee_for_id'];

    protected static function booted(): void
    {
        static::updating(function (Invoice $invoice): void {
            if ($invoice->getOriginal('status') !== 'draft' && $invoice->isDirty(self::FIXED)) {
                throw new \RuntimeException('Issued invoices cannot be changed; use a correction.');
            }
        });
        static::deleting(function (Invoice $invoice): void {
            if ($invoice->status !== 'draft') {
                throw new \RuntimeException('Issued invoices cannot be deleted; cancel them.');
            }
        });
    }

    protected $attributes = ['status' => 'draft', 'type' => 'invoice', 'currency' => 'EUR', 'total_cents' => 0, 'paid_cents' => 0];

    protected function casts(): array
    {
        return ['issued_on' => 'date', 'due_on' => 'date'];
    }

    /** @return HasMany<InvoiceLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('sort');
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return BelongsTo<Household, $this> */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /** @return BelongsTo<Person, $this> */
    public function payer(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'payer_id');
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function openCents(): int
    {
        return $this->total_cents - $this->paid_cents;
    }

    public function isOverdue(): bool
    {
        return in_array($this->status, ['issued', 'partially_paid'], true) && $this->due_on !== null && $this->due_on->isPast();
    }

    public static function money(int $cents, string $currency = 'EUR'): string
    {
        return number_format($cents / 100, 2, ',', '.').' '.($currency === 'EUR' ? '€' : $currency);
    }
}
