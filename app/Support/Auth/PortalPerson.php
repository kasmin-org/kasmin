<?php

namespace App\Support\Auth;

use App\Models\Person;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The person whose portal is shown: the logged-in user's person, or – for administrators in the read-only preview
 * "view as legal guardian" (AD25) – the chosen person.
 */
class PortalPerson
{
    public const SESSION_KEY = 'portal_view_as';

    public static function for(Request $request): ?Person
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return null;
        }
        $viewAs = $request->session()->get(self::SESSION_KEY);
        if ($viewAs !== null && $user->isAdministrative()) {
            return Person::query()->find((int) $viewAs);
        }

        return $user->person;
    }

    public static function previewing(Request $request): bool
    {
        $user = $request->user();

        return $request->hasSession() && $request->session()->has(self::SESSION_KEY) && $user instanceof User && $user->isAdministrative();
    }
}
