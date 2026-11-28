<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A fund (Fonds, also called reserve or "Rücklage"; thought of as a pot, E1e/E1g): a part of the equity that carries
 * the results of its cost centres over the years. Optionally compared with a bank or cash account.
 *
 * @property int $id
 * @property string $name
 * @property bool $is_default
 * @property int|null $account_id
 * @property string|null $note
 * @property int $sort
 */
class Fund extends Model
{
    use Auditable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    public static function default(): self
    {
        return self::query()->where('is_default', true)->firstOrFail();
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return HasMany<CostCenter, $this> */
    public function costCenters(): HasMany
    {
        return $this->hasMany(CostCenter::class);
    }
}
