<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $key
 * @property int $fee_cents
 * @property array<string, string> $name translations, e.g. ['de' => …, 'en' => …]
 */
class MembershipType extends Model
{
    use Auditable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['name' => 'array', 'pays_fee' => 'boolean'];
    }

    public function label(): string
    {
        return $this->name[app()->getLocale()] ?? $this->name['en'] ?? $this->key;
    }
}
