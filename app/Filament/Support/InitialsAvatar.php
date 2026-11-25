<?php

namespace App\Filament\Support;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

/**
 * Picture with the initials of the user for the menu of the admin interface, made on the server. The default of
 * Filament asks another website for it and so passes on the initials of everybody who logs in.
 */
class InitialsAvatar implements AvatarProvider
{
    public function get(Model $record): string
    {
        $words = preg_split('/\s+/u', trim(Filament::getNameForDefaultAvatar($record))) ?: [];
        $letters = array_filter(array_map(fn (string $word) => mb_substr((string) preg_replace('/^[^\p{L}\p{N}]+/u', '', $word), 0, 1), $words), fn (string $l) => $l !== '');
        $initials = mb_strtoupper(count($letters) > 1 ? reset($letters).end($letters) : implode('', $letters));
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" fill="#14501c"/>'
            .'<text x="32" y="33" text-anchor="middle" dominant-baseline="central" font-family="system-ui,sans-serif" font-size="26" fill="#fff">'
            .htmlspecialchars($initials, ENT_XML1 | ENT_QUOTES).'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
