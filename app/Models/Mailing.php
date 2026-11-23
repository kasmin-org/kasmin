<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property int $mail_template_id
 * @property int|null $sender_profile_id
 * @property array{group_ids?: list<int>, event_id?: int|null, statuses?: list<string>, person_ids?: list<int>} $recipients
 * @property string $routing
 * @property bool $one_per_family
 * @property bool $edit_before_sending
 * @property string $status
 */
class Mailing extends Model
{
    use Auditable;

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'draft', 'routing' => 'guardians', 'one_per_family' => true, 'edit_before_sending' => false];

    protected function casts(): array
    {
        return ['recipients' => 'array', 'one_per_family' => 'boolean', 'edit_before_sending' => 'boolean', 'scheduled_at' => 'datetime', 'prepared_at' => 'datetime', 'sent_at' => 'datetime'];
    }

    /** @return BelongsTo<MailTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(MailTemplate::class, 'mail_template_id');
    }

    /** @return HasMany<OutgoingMail, $this> */
    public function mails(): HasMany
    {
        return $this->hasMany(OutgoingMail::class);
    }
}
