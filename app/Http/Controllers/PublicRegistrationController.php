<?php

namespace App\Http\Controllers;

use App\Support\Auth\ProofOfWork;
use App\Support\Language;
use App\Support\People\SignUps;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Public registration for legal guardians (RG01–RG03): name and e-mail, then a link to confirm. The answer is the same
 * whether or not the address already exists; existing accounts simply receive a login link.
 */
class PublicRegistrationController extends Controller
{
    public function __construct(private readonly SignUps $signUps) {}

    public function show(): View
    {
        return view('register');
    }

    public function store(Request $request): RedirectResponse
    {
        // Honeypot: bots fill every field.
        if ((string) $request->input('website') !== '') {
            return redirect()->route('login.code');
        }
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:254'],
            'privacy' => ['accepted'],
        ]);
        if (! ProofOfWork::valid($request->input('pow'))) {
            return back()->withInput()->withErrors(['pow' => __('public.check_failed')]);
        }
        $this->signUps->request([
            'first_name' => (string) $data['first_name'], 'last_name' => (string) $data['last_name'], 'email' => (string) $data['email'], 'locale' => Language::current(),
            'newsletter' => Settings::bool('newsletter_ask') && $request->boolean('newsletter') ? (string) __('public.newsletter_consent') : null,
        ]);

        return redirect()->route('login.code')->with('email', $data['email'])->with('status', __('auth_link.sent'));
    }

    public function confirmation(string $token): View
    {
        return $this->signUps->find($token) === null
            ? view('public.confirm-simple', ['title' => __('public.invalid_title'), 'action' => null, 'text' => __('public.invalid')])
            : view('public.confirm-simple', ['title' => __('portal.register_title'), 'action' => route('register.confirm.store', $token), 'text' => null]);
    }

    public function confirm(string $token): View|RedirectResponse
    {
        $signUp = $this->signUps->find($token);
        if ($signUp === null) {
            return $this->confirmation($token);
        }

        return redirect()->route('login.link', $this->signUps->confirm($signUp));
    }
}
