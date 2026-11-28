<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A cost centre (Kostenstelle, E1a/E1j): what a booking was for. One over all years, active or not per period. It
 * either carries its result into a fund or is allocated to others at the end of a period.
 *
 * @property int $id
 * @property string $name
 * @property bool $is_default
 * @property bool $carries
 * @property int|null $fund_id
 * @property int $sort
 */
class CostCenter extends Model
{
    use Auditable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'carries' => 'boolean'];
    }

    public static function default(): self
    {
        return self::query()->where('is_default', true)->firstOrFail();
    }

    /** @return BelongsTo<Fund, $this> */
    public function fund(): BelongsTo
    {
        return $this->belongsTo(Fund::class);
    }

    /** The fund its result goes into (the general fund if none is set); null if it is allocated. */
    public function resultFund(): ?Fund
    {
        return $this->carries ? ($this->fund ?? Fund::default()) : null;
    }

    /** @return BelongsToMany<Period, $this> */
    public function periods(): BelongsToMany
    {
        return $this->belongsToMany(Period::class);
    }
}
