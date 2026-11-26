<?php

namespace App\Models;

use App\Casts\OneText;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $key
 * @property string|null $name
 * @property string|null $description
 * @property string $action
 * @property array<string, mixed>|null $config
 * @property string $trigger
 * @property array<string, mixed>|null $trigger_config
 * @property string $mode
 * @property list<string>|null $approver_roles
 * @property int $max_items
 * @property int|null $approvals_needed
 * @property bool $active
 * @property bool $is_standard
 * @property Carbon|null $last_run_at
 */
class Automation extends Model
{
    use Auditable;

    public const MODES = ['automatic', 'confirm', 'manual'];

    public const TRIGGERS = ['manual', 'daily', 'weekly', 'monthly', 'once', 'before_event', 'after_event', 'on_place_freed', 'on_status', 'on_group_joined', 'on_group_left', 'on_person_created', 'on_person_changed'];

    /**
     * Person and registration of the run that is planned or executed right now; not stored here.
     *
     * @var array<string, int>|null
     */
    public ?array $context = null;

    protected $guarded = ['id'];

    protected $attributes = ['mode' => 'confirm', 'max_items' => 200, 'active' => false, 'is_standard' => false];

    protected function casts(): array
    {
        return [
            'name' => OneText::class, 'description' => OneText::class, 'config' => 'array', 'trigger_config' => 'array', 'approver_roles' => 'array',
            'active' => 'boolean', 'is_standard' => 'boolean', 'last_run_at' => 'datetime',
        ];
    }

    /** @return HasMany<AutomationRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(AutomationRun::class);
    }

    public function label(?string $locale = null): string
    {
        return $this->name ?: $this->key;
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    public function triggerSetting(string $key, mixed $default = null): mixed
    {
        return $this->trigger_config[$key] ?? $default;
    }

    /** @return list<string> */
    public function approverRoles(): array
    {
        return $this->approver_roles ?: ['board', 'administrator'];
    }
}
