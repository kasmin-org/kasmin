<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property string $title
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property string $status
 * @property bool $registration_binding
 * @property Carbon|null $age_reference_date
 */
class Event extends Model
{
    use Auditable, HasUlids;

    public const STATUSES = ['draft', 'announced', 'open', 'closed', 'running', 'finished', 'archived'];

    protected $guarded = ['id', 'public_id'];

    /** Defaults like in the database, also before the model is reloaded. */
    protected $attributes = ['status' => 'draft', 'registration_binding' => true];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'registration_binding' => 'boolean', 'age_reference_date' => 'date'];
    }

    /** @return BelongsTo<EventSeries, $this> */
    public function series(): BelongsTo
    {
        return $this->belongsTo(EventSeries::class, 'event_series_id');
    }

    /** @return HasMany<EventRole, $this> */
    public function roles(): HasMany
    {
        return $this->hasMany(EventRole::class);
    }

    /** @return HasMany<Participation, $this> */
    public function participations(): HasMany
    {
        return $this->hasMany(Participation::class);
    }

    /** Date at which ages are calculated (EE04, default: event start). */
    public function ageReferenceDate(): Carbon
    {
        return $this->age_reference_date ?? $this->starts_at;
    }

    public function isOpenForRegistration(): bool
    {
        return $this->status === 'open';
    }
}
