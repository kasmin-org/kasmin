<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $key
 * @property array<string, string> $name
 * @property bool $is_admin
 * @property bool $requires_second_factor
 */
class Role extends Model
{
    use Auditable;

    /** Built-in roles; names and permissions are configurable. */
    public const BUILT_IN = ['administrator', 'board', 'treasurer', 'safety_officer', 'camp_leader', 'counsellor', 'auditor'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['name' => 'array', 'is_admin' => 'boolean', 'requires_second_factor' => 'boolean'];
    }

    public function label(): string
    {
        return $this->name[app()->getLocale()] ?? $this->name['en'] ?? $this->key;
    }
}
