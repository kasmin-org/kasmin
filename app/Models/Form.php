<?php

namespace App\Models;

use App\Casts\OneText;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasInternalKey;
use App\Support\Forms\SensitivityGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Configurable person form, e.g. the safety sheet or "general information" (SF01, FB06).
 *
 * @property int $id
 * @property string $key
 * @property string|null $name
 * @property string|null $description
 * @property int $version
 * @property string $kind
 * @property int|null $applies_until_age
 * @property int|null $confirm_every_days
 * @property bool $family_emergency_contacts
 */
class Form extends Model
{
    use Auditable;
    use HasInternalKey;

    protected $guarded = ['id'];

    protected $attributes = ['version' => 1, 'active' => true, 'kind' => 'person'];

    protected function casts(): array
    {
        return ['name' => OneText::class, 'description' => OneText::class, 'family_emergency_contacts' => 'boolean', 'active' => 'boolean'];
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

    /** Own records never take a key that a later version might ship. */
    protected function internalKeyPrefix(): string
    {
        return 'own_';
    }

    public function label(): string
    {
        return $this->name ?: $this->key;
    }

    public function descriptionText(): ?string
    {
        return $this->description ?: null;
    }
}
