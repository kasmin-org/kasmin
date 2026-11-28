<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Builder;
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
 * @property int|null $installment_id
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
 * @property int|null $corrects_id
 */
class Invoice extends Model
{
    use Auditable;

    public const STATUSES = ['draft', 'issued', 'partially_paid', 'paid', 'cancelled', 'corrected'];

    protected $guarded = ['id'];

    /** What an issued invoice says never changes; only status, payments and due date (IN10). */
    private const FIXED = ['number', 'type', 'household_id', 'payer_id', 'event_id', 'installment_key', 'installment_id', 'currency', 'total_cents', 'reference', 'issued_on', 'payer_name', 'payer_address', 'corrects_id', 'fee_for_id'];

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

    /** @return BelongsTo<EventInstallment, $this> */
    public function installment(): BelongsTo
    {
        return $this->belongsTo(EventInstallment::class);
    }

    /**
     * The instalment of the payment plan the invoice is for: by its id; invoices of older versions whose instalment
     * was removed and made again are found by the key (B8). Null for single invoices and membership fees.
     */
    public function installmentOfPlan(): ?EventInstallment
    {
        if ($this->event_id === null) {
            return null;
        }
        if ($this->installment_id !== null) {
            return $this->installment;
        }

        return $this->installment_key !== null ? EventInstallment::query()->where('event_id', $this->event_id)->where('key', $this->installment_key)->first() : null;
    }

    /**
     * The same among instalments that are loaded already (the payment plan of the event).
     *
     * @param  iterable<EventInstallment>  $installments
     */
    public function installmentAmong(iterable $installments): ?EventInstallment
    {
        foreach ($installments as $installment) {
            if ($this->installment_id !== null ? $installment->getKey() === $this->installment_id : ($this->installment_key !== null && $installment->key === $this->installment_key)) {
                return $installment;
            }
        }

        return null;
    }

    /**
     * Whether the invoice shows its discounts in a column of the line, as invoices did before discounts were their
     * own position (B10): every invoice made before the update, with or without a discount – issued invoices never
     * change their look.
     */
    public function showsDiscountsInLine(): bool
    {
        return $this->lines->contains(fn (InvoiceLine $line) => $line->discount_cents > 0 && $line->discount_label === null)
            || $this->id < Settings::int('invoice_discount_lines_from');
    }

    /** What the invoice is for, as it is shown: the name of the instalment, or the membership fee of a year. */
    public function installmentName(): ?string
    {
        if (preg_match('/^membership-(\d{4})$/', (string) $this->installment_key, $year) === 1) {
            return (string) __('finance.membership_fee', ['year' => $year[1]]);
        }
        $installment = $this->event !== null && $this->event->relationLoaded('installments') ? $this->installmentAmong($this->event->installments) : $this->installmentOfPlan();

        return $installment?->labelText();
    }

    /**
     * Invoices for this instalment; those of older versions without the id by its key.
     *
     * @param  Builder<Invoice>  $query
     */
    public function scopeForInstallment(Builder $query, EventInstallment $installment): void
    {
        $query->where('event_id', $installment->event_id)
            ->where(fn (Builder $q) => $q->where('installment_id', $installment->getKey())->orWhere(fn (Builder $q) => $q->whereNull('installment_id')->where('installment_key', $installment->key)));
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
