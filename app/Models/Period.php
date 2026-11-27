<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A period of the accounting (Buchungsperiode, E1d.1): a date range. Bookings belong to it by their date, so changing
 * the dates of an open period moves them. Closed periods are locked.
 *
 * @property int $id
 * @property string $name
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property string $status open, closed
 * @property Carbon|null $closed_at
 * @property int|null $closed_by_id
 * @property string|null $reopened_reason
 */
class Period extends Model
{
    use Auditable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'closed_at' => 'datetime'];
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    public static function of(\DateTimeInterface|string $date): ?self
    {
        $day = Carbon::parse($date)->toDateString();

        return self::query()->whereDate('starts_on', '<=', $day)->whereDate('ends_on', '>=', $day)->first();
    }

    /** @return BelongsToMany<CostCenter, $this> active cost centres */
    public function costCenters(): BelongsToMany
    {
        return $this->belongsToMany(CostCenter::class);
    }

    /** @return HasMany<AllocationRule, $this> */
    public function allocationRules(): HasMany
    {
        return $this->hasMany(AllocationRule::class);
    }

    /** @return HasMany<Budget, $this> */
    public function budgets(): HasMany
    {
        return $this->hasMany(Budget::class);
    }
}
