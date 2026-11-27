<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of the allocation key of a period (E1d.8): how much of an allocated cost centre a receiving one bears.
 *
 * @property int $id
 * @property int $period_id
 * @property int $source_cost_center_id
 * @property int $target_cost_center_id
 * @property string $kind fixed, percent, share, rest
 * @property int $value cents (fixed) or basis points (percent)
 */
class AllocationRule extends Model
{
    use Auditable;

    public $timestamps = false;

    protected $guarded = ['id'];

    /** @return BelongsTo<CostCenter, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class, 'source_cost_center_id');
    }

    /** @return BelongsTo<CostCenter, $this> */
    public function target(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class, 'target_cost_center_id');
    }
}
