<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Support\Forms\SensitivityGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Configurable person form, e.g. the safety sheet or "general information" (SF01, FB06).
 *
 * @property int $id
 * @property string $key
 * @property array<string, string> $name
 * @property array<string, string>|null $description
 * @property int $version
 * @property string $kind
 * @property int|null $applies_until_age
 * @property int|null $confirm_every_days
 * @property bool $family_emergency_contacts
 */
class Form extends Model
{
    use Auditable;

    protected $guarded = ['id'];

    protected $attributes = ['version' => 1, 'active' => true, 'kind' => 'person'];

    protected function casts(): array
    {
        return ['name' => 'array', 'description' => 'array', 'family_emergency_contacts' => 'boolean', 'active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::updating(fn (Form $form) => SensitivityGuard::form($form));
        static::deleting(fn (Form $form) => SensitivityGuard::form($form, deleting: true));
    }

    /** @return HasMany<FormField, $this> */
    public function fields(): HasMany
    {
        return $this->hasMany(FormField::class)->orderBy('sort');
    }

    public function label(): string
    {
        return $this->name[app()->getLocale()] ?? $this->name['en'] ?? $this->key;
    }

    public function descriptionText(): ?string
    {
        return $this->description[app()->getLocale()] ?? $this->description['en'] ?? null;
    }
}
