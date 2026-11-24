<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Price of a role for an age band and/or a period of registration (PR01).
 *
 * @property int $event_id
 * @property string $role_key
 * @property int|null $min_age
 * @property int|null $max_age
 * @property Carbon|null $from_date
 * @property Carbon|null $until_date
 * @property int $price_cents
 */
class EventPrice extends Model
{
    use Auditable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['from_date' => 'date', 'until_date' => 'date'];
    }

    public function applies(string $role, ?int $age, Carbon $registered): bool
    {
        $day = $registered->toDateString();

        return $this->role_key === $role
            && ($this->min_age === null || ($age !== null && $age >= $this->min_age))
            && ($this->max_age === null || ($age !== null && $age <= $this->max_age))
            && ($this->from_date === null || $day >= $this->from_date->toDateString())
            && ($this->until_date === null || $day <= $this->until_date->toDateString());
    }
}
