<?php

namespace App\Models;

use App\Casts\OneText;
use App\Models\Concerns\Auditable;
use App\Support\Language;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * What a mail is about. The group decides which placeholders exist in the template: a mail to everybody knows no
     * invoice number. Recipient, salutation, family, children, organisation and links exist in every group.
     */
    public const GROUPS = ['general', 'event', 'registration', 'invoice', 'membership'];

    /** Placeholders a group has in addition to the general ones. */
    public const GROUP_FIELDS = ['general' => [], 'event' => ['event'], 'registration' => ['event', 'registration'], 'invoice' => ['invoice', 'event'], 'membership' => ['group']];

    /** The group of the standard templates; it is fixed for them. */
    public const STANDARD_GROUPS = [
        'registration_confirmation' => 'registration', 'application_welcome' => 'registration', 'round_offer' => 'registration', 'own_registration_notice' => 'registration',
        'waitlist_position' => 'registration', 'waitlist_offer' => 'registration', 'cancellation_confirmation' => 'registration', 'offer_expired' => 'registration',
        'safety_sheet_request' => 'event', 'safety_sheet_confirm' => 'event', 'safety_sheet_thanks' => 'event', 'safety_sheet_reminder' => 'event', 'event_information' => 'event',
        'invoice' => 'invoice', 'payment_reminder' => 'invoice', 'payment_overdue' => 'invoice', 'payment_confirmation' => 'invoice',
        'group_place_offer' => 'membership', 'guardianship_ended' => 'general', 'newsletter' => 'general',
    ];

    /** The group of own templates of older versions, from what was their category. */
    public const CATEGORY_GROUPS = ['registration' => 'registration', 'waitlist' => 'registration', 'invoice' => 'invoice', 'reminder' => 'event', 'safety_sheet' => 'event', 'event_information' => 'event', 'newsletter' => 'general', 'notification' => 'general', 'system' => 'general'];

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::saving(function (MailTemplate $template): void {
            $fixed = $template->is_standard ? (self::STANDARD_GROUPS[$template->key] ?? null) : null;
            $group = $fixed ?? $template->getAttribute('group') ?? self::CATEGORY_GROUPS[$template->category] ?? 'general';
            $template->setAttribute('group', in_array($group, self::GROUPS, true) ? $group : 'general');
        });
    }

    public function groupKey(): string
    {
        $group = (string) ($this->getAttribute('group') ?? '');

        return in_array($group, self::GROUPS, true) ? $group : (self::STANDARD_GROUPS[$this->key] ?? self::CATEGORY_GROUPS[$this->category] ?? 'general');
    }

    /** Templates that can be chosen for a mailing: about nothing (general) or, with an event, about the event. Never mails of the system. */
    /** @return Builder<MailTemplate> */
    public static function forMailings(bool $withEvent): Builder
    {
        return self::query()->where('active', true)->whereIn('group', $withEvent ? ['general', 'event'] : ['general'])->where('category', '!=', 'system');
    }

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
