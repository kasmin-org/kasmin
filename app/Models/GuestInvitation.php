<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Personal invitation of a participant's guest (PA05).
 *
 * @property int $id
 * @property int $participation_id
 * @property string|null $email
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 */
class GuestInvitation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime'];
    }

    /** @return BelongsTo<Participation, $this> */
    public function participation(): BelongsTo
    {
        return $this->belongsTo(Participation::class);
    }

    public function usable(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture() && $this->participation?->event?->isOpenForRegistration() === true;
    }
}
