<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * From a limited session (link in a mail) to a full login: sends a fresh link and code to the person's own address (L03).
 */
class StepUpController extends Controller
{
    public function show(): View
    {
        return view('auth.step-up');
    }

    public function send(Request $request, LoginController $login): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $intended = $request->session()->get('url.intended');
        Audit::record('login.step_up_requested', $user);
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        if (is_string($intended)) {
            $request->session()->put('url.intended', $intended);
        }

        return $login->sendTo($user->email);
    }
}
