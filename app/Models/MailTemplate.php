<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $key
 * @property string $category
 * @property array<string, string> $name
 * @property int|null $sender_profile_id
 * @property string $audience
 * @property bool $is_standard
 * @property int|null $standard_version
 * @property int $version
 */
class MailTemplate extends Model
{
    use Auditable;

    public const CATEGORIES = ['registration', 'waitlist', 'invoice', 'reminder', 'safety_sheet', 'event_information', 'newsletter', 'notification', 'system'];

    protected $guarded = ['id'];

    protected $attributes = ['version' => 1, 'audience' => 'guardians', 'active' => true];

    protected function casts(): array
    {
        return ['name' => 'array', 'is_standard' => 'boolean', 'active' => 'boolean'];
    }

    /** @return HasMany<MailTemplateContent, $this> */
    public function contents(): HasMany
    {
        return $this->hasMany(MailTemplateContent::class);
    }

    /** @return HasMany<MailTemplateVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(MailTemplateVersion::class)->orderByDesc('version');
    }

    /** @return BelongsTo<SenderProfile, $this> */
    public function senderProfile(): BelongsTo
    {
        return $this->belongsTo(SenderProfile::class);
    }

    public function label(): string
    {
        return $this->name[app()->getLocale()] ?? $this->name['en'] ?? $this->key;
    }

    public function content(string $locale): ?MailTemplateContent
    {
        return $this->contents->firstWhere('locale', $locale) ?? $this->contents->firstWhere('locale', 'en') ?? $this->contents->first();
    }
}
