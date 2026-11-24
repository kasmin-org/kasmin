<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\LoginLinkMail;
use App\Models\ContactPoint;
use App\Models\LoginToken;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Auth\AdminLoginNotice;
use App\Support\Auth\LoginLinks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

/**
 * Passwordless login: e-mail → link and code. Opening a link never logs in by itself (mail scanners open links);
 * the page shows a button that has to be pressed.
 */
class LoginController extends Controller
{
    public function __construct(private readonly LoginLinks $links) {}

    public function show(): View
    {
        return view('auth.login');
    }

    public function send(Request $request): RedirectResponse
    {
        $email = mb_strtolower(trim((string) $request->validate(['email' => ['required', 'email', 'max:254']])['email']));

        return $this->sendTo($email);
    }

    public function sendTo(string $email): RedirectResponse
    {
        $user = $this->findUser($email);

        if ($user !== null) {
            ['token' => $token, 'code' => $code] = $this->links->issue($user);
            $name = $user->person->first_name ?? $user->name;
            Mail::to($email)->locale($user->person->locale ?? app()->getLocale())
                ->send(new LoginLinkMail($name, route('login.link', $token), $code, LoginLinks::LINK_MINUTES));
        }

        // Same answer whether or not the address exists (no account enumeration).
        return redirect()->route('login.code')->with('email', $email)->with('status', __('auth_link.sent'));
    }

    public function showLink(string $token): View
    {
        $model = $this->links->findByToken($token);
        if ($model === null) {
            Audit::record('login.failed', null, null, ['method' => 'link', 'reason' => 'invalid_or_expired'], 'system');
        }

        return view('auth.link', ['valid' => $model !== null, 'token' => $token]);
    }

    public function consumeLink(Request $request, string $token): RedirectResponse
    {
        $model = $this->links->findByToken($token);
        if ($model === null) {
            Audit::record('login.failed', null, null, ['method' => 'link', 'reason' => 'invalid_or_expired'], 'system');

            return redirect()->route('login')->withErrors(['email' => __('auth_link.invalid')]);
        }

        return $this->login($request, $this->links->consume($model), match ($model->purpose) {
            'mail_link' => 'mail_link', 'cli' => 'cli_link', default => 'magic_link',
        });
    }

    public function showCode(): View
    {
        return view('auth.code', ['email' => session('email')]);
    }

    public function consumeCode(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'code' => ['required', 'digits:6']]);
        $user = $this->findUser(mb_strtolower(trim($data['email'])));
        $model = $user !== null ? $this->links->redeemCode($user, $data['code']) : null;

        if ($model === null) {
            Audit::record('login.failed', $user, null, ['method' => 'code', 'reason' => 'wrong_code'], 'system');

            return back()->withInput()->withErrors(['code' => __('auth_link.wrong_code')]);
        }

        return $this->login($request, $model, 'code');
    }

    public function logout(Request $request): RedirectResponse
    {
        Audit::record('logout', $request->user());
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function login(Request $request, LoginToken $token, string $method): RedirectResponse
    {
        /** @var User $user */
        $user = $token->user;
        // Remembered devices only for full logins without administrative role: every administrator login is reported (LG02).
        $limited = $token->scope === 'limited';
        Auth::login($user, remember: ! $limited && ! $user->isAdministrative());
        $request->session()->regenerate();
        $request->session()->put('login_scope', $token->scope);
        $user->forceFill(['last_login_at' => now()])->save();

        Audit::record('login.succeeded', $user, null, [
            'method' => $method, 'token_id' => $token->id, 'purpose' => $token->purpose, 'scope' => $token->scope,
        ], 'user', $user->id);

        app(AdminLoginNotice::class)->send($user, $request, $method);

        if ($user->requiresSecondFactor()) {
            return redirect()->route('two-factor');
        }

        return redirect()->intended($user->isAdministrative() && ! $limited ? '/admin' : route('portal'));
    }

    private function findUser(string $email): ?User
    {
        $user = User::query()->where('email', $email)->first();
        if ($user !== null) {
            return $user;
        }
        $personId = ContactPoint::query()->where('type', 'email')->where('value', $email)
            ->whereJsonContains('purposes', 'login')->value('person_id');

        return $personId !== null ? User::query()->where('person_id', $personId)->first() : null;
    }
}
