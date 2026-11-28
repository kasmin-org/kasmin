<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An account of the chart of accounts (E1): what was booked. Bank and cash accounts are where the money is.
 *
 * @property int $id
 * @property string|null $number
 * @property string $name
 * @property string $type asset, liability, equity, income, expense
 * @property string|null $money bank, cash
 * @property string|null $iban
 * @property int|null $default_cost_center_id
 * @property string|null $system_key
 * @property bool $active
 * @property int $sort
 */
class Account extends Model
{
    use Auditable;

    public const TYPES = ['asset', 'liability', 'equity', 'income', 'expense'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    /** Income and expense accounts: their lines need a cost centre and are closed at the end of a period. */
    public function isResult(): bool
    {
        return in_array($this->type, ['income', 'expense'], true);
    }

    public static function system(string $key): self
    {
        return self::query()->where('system_key', $key)->firstOrFail();
    }

    public function label(): string
    {
        return trim(($this->number !== null && $this->number !== '' ? $this->number.' ' : '').$this->name);
    }

    /** @return BelongsTo<CostCenter, $this> */
    public function defaultCostCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class, 'default_cost_center_id');
    }

    /** @return HasMany<JournalLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    /** @return HasMany<Fund, $this> */
    public function funds(): HasMany
    {
        return $this->hasMany(Fund::class);
    }
}
