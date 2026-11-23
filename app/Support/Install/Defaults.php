<?php

namespace App\Support\Install;

use App\Models\MembershipType;
use App\Models\Role;

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
        'camp_leader' => ['de' => 'Lagerleitung', 'en' => 'Camp leader', 'admin' => true],
        'counsellor' => ['de' => 'Betreuung', 'en' => 'Counsellor', 'admin' => false],
        'auditor' => ['de' => 'Rechnungsprüfung', 'en' => 'Auditor', 'admin' => true],
    ];

    /** @var array<string, array{de: string, en: string, fee: bool}> */
    public const MEMBERSHIP_TYPES = [
        'child' => ['de' => 'Kind (außerordentliches Mitglied)', 'en' => 'Child (associate member)', 'fee' => true],
        'junior_counsellor' => ['de' => 'Nachwuchs-Betreuung', 'en' => 'Junior counsellor', 'fee' => true],
        'regular' => ['de' => 'Ordentliches Mitglied (Team, Vorstand)', 'en' => 'Regular member (team, board)', 'fee' => false],
        'former' => ['de' => 'Ehemaliges Mitglied', 'en' => 'Former member', 'fee' => false],
    ];

    public function installRoles(): void
    {
        foreach (self::ROLES as $key => $role) {
            Role::query()->firstOrCreate(['key' => $key], [
                'name' => ['de' => $role['de'], 'en' => $role['en']],
                'is_admin' => $role['admin'],
                'requires_second_factor' => $role['admin'],
            ]);
        }
    }

    public function install(string $defaults): void
    {
        $this->installRoles();
        if ($defaults === 'none') {
            return;
        }
        app(FormDefaults::class)->install();

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
