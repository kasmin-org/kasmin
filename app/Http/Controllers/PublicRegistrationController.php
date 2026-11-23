<?php

namespace App\Http\Controllers;

use App\Mail\LoginLinkMail;
use App\Models\ContactPoint;
use App\Models\Household;
use App\Models\Person;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Auth\LoginLinks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Public registration for legal guardians (RG01–RG03): name and e-mail, then a magic link. The answer is the same
 * whether or not the address already exists; existing accounts simply receive a login link.
 */
class PublicRegistrationController extends Controller
{
    public function __construct(private readonly LoginLinks $links) {}

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
            'locale' => ['required', 'in:de,en'],
            'privacy' => ['accepted'],
        ]);
        $email = Str::lower(trim($data['email']));

        $user = User::query()->where('email', $email)->first();
        if ($user === null && ! ContactPoint::query()->where('type', 'email')->where('value', $email)->exists()) {
            $user = DB::transaction(function () use ($data, $email) {
                $person = Person::query()->create(['first_name' => $data['first_name'], 'last_name' => $data['last_name'], 'locale' => $data['locale']]);
                $person->contactPoints()->create(['type' => 'email', 'value' => $email, 'purposes' => ['login', 'information', 'invoices'], 'is_primary' => true]);
                $household = Household::query()->create(['name' => $data['last_name'], 'locale' => $data['locale']]);
                $household->members()->attach($person->id, ['role' => 'guardian']);
                $household->update(['invoice_recipient_id' => $person->id]);
                Audit::record('registration.guardian', $person, null, ['source' => 'public_form']);

                return User::query()->create(['name' => $person->fullName(), 'email' => $email, 'password' => Str::password(64), 'person_id' => $person->id]);
            });
        }

        if ($user !== null) {
            ['token' => $token, 'code' => $code] = $this->links->issue($user);
            Mail::to($email)->locale($data['locale'])
                ->send(new LoginLinkMail($data['first_name'], route('login.link', $token), $code, LoginLinks::LINK_MINUTES));
        }

        return redirect()->route('login.code')->with('email', $email)->with('status', __('auth_link.sent'));
    }
}
