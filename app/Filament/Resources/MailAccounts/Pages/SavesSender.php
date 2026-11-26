<?php

namespace App\Filament\Resources\MailAccounts\Pages;

use App\Models\MailAccount;
use App\Models\SenderProfile;
use App\Support\Settings;

/**
 * The form shows the mail account and the sender together; the sender (name, address, reply-to) lives in its own
 * table. These helpers move the three fields between the form and that table.
 */
trait SavesSender
{
    /** @var array{from_name?: string, from_address?: string, reply_to?: string|null} */
    protected array $sender = [];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function takeSender(array $data): array
    {
        $this->sender = ['from_name' => (string) ($data['from_name'] ?? ''), 'from_address' => (string) ($data['from_address'] ?? ''), 'reply_to' => filled($data['reply_to'] ?? null) ? (string) $data['reply_to'] : null];
        unset($data['from_name'], $data['from_address'], $data['reply_to']);

        return $data + ['name' => 'Standard', 'is_default' => true];
    }

    protected function storeSender(MailAccount $account): void
    {
        $sender = SenderProfile::main() ?? new SenderProfile(['name' => 'Standard']);
        $sender->fill($this->sender + ['mail_account_id' => $account->id])->save();
        MailAccount::query()->whereKeyNot($account->id)->update(['is_default' => false]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function withSender(array $data): array
    {
        $sender = SenderProfile::main();

        return $data + ['from_name' => $sender->from_name ?? (string) Settings::get('organisation_name'), 'from_address' => $sender->from_address ?? (string) Settings::get('organisation_email'), 'reply_to' => $sender?->reply_to];
    }
}
