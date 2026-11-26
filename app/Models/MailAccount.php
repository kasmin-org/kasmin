<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
 * @property string $delivery
 * @property string|null $dkim_domain
 * @property string|null $dkim_selector
 * @property string|null $dkim_private_key
 */
class MailAccount extends Model
{
    use Auditable;

    protected $guarded = ['id'];

    protected $hidden = ['smtp_password', 'imap_password', 'dkim_private_key'];

    protected function casts(): array
    {
        return ['smtp_password' => 'encrypted', 'imap_password' => 'encrypted', 'dkim_private_key' => 'encrypted', 'is_default' => 'boolean', 'last_imap_check_at' => 'datetime'];
    }

    /** "send and receive" accounts have IMAP; only they can store copies and assign replies (ML04). */
    /** @return HasMany<SenderProfile, $this> */
    public function senderProfiles(): HasMany
    {
        return $this->hasMany(SenderProfile::class);
    }

    public function isDirect(): bool
    {
        return $this->delivery === 'direct';
    }

    /**
     * Creates a new 2048-bit DKIM key pair and returns the DNS TXT record value to publish.
     */
    public function generateDkimKey(): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false || ! openssl_pkey_export($key, $private)) {
            throw new \RuntimeException('OpenSSL could not create a key.');
        }
        $this->forceFill(['dkim_private_key' => $private, 'dkim_selector' => $this->dkim_selector ?: 'kasmin'.now()->format('Ym')])->save();

        return $this->dkimRecord() ?? '';
    }

    /** DNS TXT record value for the public key, e.g. "v=DKIM1; k=rsa; p=…". */
    public function dkimRecord(): ?string
    {
        if (! $this->dkim_private_key) {
            return null;
        }
        $details = openssl_pkey_get_details(openssl_pkey_get_private($this->dkim_private_key) ?: throw new \RuntimeException('Invalid key'));
        $public = preg_replace('/-----[^-]+-----|\s/', '', (string) ($details['key'] ?? ''));

        return 'v=DKIM1; k=rsa; p='.$public;
    }

    public function canReceive(): bool
    {
        return $this->imap_host !== null && $this->imap_host !== '';
    }

    /** The mail account of the installation: there is one (the standard one, if older versions left several). */
    public static function main(): ?self
    {
        return self::query()->orderByDesc('is_default')->orderBy('id')->first();
    }
}
