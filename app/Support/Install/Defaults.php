<?php

namespace App\Support\Install;

use App\Models\MembershipType;
use App\Models\Role;
use App\Support\Language;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;

/**
 * Default content chosen in the installer (H01). Built-in roles are always created, because at least one
 * administrator must exist; everything else only with "German defaults" or "English defaults".
 */
class Defaults
{
    /** @var array<string, array{de: string, en: string, admin: bool}> */
    public const ROLES = [
        'administrator' => ['de' => 'Administration', 'en' => 'Administrator', 'admin' => true],
        'board' => ['de' => 'Vorstand', 'en' => 'Board', 'admin' => true],
        'treasurer' => ['de' => 'Finanzen', 'en' => 'Treasurer', 'admin' => true],
        'safety_officer' => ['de' => 'Sicherheitsdaten', 'en' => 'Safety officer', 'admin' => true],
        'camp_leader' => ['de' => 'Lagerleitung', 'en' => 'Camp leader', 'admin' => true, 'own' => true],
        'counsellor' => ['de' => 'Betreuung', 'en' => 'Counsellor', 'admin' => false, 'own' => true],
        'auditor' => ['de' => 'Rechnungsprüfung', 'en' => 'Auditor', 'admin' => true],
    ];

    /** @var array<string, array{de: string, en: string, fee: bool}> */
    public const MEMBERSHIP_TYPES = [
        'child' => ['de' => 'Kind (außerordentliches Mitglied)', 'en' => 'Child (associate member)', 'fee' => true],
        'junior_counsellor' => ['de' => 'Nachwuchs-Betreuung', 'en' => 'Junior counsellor', 'fee' => true],
        'regular' => ['de' => 'Ordentliches Mitglied (Team, Vorstand)', 'en' => 'Regular member (team, board)', 'fee' => false],
        'former' => ['de' => 'Ehemaliges Mitglied', 'en' => 'Former member', 'fee' => false],
    ];

    /**
     * Language of the standard content (forms, mail templates, automations, event types) if the organisation chose it at
     * the installation; null if it chose none. Installations from before this was recorded: by what is there.
     */
    public static function wanted(): ?string
    {
        try {
            $chosen = DB::table('system_meta')->where('key', 'installed_defaults')->value('value');
            if (in_array($chosen, ['de', 'en'], true)) {
                return $chosen;
            }
            if ($chosen !== null) {
                return null;
            }
            $some = DB::table('mail_templates')->where('is_standard', true)->exists() || DB::table('forms')->where('key', 'safety_sheet')->exists();

            return $some ? (DB::table('system_meta')->where('key', 'default_locale')->value('value') === 'en' ? 'en' : 'de') : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function installRoles(): void
    {
        foreach (self::ROLES as $key => $role) {
            Role::query()->firstOrCreate(['key' => $key], [
                'name' => ['de' => $role['de'], 'en' => $role['en']],
                'is_admin' => $role['admin'],
                'requires_second_factor' => $role['admin'],
                'scope' => ($role['own'] ?? false) ? 'own' : 'all',
            ]);
        }
    }

    /**
     * @param  string  $defaults  'de' or 'en': the standard content in this language (an installation has one language,
     *                            so each text is stored once); 'none': the roles only
     */
    public function install(string $defaults): void
    {
        $previous = app()->getLocale();
        if (in_array($defaults, Language::SUPPORTED, true) && Settings::get('default_locale') === null) {
            app()->setLocale($defaults); // the texts are written in the language in use
        }
        try {
            $this->installAll($defaults);
        } finally {
            app()->setLocale($previous);
        }
    }

    private function installAll(string $defaults): void
    {
        $this->installRoles();
        if ($defaults === 'none') {
            return;
        }
        app(FormDefaults::class)->install();
        app(MailTemplateDefaults::class)->install();
        app(AutomationDefaults::class)->install();
        app(EventTemplateDefaults::class)->install($defaults);

        $sort = 0;
        foreach (self::MEMBERSHIP_TYPES as $key => $type) {
            MembershipType::query()->firstOrCreate(['key' => $key], [
                'name' => ['de' => $type['de'], 'en' => $type['en']],
                'pays_fee' => $type['fee'],
                'sort' => $sort += 10,
            ]);
        }
    }
}
