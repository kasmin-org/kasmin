<?php

namespace App\Models;

use App\Casts\OneText;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasInternalKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One instalment of an event's payment plan (PR03), e.g. deposit (fixed) and balance (rest).
 *
 * @property int $event_id
 * @property string $key
 * @property int|null $refund_until_days
 * @property int|null $membership_type_id
 * @property bool $confirms_place
 * @property int|null $paid_add_group_id
 * @property int|null $paid_remove_group_id
 * @property string|null $label
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
    use HasInternalKey;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['label' => OneText::class, 'due_date' => 'date', 'refundable' => 'boolean', 'discounts_apply' => 'boolean', 'confirms_place' => 'boolean'];
    }

    /** @return BelongsTo<MembershipType, $this> */
    public function membershipType(): BelongsTo
    {
        return $this->belongsTo(MembershipType::class);
    }

    protected function internalKeySource(): string
    {
        return (string) $this->label;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function internalKeyScope(Builder $query): Builder
    {
        return $query->where('event_id', $this->event_id);
    }

    protected function internalKeyLength(): int
    {
        return 32;
    }

    public function labelText(?string $locale = null): string
    {
        return $this->label ?: $this->key;
    }
}
