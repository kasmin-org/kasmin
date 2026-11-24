<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use App\Models\User;
use App\Support\Auth\PortalPerson;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Switches the language; for logged-in people also the language of their mails.
 */
class LanguageController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $locale = (string) $request->validate(['locale' => ['required', Rule::in(SetLocale::LOCALES)]])['locale'];
        $request->session()->put('locale', $locale);
        $user = $request->user();
        if ($user instanceof User && ! PortalPerson::previewing($request)) {
            $user->person?->update(['locale' => $locale]);
        }

        return back();
    }
}
