<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Global settings (S01) stored as key/value pairs in `system_meta`. Values are strings; helpers convert.
 */
class Settings
{
    /** Organisation and invoice settings with their defaults. */
    public const DEFAULTS = [
        'organisation_name' => null,
        'organisation_address' => '',
        'organisation_email' => '',
        'organisation_website' => '',
        'organisation_iban' => '',
        'organisation_bic' => '',
        'organisation_bank' => '',
        'organisation_legal_footer' => '',
        'invoice_number_format' => 'R{number}',
        'invoice_next_number' => '1',
        'invoice_number_per_year' => '0',
        'invoice_due_days' => '14',
        'invoice_attach_paid' => '1',
        'invoice_reminder_days_before' => '3',
        'invoice_overdue_days' => '7,21',
        'max_guardians' => '5',            // RG07
        'min_login_age' => '14',           // PE04
        'guardian_until_age' => '18',      // PE05
        'own_registrations_visible' => '0', // PE05a
        'retention_safety_years' => '7', // CO01
        'retention_invoice_years' => '7',
        'public_url' => '',
        'mail_test_address' => '',       // MS08: all mails go here
        'mailing_approval_above' => '50', // MM07; 0 = never
        'embed_origins' => '', // websites that may show public pages in a frame
        'timezone' => null, // UTC; stored times are in this time zone, so it is chosen at the set-up
        'digest_time' => null,
        'approval_days' => null,
        'ai_processing' => 'none', // none, non_confidential, all (MI12)
    ];

    /** @var array<string, string|null> */
    private static array $cache = [];

    public static function get(string $key, ?string $default = null): ?string
    {
        if (! array_key_exists($key, self::$cache)) {
            try {
                self::$cache[$key] = DB::table('system_meta')->where('key', $key)->value('value');
            } catch (\Throwable) {
                return $default ?? self::DEFAULTS[$key] ?? null;
            }
        }

        return self::$cache[$key] ?? $default ?? self::DEFAULTS[$key] ?? null;
    }

    public static function int(string $key): int
    {
        return (int) self::get($key);
    }

    public static function bool(string $key): bool
    {
        return in_array(self::get($key), ['1', 'true', 'yes'], true);
    }

    /** @return list<int> */
    public static function intList(string $key): array
    {
        return array_values(array_filter(array_map('intval', explode(',', (string) self::get($key))), fn (int $v) => $v > 0));
    }

    public static function set(string $key, ?string $value): void
    {
        DB::table('system_meta')->updateOrInsert(['key' => $key], ['value' => $value, 'updated_at' => now(), 'created_at' => now()]);
        self::$cache[$key] = $value;
    }

    public static function flush(): void
    {
        self::$cache = [];
    }
}
