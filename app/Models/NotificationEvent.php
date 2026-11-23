<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Non-confidential event for admin notifications and digests (NT03: never confidential values).
 *
 * @property string $topic
 * @property string $summary
 * @property string|null $url
 * @property Carbon $created_at
 */
class NotificationEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
