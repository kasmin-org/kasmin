<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;

/**
 * The start page of the admin interface. It belongs to no area (B11): it opens with the logo or "Dashboard" at the
 * top left, not from the navigation of an area.
 */
class Dashboard extends BaseDashboard
{
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }
}
