<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One of several approvals of a run (RO03).
 *
 * @property int $automation_run_id
 * @property int $user_id
 * @property list<string>|null $excluded
 */
class AutomationApproval extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['excluded' => 'array'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
