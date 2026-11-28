<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A booking (E1): date, text, receipt and balanced lines. Numbers run over all years. Entries are never deleted but
 * reversed; the only exception are manual entries in open periods when a cost centre is deleted (E1e.6).
 *
 * @property int $id
 * @property int $number
 * @property Carbon $date
 * @property string $text
 * @property string|null $receipt
 * @property string|null $receipt_path
 * @property string $kind
 * @property string|null $source_type
 * @property int|null $source_id
 * @property int|null $reverses_id
 * @property int|null $period_closing_id
 * @property int|null $created_by_id
 */
class JournalEntry extends Model
{
    public const KINDS = ['manual', 'invoice', 'credit_note', 'payment', 'refund', 'credit', 'expense', 'expense_payout', 'donation', 'advance', 'advance_return', 'allocation', 'closing', 'fund_transfer', 'opening'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    /** @return HasMany<JournalLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_id');
    }

    /** @return HasOne<JournalEntry, $this> */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_id');
    }

    public function totalCents(): int
    {
        return (int) $this->lines->sum('debit_cents');
    }

    /** Made by Kasmin from a document or a closing, not typed in. */
    public function isAutomatic(): bool
    {
        return $this->kind !== 'manual';
    }
}
