<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use PragmaRX\Google2FAQRCode\Google2FA;

/**
 * Second factor (TOTP app) for administrative roles (L06). Set up on first login, asked after every login.
 */
class TwoFactorController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        if (! $user->requiresSecondFactor() || $request->session()->get('two_factor_passed')) {
            return redirect()->intended('/admin');
        }

        $qr = null;
        if ($user->two_factor_confirmed_at === null) {
            $google2fa = new Google2FA;
            $secret = $request->session()->get('two_factor_setup_secret') ?? $google2fa->generateSecretKey(32);
            $request->session()->put('two_factor_setup_secret', $secret);
            $qr = $google2fa->getQRCodeInline((string) config('app.name'), $user->email, (string) $secret);
        }

        return view('auth.two-factor', [
            'setup' => $user->two_factor_confirmed_at === null,
            'qr' => $qr,
            'secret' => $request->session()->get('two_factor_setup_secret'),
        ]);
    }

    public function verify(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $code = (string) $request->validate(['code' => ['required', 'digits:6']])['code'];
        $google2fa = new Google2FA;
        $setup = $user->two_factor_confirmed_at === null;
        $secret = $setup ? (string) $request->session()->get('two_factor_setup_secret') : (string) $user->two_factor_secret;

        if ($secret === '' || ! $google2fa->verifyKey($secret, $code, 1)) {
            Audit::record('two_factor.failed', $user);

            return back()->withErrors(['code' => __('auth_link.wrong_code')]);
        }

        if ($setup) {
            $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save();
            $request->session()->forget('two_factor_setup_secret');
            Audit::record('two_factor.enabled', $user);
        }
        $request->session()->put('two_factor_passed', true);
        Audit::record('two_factor.passed', $user, null, ['method' => 'totp']);

        return redirect()->intended('/admin');
    }
}
