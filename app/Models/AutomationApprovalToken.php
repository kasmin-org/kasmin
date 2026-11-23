<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One-time, expiring approval link of one approver for one run (AT04). Only the hash is stored.
 *
 * @property int $id
 * @property int $automation_run_id
 * @property int $user_id
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 */
class AutomationApprovalToken extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime'];
    }

    /** @return BelongsTo<AutomationRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AutomationRun::class, 'automation_run_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
