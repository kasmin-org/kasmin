<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The planned result of a cost centre (or one of its accounts) in a period: income positive, costs negative.
 *
 * @property int $id
 * @property int $period_id
 * @property int $cost_center_id
 * @property int|null $account_id
 * @property int $amount_cents
 */
class Budget extends Model
{
    use Auditable;

    public $timestamps = false;

    protected $guarded = ['id'];

    /** @return BelongsTo<CostCenter, $this> */
    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
