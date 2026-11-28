<?php

namespace App\Models;

use App\Casts\OneText;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasInternalKey;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $key
 * @property int $fee_cents
 * @property string|null $name
 */
class MembershipType extends Model
{
    use Auditable;
    use HasInternalKey;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['name' => OneText::class, 'pays_fee' => 'boolean'];
    }

    /** Own records never take a key that a later version might ship. */
    protected function internalKeyPrefix(): string
    {
        return 'own_';
    }

    public function label(): string
    {
        return $this->name ?: $this->key;
    }
}
