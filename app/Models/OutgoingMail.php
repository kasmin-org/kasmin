<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Queue entry and mail log at the same time (MS01, ML01). Content is stored as sent.
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $sender_profile_id
 * @property int|null $person_id
 * @property int|null $household_id
 * @property int|null $mailing_id
 * @property string $to_address
 * @property string|null $to_name
 * @property string $subject
 * @property string $html
 * @property string $text
 * @property string $kind
 * @property string $status
 * @property int $attempts
 * @property bool $edited
 * @property Carbon|null $send_after
 * @property Carbon|null $sent_at
 * @property string|null $message_id
 */
class OutgoingMail extends Model
{
    use HasUuids;

    public const STATUSES = ['held', 'queued', 'sending', 'sent', 'failed', 'bounced', 'cancelled'];

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'queued', 'kind' => 'transactional', 'attempts' => 0, 'edited' => false];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return ['attachments' => 'array', 'edited' => 'boolean', 'send_after' => 'datetime', 'sent_at' => 'datetime', 'imap_copied_at' => 'datetime'];
    }

    /** @return BelongsTo<SenderProfile, $this> */
    public function senderProfile(): BelongsTo
    {
        return $this->belongsTo(SenderProfile::class);
    }

    /** @return BelongsTo<Person, $this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /** @return BelongsTo<Mailing, $this> */
    public function mailing(): BelongsTo
    {
        return $this->belongsTo(Mailing::class);
    }
}
