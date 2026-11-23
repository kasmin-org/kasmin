<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One-time login link and code. Only SHA-256 hashes are stored.
 *
 * @property int $id
 * @property int $user_id
 * @property string $purpose
 * @property string $scope
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 * @property int $failed_attempts
 */
class LoginToken extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isUsable(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture() && $this->failed_attempts < 5;
    }
}
