<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * From name/address and reply-to, bound to a mail account; templates choose a profile (MS03).
 *
 * @property int $id
 * @property string $name
 * @property string $from_name
 * @property string $from_address
 * @property string|null $reply_to
 * @property int $mail_account_id
 */
class SenderProfile extends Model
{
    use Auditable;

    protected $guarded = ['id'];

    /** @return BelongsTo<MailAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(MailAccount::class, 'mail_account_id');
    }

    /**
     * The sender of the installation – name, address and reply-to that every mail goes out with. There is one; if
     * older versions left several, it is the first one of the mail account.
     */
    public static function main(): ?self
    {
        $account = MailAccount::main();

        return ($account === null ? null : self::query()->where('mail_account_id', $account->id)->orderBy('id')->first()) ?? self::query()->orderBy('id')->first();
    }
}
