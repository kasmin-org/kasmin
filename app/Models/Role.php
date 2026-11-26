<?php

namespace App\Models;

use App\Casts\OneText;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $key
 * @property string|null $name
 * @property bool $is_admin
 * @property array<string, string>|null $permissions
 * @property string|null $scope
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
        return ['name' => OneText::class, 'is_admin' => 'boolean', 'requires_second_factor' => 'boolean', 'permissions' => 'array'];
    }

    /** Counts only for the own events (RO01). Administrators are never restricted. */
    public function ownEventsOnly(): bool
    {
        return $this->scope === 'own' && $this->key !== 'administrator';
    }

    public function label(): string
    {
        return $this->name ?: $this->key;
    }
}
