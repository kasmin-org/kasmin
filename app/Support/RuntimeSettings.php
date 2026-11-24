<?php

namespace App\Support;

use App\Models\MailAccount;
use App\Support\Schema\SchemaGuard;
use App\Support\Schema\SchemaStatus;
use Illuminate\Support\Facades\URL;

/**
 * Applies settings from the database to the running application: public URL, digest time, approval days, and the default
 * mail account for mails sent directly (login links). The database wins over environment variables (DK02).
 */
class RuntimeSettings
{
    public static function apply(): void
    {
        try {
            if (app(SchemaGuard::class)->status() !== SchemaStatus::Ok) {
                return;
            }
            $url = Settings::get('public_url');
            if ($url !== null && $url !== '') {
                config(['app.url' => $url]);
                if (app()->runningInConsole()) {
                    URL::useOrigin($url); // links in mails and commands; web requests use their own address
                }
            }
            foreach (['digest_time' => 'kasmin.digest_time', 'approval_days' => 'kasmin.approval_days'] as $key => $config) {
                $value = Settings::get($key);
                if ($value !== null && $value !== '') {
                    config([$config => $key === 'approval_days' ? (int) $value : $value]);
                }
            }
            $account = MailAccount::query()->where('is_default', true)->where('delivery', 'smtp')->whereNotNull('smtp_host')->first();
            if ($account !== null) {
                $from = $account->senderProfiles()->orderBy('id')->first();
                config([
                    'mail.default' => 'smtp',
                    'mail.mailers.smtp' => [
                        'transport' => 'smtp', 'scheme' => $account->smtp_encryption === 'ssl' ? 'smtps' : 'smtp', 'require_tls' => true,
                        'host' => $account->smtp_host, 'port' => $account->smtp_port, 'username' => $account->smtp_username, 'password' => $account->smtp_password,
                    ],
                ]);
                if ($from !== null) {
                    config(['mail.from' => ['address' => $from->from_address, 'name' => $from->from_name]]);
                }
            }
        } catch (\Throwable $e) {
            report($e); // e.g. an unreadable password after a changed APP_KEY: keep the environment's settings
        }
    }
}
