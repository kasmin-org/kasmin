<?php

namespace App\Support;

/**
 * The language of the installation. It is chosen at the installation and can be changed by administrators in the
 * settings; there is no language per person or per visit. Texts that were entered (forms, templates, names) are not
 * translated and stay as they are when the language changes.
 */
class Language
{
    public const SUPPORTED = ['de', 'en'];

    public static function current(): string
    {
        $chosen = Settings::get('default_locale');

        return in_array($chosen, self::SUPPORTED, true) ? $chosen : app()->getLocale();
    }

    /** Makes the language of the installation the one in use (web requests, cron, commands). */
    public static function apply(): void
    {
        $chosen = Settings::get('default_locale');
        if (in_array($chosen, self::SUPPORTED, true) && app()->getLocale() !== $chosen) {
            app()->setLocale($chosen);
        }
    }
}
