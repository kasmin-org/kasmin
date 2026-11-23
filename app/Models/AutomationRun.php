<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One execution of an automation: the plan (what would happen), the decision and the result (AT03–AT08).
 *
 * @property int $id
 * @property int $automation_id
 * @property string|null $dedupe_key
 * @property string $status
 * @property string $trigger
 * @property int|null $event_id
 * @property list<array{key: string, label: string, details?: string|null}> $plan
 * @property list<string>|null $excluded
 * @property array<string, mixed>|null $result
 * @property string|null $error
 * @property Carbon|null $expires_at
 * @property Carbon|null $reminded_at
 * @property int|null $decided_by_id
 * @property Carbon|null $decided_at
 * @property string|null $comment
 * @property Carbon|null $executed_at
 */
class AutomationRun extends Model
{
    public const STATUSES = ['pending_approval', 'approved', 'denied', 'expired', 'done', 'failed'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'plan' => 'array', 'excluded' => 'array', 'result' => 'array', 'expires_at' => 'datetime', 'reminded_at' => 'datetime',
            'decided_at' => 'datetime', 'executed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Automation, $this> */
    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class);
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<User, $this> */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_id');
    }

    /** @return HasMany<AutomationApprovalToken, $this> */
    public function tokens(): HasMany
    {
        return $this->hasMany(AutomationApprovalToken::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending_approval' && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /** @return list<array{key: string, label: string, details?: string|null}> items that are not excluded */
    public function selectedItems(): array
    {
        $excluded = $this->excluded ?? [];

        return array_values(array_filter($this->plan, fn (array $item) => ! in_array($item['key'], $excluded, true)));
    }
}
