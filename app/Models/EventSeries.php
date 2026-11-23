<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Events of the same kind over the years, e.g. the camp series or the alumni-event series (ET03).
 *
 * @property string $name
 * @property string $type
 */
class EventSeries extends Model
{
    use Auditable;

    protected $table = 'event_series';

    protected $guarded = ['id'];

    /** @return HasMany<Event, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function nextEvent(): ?Event
    {
        return $this->events()->where('ends_at', '>=', now())->orderBy('starts_at')->first();
    }
}
