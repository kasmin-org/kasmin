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
 * @property string|null $standard_hash
 * @property int $version
 * @property array<array-key, string>|null $attachments
 */
class MailTemplate extends Model
{
    use Auditable;

    public const CATEGORIES = ['registration', 'waitlist', 'invoice', 'reminder', 'safety_sheet', 'event_information', 'newsletter', 'notification', 'system'];

    protected $guarded = ['id'];

    protected $attributes = ['version' => 1, 'audience' => 'guardians', 'active' => true];

    protected function casts(): array
    {
        return ['attachments' => 'array', 'name' => 'array', 'is_standard' => 'boolean', 'active' => 'boolean'];
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

    /** Before the first change, so the original text can be restored, too. */
    public function keepFirstVersion(): void
    {
        if ($this->versions()->doesntExist()) {
            $this->versions()->create(['version' => $this->version, 'snapshot' => $this->snapshot(), 'changed_by_id' => null]);
        }
    }

    public function restoreVersion(MailTemplateVersion $version): void
    {
        $this->keepFirstVersion();
        $locales = [];
        foreach ((array) ($version->snapshot['contents'] ?? []) as $content) {
            $locales[] = $content['locale'];
            $this->contents()->updateOrCreate(['locale' => $content['locale']], ['subject' => $content['subject'], 'html' => $content['html'], 'text' => $content['text'] ?? null]);
        }
        $this->contents()->whereNotIn('locale', $locales)->delete();
        $this->newVersion();
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        return ['name' => $this->name, 'contents' => $this->contents()->get(['locale', 'subject', 'html', 'text'])->toArray()];
    }

    /** Every change is a new version with a snapshot for restoring (MT07). */
    public function newVersion(): void
    {
        $this->increment('version');
        $this->versions()->create([
            'version' => $this->version,
            'snapshot' => $this->snapshot(),
            'changed_by_id' => auth()->id(),
        ]);
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
