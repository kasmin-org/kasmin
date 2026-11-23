<?php

namespace App\Filament\Support;

use App\Models\User;
use Filament\Forms\Components\TextInput;

/**
 * Amount input in the currency unit (e.g. 12.50) stored as cents.
 */
class Money
{
    public static function input(string $name, string $label): TextInput
    {
        return TextInput::make($name)->label($label)->numeric()->step('0.01')->minValue(0)->suffix('€')
            ->formatStateUsing(fn ($state) => $state === null ? null : number_format(((int) $state) / 100, 2, '.', ''))
            ->dehydrateStateUsing(fn ($state) => $state === null || $state === '' ? 0 : (int) round(((float) str_replace(',', '.', (string) $state)) * 100));
    }

    public static function toCents(mixed $value): int
    {
        return (int) round(((float) str_replace(',', '.', (string) $value)) * 100);
    }

    public static function canManage(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->canManageFinance();
    }

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->canViewFinance();
    }
}
