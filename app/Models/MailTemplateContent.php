<?php

namespace App\Models;

use App\Support\Language;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $mail_template_id
 * @property string $locale
 * @property string $subject
 * @property string $html
 * @property string|null $text
 */
class MailTemplateContent extends Model
{
    protected $guarded = ['id'];

    /**
     * A template has one text. The standard templates of the software come in both languages, and backups or version
     * snapshots of the versions up to 0.27 hold two: of several, the one in the language of the installation stays.
     */
    protected static function booted(): void
    {
        static::creating(function (MailTemplateContent $content): bool {
            $others = self::query()->where('mail_template_id', $content->mail_template_id);
            if (! $others->exists()) {
                return true;
            }
            if ($content->locale !== Language::current()) {
                return false; // the template already has its text
            }
            $others->delete();

            return true;
        });
    }

    /** @return BelongsTo<MailTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(MailTemplate::class, 'mail_template_id');
    }
}
