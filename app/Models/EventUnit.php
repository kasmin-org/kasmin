<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One group, room or duty shift of a division (EC04).
 *
 * @property int $id
 * @property int $event_division_id
 * @property string $name
 * @property int|null $capacity
 * @property string|null $note
 * @property int $sort
 */
class EventUnit extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['capacity' => 'integer'];
    }

    /** @return BelongsTo<EventDivision, $this> */
    public function division(): BelongsTo
    {
        return $this->belongsTo(EventDivision::class, 'event_division_id');
    }

    /** @return BelongsToMany<Participation, $this> */
    public function participations(): BelongsToMany
    {
        return $this->belongsToMany(Participation::class, 'event_unit_members')->withTimestamps();
    }
}
