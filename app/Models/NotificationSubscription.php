<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $user_id
 * @property string $topic
 * @property string $frequency
 * @property Carbon|null $last_sent_at
 */
class NotificationSubscription extends Model
{
    public const TOPICS = ['registrations', 'profile_updates', 'safety_updates', 'waitlist', 'payments', 'bounces', 'approvals', 'deletion_requests', 'automation_errors', 'tasks'];

    public const FREQUENCIES = ['immediate', 'daily', 'weekly', 'off'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['last_sent_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
