<?php

namespace App\Models;

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

    /** @return BelongsTo<MailTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(MailTemplate::class, 'mail_template_id');
    }
}
