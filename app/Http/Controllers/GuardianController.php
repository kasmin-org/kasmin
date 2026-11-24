<?php

namespace App\Http\Controllers;

use App\Models\Person;
use App\Support\Auth\PortalPerson;
use App\Support\People\Guardians;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * Further legal guardians and own logins of children (RG07, GP22).
 */
class GuardianController extends Controller
{
    public function __construct(private readonly Guardians $guardians) {}

    public function invite(Request $request, string $household): RedirectResponse
    {
        $person = $this->person($request);
        $model = $person->households()->wherePivot('role', 'guardian')->where('households.public_id', $household)->firstOrFail();
        $data = $request->validate(['first_name' => ['required', 'string', 'max:120'], 'last_name' => ['required', 'string', 'max:120'], 'email' => ['required', 'email', 'max:254']]);
        try {
            $this->guardians->invite($model, $person, ['first_name' => (string) $data['first_name'], 'last_name' => (string) $data['last_name'], 'email' => (string) $data['email']]);
        } catch (RuntimeException $e) {
            return back()->withErrors(['email' => $e->getMessage()]);
        }

        return back()->with('status', __('public.invited'));
    }

    public function show(string $token): View
    {
        $invitation = $this->guardians->find($token);

        return $invitation === null ? view('public.confirm', ['token' => $token, 'form' => null])
            : view('public.guardian', ['token' => $token, 'host' => $invitation->invitedBy?->fullName(), 'done' => false]);
    }

    public function accept(string $token): View
    {
        $invitation = $this->guardians->find($token);
        if ($invitation === null) {
            return view('public.confirm', ['token' => $token, 'form' => null]);
        }
        try {
            $this->guardians->accept($invitation);
        } catch (RuntimeException) {
            return view('public.confirm', ['token' => $token, 'form' => null]);
        }

        return view('public.guardian', ['token' => $token, 'host' => null, 'done' => true]);
    }

    public function childLogin(Request $request, string $child): RedirectResponse
    {
        $person = $this->person($request);
        $model = $person->wards()->where('public_id', $child)->firstOrFail();
        $email = (string) $request->validate(['email' => ['required', 'email', 'max:254']])['email'];
        try {
            $this->guardians->createLogin($model, $person, $email);
        } catch (RuntimeException $e) {
            return back()->withErrors(['email' => $e->getMessage()]);
        }

        return back()->with('status', __('portal.login_created'));
    }

    private function person(Request $request): Person
    {
        return PortalPerson::for($request) ?? abort(403);
    }
}
