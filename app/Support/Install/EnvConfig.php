<?php

namespace App\Support\Install;

use App\Models\MailAccount;
use App\Models\SenderProfile;
use App\Support\Audit\Audit;
use App\Support\Settings;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Configuration of a container by environment variables (DK01–DK03). The values are written to the database once, when
 * Kasmin is set up. Afterwards only the database counts; a variable that differs is reported in the log, never applied.
 * Every variable also exists as NAME_FILE (path of a file with the value, e.g. a Docker secret).
 */
class EnvConfig
{
    /** Variable => setting in the database. */
    public const SETTINGS = [
        'KASMIN_PUBLIC_URL' => 'public_url',
        'KASMIN_ORGANISATION' => 'organisation_name',
        'KASMIN_ORGANISATION_ADDRESS' => 'organisation_address',
        'KASMIN_ORGANISATION_EMAIL' => 'organisation_email',
        'KASMIN_ORGANISATION_WEBSITE' => 'organisation_website',
        'KASMIN_ORGANISATION_IBAN' => 'organisation_iban',
        'KASMIN_ORGANISATION_BIC' => 'organisation_bic',
        'KASMIN_ORGANISATION_BANK' => 'organisation_bank',
        'KASMIN_ORGANISATION_LEGAL_FOOTER' => 'organisation_legal_footer',
        'KASMIN_LOCALE' => 'default_locale',
        'KASMIN_DEFAULTS' => 'installed_defaults',
        'KASMIN_ADMIN_EMAIL' => 'initial_admin_email',
        'KASMIN_INVOICE_NUMBER_FORMAT' => 'invoice_number_format',
        'KASMIN_INVOICE_DUE_DAYS' => 'invoice_due_days',
        'KASMIN_INVOICE_ATTACH_PAID' => 'invoice_attach_paid',
        'KASMIN_INVOICE_REMINDER_DAYS_BEFORE' => 'invoice_reminder_days_before',
        'KASMIN_INVOICE_OVERDUE_DAYS' => 'invoice_overdue_days',
        'KASMIN_RETENTION_SAFETY_YEARS' => 'retention_safety_years',
        'KASMIN_RETENTION_INVOICE_YEARS' => 'retention_invoice_years',
        'KASMIN_AI_PROCESSING' => 'ai_processing',
        'KASMIN_TIMEZONE' => 'timezone',
        'KASMIN_DIGEST_TIME' => 'digest_time',
        'KASMIN_APPROVAL_DAYS' => 'approval_days',
    ];

    /** Variable => column of the mail account created at the set-up. */
    public const MAIL = [
        'KASMIN_MAIL_HOST' => 'smtp_host',
        'KASMIN_MAIL_PORT' => 'smtp_port',
        'KASMIN_MAIL_ENCRYPTION' => 'smtp_encryption',
        'KASMIN_MAIL_USERNAME' => 'smtp_username',
        'KASMIN_MAIL_PASSWORD' => 'smtp_password',
        'KASMIN_IMAP_HOST' => 'imap_host',
        'KASMIN_IMAP_PORT' => 'imap_port',
        'KASMIN_IMAP_USERNAME' => 'imap_username',
        'KASMIN_IMAP_PASSWORD' => 'imap_password',
        'KASMIN_IMAP_SENT_FOLDER' => 'imap_sent_folder',
        'KASMIN_MAIL_RATE_PER_MINUTE' => 'rate_per_minute',
        'KASMIN_MAIL_RATE_PER_HOUR' => 'rate_per_hour',
        'KASMIN_MAIL_RATE_PER_DAY' => 'rate_per_day',
    ];

    /** Variable => column of the sender profile created at the set-up. */
    public const SENDER = [
        'KASMIN_MAIL_FROM_ADDRESS' => 'from_address',
        'KASMIN_MAIL_FROM_NAME' => 'from_name',
        'KASMIN_MAIL_REPLY_TO' => 'reply_to',
    ];

    public const SECRETS = ['KASMIN_MAIL_PASSWORD', 'KASMIN_IMAP_PASSWORD'];

    private const MAIL_ACCOUNT = 'env_mail_account';

    private const SENDER_PROFILE = 'env_sender_profile';

    /** The value of a variable (or of the file in NAME_FILE); null if it is not set or empty. */
    public static function value(string $name): ?string
    {
        $file = getenv($name.'_FILE');
        $value = is_string($file) && $file !== '' ? @file_get_contents($file) : getenv($name);
        if ($value === false || trim($value) === '') {
            return null;
        }
        $value = trim($value);

        return match ($name) {
            'KASMIN_PUBLIC_URL' => rtrim($value, '/'),
            'KASMIN_ADMIN_EMAIL' => Str::lower($value),
            'KASMIN_ORGANISATION_IBAN', 'KASMIN_ORGANISATION_BIC' => Str::upper(str_replace(' ', '', $value)),
            'KASMIN_INVOICE_ATTACH_PAID' => in_array(Str::lower($value), ['1', 'true', 'yes', 'on'], true) ? '1' : '0',
            default => $value,
        };
    }

    /**
     * Problems that would make the set-up fail or store nonsense.
     *
     * @return list<string>
     */
    public static function problems(): array
    {
        $problems = [];
        $check = function (string $name, callable $valid, string $message) use (&$problems): void {
            $value = self::value($name);
            if ($value !== null && ! $valid($value)) {
                $problems[] = $name.': '.$message;
            }
        };
        $oneOf = fn (array $values) => fn (string $v) => in_array($v, $values, true);
        $number = fn (string $v) => ctype_digit($v);
        $check('KASMIN_PUBLIC_URL', fn (string $v) => preg_match('#^https?://[A-Za-z0-9.-]+(:\d+)?$#', $v) === 1, 'must be like https://camp.example.org');
        $check('KASMIN_TIMEZONE', fn (string $v) => in_array($v, timezone_identifiers_list(), true), 'must be a time zone such as Europe/Vienna');
        $check('KASMIN_LOCALE', $oneOf(['de', 'en']), 'must be de or en');
        $check('KASMIN_DEFAULTS', $oneOf(['de', 'en', 'none']), 'must be de, en or none');
        $check('KASMIN_AI_PROCESSING', $oneOf(['none', 'non_confidential', 'all']), 'must be none, non_confidential or all');
        $check('KASMIN_MAIL_ENCRYPTION', $oneOf(['ssl', 'tls']), 'must be ssl or tls (unencrypted delivery is not possible)');
        $check('KASMIN_DIGEST_TIME', fn (string $v) => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) === 1, 'must be like 07:00');
        foreach (['KASMIN_ADMIN_EMAIL', 'KASMIN_ORGANISATION_EMAIL', 'KASMIN_MAIL_FROM_ADDRESS', 'KASMIN_MAIL_REPLY_TO'] as $name) {
            $check($name, fn (string $v) => filter_var($v, FILTER_VALIDATE_EMAIL) !== false, 'is not an e-mail address');
        }
        foreach (['KASMIN_INVOICE_DUE_DAYS', 'KASMIN_INVOICE_REMINDER_DAYS_BEFORE', 'KASMIN_RETENTION_SAFETY_YEARS', 'KASMIN_RETENTION_INVOICE_YEARS', 'KASMIN_APPROVAL_DAYS', 'KASMIN_MAIL_PORT', 'KASMIN_IMAP_PORT', 'KASMIN_MAIL_RATE_PER_MINUTE', 'KASMIN_MAIL_RATE_PER_HOUR', 'KASMIN_MAIL_RATE_PER_DAY'] as $name) {
            $check($name, $number, 'must be a number');
        }
        foreach ([...array_keys(self::SETTINGS), ...array_keys(self::MAIL), ...array_keys(self::SENDER)] as $name) {
            $file = getenv($name.'_FILE');
            if (is_string($file) && $file !== '' && ! is_readable($file)) {
                $problems[] = $name.'_FILE: '.$file.' cannot be read';
            }
        }

        return $problems;
    }

    /** Writes the variables to the database; only called once by the installer. */
    public function store(string $adminEmail): void
    {
        if (($problems = self::problems()) !== []) {
            throw new RuntimeException(implode('; ', $problems));
        }
        $given = ['organisation_name', 'default_locale', 'installed_defaults']; // chosen in the installer
        foreach (self::SETTINGS as $name => $key) {
            $value = self::value($name);
            if ($value !== null && ! in_array($key, $given, true)) {
                Settings::set($key, $value);
            }
        }
        Settings::set('initial_admin_email', Str::lower($adminEmail));
        $mail = $this->columns(self::MAIL);
        if (isset($mail['smtp_host'])) {
            $account = MailAccount::query()->create($mail + [
                'name' => 'SMTP', 'delivery' => 'smtp', 'smtp_port' => ($mail['smtp_encryption'] ?? 'ssl') === 'ssl' ? 465 : 587, 'smtp_encryption' => 'ssl', 'is_default' => true,
            ]);
            Settings::set(self::MAIL_ACCOUNT, (string) $account->id);
            $sender = $this->columns(self::SENDER);
            if (isset($sender['from_address'])) {
                $profile = SenderProfile::query()->create($sender + ['name' => 'Standard', 'from_name' => (string) Settings::get('organisation_name'), 'mail_account_id' => $account->id]);
                Settings::set(self::SENDER_PROFILE, (string) $profile->id);
            }
        }
        Audit::record('settings.environment_stored', null, null, null, 'system');
    }

    /**
     * Variables that differ from the database (DK03). Secrets are named without their values.
     *
     * @return list<string>
     */
    public function differences(): array
    {
        $result = [];
        $compare = function (string $name, mixed $stored) use (&$result): void {
            $value = self::value($name);
            $stored = match (true) {
                is_bool($stored) => $stored ? '1' : '0', $stored === null => '', default => (string) $stored,
            };
            if ($value === null || $value === $stored) {
                return;
            }
            $result[] = in_array($name, self::SECRETS, true)
                ? "{$name} differs from the value in the database."
                : "{$name} is \"{$value}\", but the database contains \"{$stored}\".";
        };
        foreach (self::SETTINGS as $name => $key) {
            $compare($name, Settings::get($key));
        }
        $account = MailAccount::query()->find((int) Settings::get(self::MAIL_ACCOUNT));
        foreach (self::MAIL as $name => $column) {
            $compare($name, $account?->getAttribute($column));
        }
        $profile = SenderProfile::query()->find((int) Settings::get(self::SENDER_PROFILE));
        foreach (self::SENDER as $name => $column) {
            $compare($name, $profile?->getAttribute($column));
        }

        return $result;
    }

    /**
     * @param  array<string, string>  $map
     * @return array<string, string>
     */
    private function columns(array $map): array
    {
        $values = [];
        foreach ($map as $name => $column) {
            $value = self::value($name);
            if ($value !== null) {
                $values[$column] = $value;
            }
        }

        return $values;
    }
}
