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
        'own_event_days' => '180',        // RO01: roles for the own events count this long after the end
        'max_guardians' => '5',            // RG07
        'min_login_age' => '14',           // PE04
        'guardian_until_age' => '18',      // PE05
        'own_registrations' => 'hidden',   // PE05a: hidden, informed, visible
        'newsletter_ask' => '1',           // CO02
        'gender_note_female' => '',        // PE09: text below the choice of the gender of a child
        'gender_note_male' => '',
        'gender_note_diverse' => null,     // null = the text of the language files (in both languages)
        'retention_safety_years' => '7', // CO01
        'retention_invoice_years' => '7',
        'public_url' => '',
        'backup_keep' => '7',
        'backup_remote_url' => '',      // N07: WebDAV folder (https)
        'backup_remote_user' => '',
        'mail_logo' => '',
        'mail_color' => '#1da029',       // MT06
        'mail_footer' => '',
        'audit_ip_days' => '30',         // AU03; 0 = never shortened
        'retention_audit_years' => '10', // AU05; 0 = kept for good
        'retention_mail_years' => '0',   // ML05; 0 = as long as the person exists
        'auto_update' => '1',            // UP03
        'auto_update_days' => '10',
        'auto_update_time' => '04:00',
        'mail_test_address' => '',       // MS08: all mails go here
        'mailing_approval_above' => '50', // MM07; 0 = never
        'embed_origins' => '', // websites that may show public pages in a frame
        'timezone' => null, // UTC; stored times are in this time zone, so it is chosen at the set-up
        'digest_time' => null,
        'approval_days' => null,
        'approval_summary_time' => '07:00', // AT06: one mail with all open approvals at this time; several times with commas; empty = each request at once
        'ai_processing' => 'none', // none, non_confidential, all (MI12)
        'expense_four_eyes' => '0', // E1d.3: a second person approves expense claims
        'expense_flat_rates' => '', // E1d.3: one flat rate per line, "name: amount"
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

    /**
     * PE09: the note shown below the gender of a child, per gender; an empty text shows nothing.
     *
     * @return array<string, string>
     */
    public static function genderNotes(): array
    {
        $notes = [];
        foreach (['female', 'male', 'diverse'] as $gender) {
            $text = trim((string) (self::get('gender_note_'.$gender) ?? ($gender === 'diverse' ? __('portal.gender_note_diverse') : '')));
            if ($text !== '') {
                $notes[$gender] = $text;
            }
        }

        return $notes;
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
