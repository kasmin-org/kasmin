<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $event_id
 * @property string $label
 * @property string|null $category
 * @property int $amount_cents
 */
class EventCost extends Model
{
    use Auditable;

    public const CATEGORIES = ['accommodation', 'food', 'transport', 'material', 'staff', 'other'];

    protected $guarded = ['id'];

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
