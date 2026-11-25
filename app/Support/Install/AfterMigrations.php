<?php

namespace App\Support\Install;

use App\Models\Automation;

/**
 * Standard texts, automations and event types that a migration brings along. They are installed after all migrations:
 * the models belong to the newest database, so in between a column may still be missing.
 */
class AfterMigrations
{
    /** @var array<class-string<MailTemplateDefaults|AutomationDefaults|EventTemplateDefaults>, true> */
    private static array $requested = [];

    /** @var list<string> */
    private static array $automations = [];

    /** @param  class-string<MailTemplateDefaults|AutomationDefaults|EventTemplateDefaults>  ...$defaults */
    public static function request(string ...$defaults): void
    {
        foreach ($defaults as $class) {
            self::$requested[$class] = true;
        }
    }

    /** Single automations for every installation, also without defaults. */
    public static function automations(string ...$keys): void
    {
        array_push(self::$automations, ...array_values($keys));
    }

    public static function run(): void
    {
        [$requested, $automations] = [array_keys(self::$requested), self::$automations];
        [self::$requested, self::$automations] = [[], []];
        foreach ($automations as $key) {
            Automation::query()->firstOrCreate(['key' => $key], AutomationDefaults::definitions()[$key] + ['is_standard' => true, 'active' => false]);
        }
        foreach ($requested as $class) {
            app($class)->install();
        }
    }
}
