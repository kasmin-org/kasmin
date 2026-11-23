<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * SMTP account for sending and optionally IMAP for copies, replies and bounces (MS02, MS03, MS06, ML04).
 *
 * @property int $id
 * @property string $name
 * @property string $smtp_host
 * @property int $smtp_port
 * @property string $smtp_encryption
 * @property string|null $smtp_username
 * @property string|null $smtp_password
 * @property string|null $imap_host
 * @property int|null $imap_port
 * @property string|null $imap_username
 * @property string|null $imap_password
 * @property string|null $imap_sent_folder
 * @property int|null $rate_per_minute
 * @property int|null $rate_per_hour
 * @property int|null $rate_per_day
 * @property bool $is_default
 */
class MailAccount extends Model
{
    use Auditable;

    protected $guarded = ['id'];

    protected $hidden = ['smtp_password', 'imap_password'];

    protected function casts(): array
    {
        return ['smtp_password' => 'encrypted', 'imap_password' => 'encrypted', 'is_default' => 'boolean', 'last_imap_check_at' => 'datetime'];
    }

    /** "send and receive" accounts have IMAP; only they can store copies and assign replies (ML04). */
    public function canReceive(): bool
    {
        return $this->imap_host !== null && $this->imap_host !== '';
    }
}
