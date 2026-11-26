<?php

namespace App\Models;

use App\Casts\OneText;
use App\Models\Concerns\Auditable;
use App\Support\Language;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $key
 * @property string $category
 * @property string|null $name
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
        return ['attachments' => 'array', 'name' => OneText::class, 'is_standard' => 'boolean', 'active' => 'boolean'];
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

    /** Writes the one text of the template. */
    public function setContent(string $subject, string $html, ?string $text = null): MailTemplateContent
    {
        $content = $this->contents()->first() ?? $this->contents()->make(['locale' => Language::current()]);
        $content->fill(['subject' => $subject, 'html' => $html, 'text' => $text])->save();
        $this->contents()->whereKeyNot($content->getKey())->delete();
        $this->unsetRelation('contents');

        return $content;
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
        // Snapshots of the versions up to 0.27 hold one text per language: the one of the text in use is restored.
        $contents = collect((array) ($version->snapshot['contents'] ?? []));
        $wanted = $this->contents()->value('locale') ?? Language::current();
        $content = $contents->firstWhere('locale', $wanted) ?? $contents->firstWhere('locale', Language::current()) ?? $contents->first();
        if (is_array($content)) {
            $this->setContent((string) $content['subject'], (string) $content['html'], $content['text'] ?? null);
        }
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
        return $this->name ?: $this->key;
    }

    /**
     * The text of the template. There is one; the language is that of the installation at the time it was written.
     * (Up to 0.27 a template had one text per language; the argument is still accepted and has no effect.)
     */
    public function content(?string $locale = null): ?MailTemplateContent
    {
        return $this->contents->first();
    }
}
