<?php

namespace App\Models;

use App\Casts\OneText;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $key
 * @property int $fee_cents
 * @property string|null $name
 */
class MembershipType extends Model
{
    use Auditable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['name' => OneText::class, 'pays_fee' => 'boolean'];
    }

    public function label(): string
    {
        return $this->name ?: $this->key;
    }
}
