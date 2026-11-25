<?php

namespace App\Support\System;

use App\Support\Schema\SchemaGuard;

/**
 * Links to the documentation of the installed version (H03, V04). The website publishes the documentation per
 * minor version (docs/0.22/…) and for the development branch (docs/dev/…).
 */
class Docs
{
    public const BASE = 'https://www.kasmin.org/docs/';

    /** Admin pages by the start of their route name (after "filament.admin."); the first match counts. */
    public const PAGES = [
        'pages.dashboard' => 'admin/dashboard-and-reports',
        'pages.reports' => 'admin/dashboard-and-reports',
        'pages.duplicates' => 'admin/registration',
        'pages.import' => 'admin/import',
        'resources.import-batches' => 'admin/import',
        'pages.lists' => 'admin/lists',
        'pages.notifications' => 'admin/mail',
        'pages.privacy' => 'admin/privacy',
        'resources.consents' => 'admin/privacy',
        'resources.audit-events' => 'admin/privacy',
        'pages.season' => 'admin/event-planning',
        'resources.events.schedule' => 'admin/event-planning',
        'resources.events.groups' => 'admin/events',
        'resources.event-templates' => 'admin/event-planning',
        'pages.settings' => 'admin/system',
        'pages.system' => 'admin/system',
        'resources.api-tokens' => 'admin/api',
        'resources.webhooks' => 'admin/api',
        'resources.automation' => 'admin/automations',
        'resources.event' => 'admin/events',
        'resources.forms' => 'admin/forms',
        'resources.public-' => 'admin/registration',
        'resources.groups' => 'admin/groups',
        'resources.membership-types' => 'admin/groups',
        'resources.households' => 'admin/registration',
        'resources.people' => 'admin/registration',
        'resources.invoices' => 'admin/invoices',
        'resources.payments' => 'admin/invoices',
        'resources.refunds' => 'admin/invoices',
        'resources.roles' => 'admin/roles',
        'resources.mail' => 'admin/mail',
        'resources.outgoing-mails' => 'admin/mail',
        'resources.bad-addresses' => 'admin/mail',
        'resources.sender-profiles' => 'admin/mail',
    ];

    public static function url(string $page): string
    {
        return self::BASE.self::versionSlug().'/'.trim($page, '/').'/';
    }

    /** "0.22" for 0.22.3, "dev" for a development version. */
    public static function versionSlug(?string $version = null): string
    {
        $version ??= app(SchemaGuard::class)->codeVersion();
        if (str_contains($version, '-') || preg_match('/^(\d+\.\d+)\.\d+$/', $version, $m) !== 1) {
            return 'dev';
        }

        return $m[1];
    }

    /** The documentation page for an admin route, or null if there is none. */
    public static function forRoute(?string $route): ?string
    {
        $name = preg_replace('/^filament\.admin\./', '', (string) $route);
        foreach (self::PAGES as $prefix => $page) {
            if (str_starts_with((string) $name, $prefix)) {
                return self::url($page);
            }
        }

        return null;
    }
}
