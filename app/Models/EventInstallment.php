<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One instalment of an event's payment plan (PR03), e.g. deposit (fixed) and balance (rest).
 *
 * @property int $event_id
 * @property string $key
 * @property array<string, string> $label
 * @property string $kind
 * @property int|null $amount_cents
 * @property Carbon|null $due_date
 * @property int|null $due_days
 * @property bool $refundable
 * @property bool $discounts_apply
 */
class EventInstallment extends Model
{
    use Auditable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['label' => 'array', 'due_date' => 'date', 'refundable' => 'boolean', 'discounts_apply' => 'boolean'];
    }

    public function labelText(?string $locale = null): string
    {
        return $this->label[$locale ?? app()->getLocale()] ?? $this->label['en'] ?? $this->key;
    }
}
