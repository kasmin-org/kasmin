<?php

namespace App\Filament\Support;

use UnitEnum;

/**
 * A page or resource of one of the five areas (B11): it appears in the navigation on the left only while its area is
 * open, in the group and order that Areas gives it.
 */
trait InArea
{
    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return Areas::group(static::class);
    }

    public static function getNavigationSort(): ?int
    {
        return Areas::sort(static::class);
    }

    public static function shouldRegisterNavigation(): bool
    {
        $area = Areas::of(static::class);

        return $area !== null && $area === Areas::current();
    }
}
