<?php

namespace App\Filament\Support;

use App\Support\Auth\Permissions;
use Illuminate\Database\Eloquent\Model;

/**
 * Access of a resource by the area of its permissions (RO04).
 */
trait AreaAccess
{
    abstract public static function area(): string;

    public static function canAccess(): bool
    {
        return static::canViewAny();
    }

    public static function canViewAny(): bool
    {
        return Permissions::current(static::area());
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return Permissions::current(static::area(), 'edit');
    }

    public static function canEdit(Model $record): bool
    {
        return Permissions::current(static::area(), 'edit');
    }

    public static function canDelete(Model $record): bool
    {
        return Permissions::current(static::area(), 'edit');
    }

    public static function canDeleteAny(): bool
    {
        return Permissions::current(static::area(), 'edit');
    }
}
