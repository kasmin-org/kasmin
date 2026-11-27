<?php

namespace App\Http\Controllers;

use App\Models\ContactPoint;
use App\Models\Household;
use App\Models\Person;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Auth\LoginLinks;
use App\Support\Auth\PortalPerson;
use App\Support\Auth\Sessions;
use App\Support\Mail\Notifier;
use App\Support\People\Addresses;
use App\Support\Privacy\DataExport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Own contact data, family address and invoice recipient, and the copy of one's own data (F03, FD01, N03).
 */
class PortalProfileController extends Controller
{
    public function __construct(private readonly Addresses $addresses) {}

    public function edit(Request $request): View
    {
        $person = $this->person($request);

        return view('portal.profile.edit', [
            'person' => $person, 'points' => $person->contactPoints()->orderByDesc('is_primary')->get(),
            'households' => $person->households()->wherePivot('role', 'guardian')->with('members')->get(),
            'sessions' => PortalPerson::previewing($request) || ! $request->user() instanceof User ? [] : Sessions::of($request->user(), $request),
        ]);
    }

    public function logoutOthers(Request $request): RedirectResponse
    {
        abort_unless($request->user() instanceof User && ! PortalPerson::previewing($request), 403);
        $ended = Sessions::logoutOthers($request->user(), $request);

        return back()->with('status', trans_choice('portal.sessions_ended', $ended, ['count' => $ended]));
    }

    /** Ends the links in all older mails of the person (B14). */
    public function revokeLinks(Request $request): RedirectResponse
    {
        abort_unless($request->user() instanceof User && ! PortalPerson::previewing($request), 403);
        $count = app(LoginLinks::class)->revokeMailLinks($request->user());

        return back()->with('status', trans_choice('portal.mail_links_ended', $count, ['count' => $count]));
    }

    public function savePhones(Request $request): RedirectResponse
    {
        $person = $this->person($request);
        $data = $request->validate(['phones' => ['nullable', 'array', 'max:5'], 'phones.*' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+()\/ .-]+$/']]);
        $phones = array_values(array_unique(array_filter(array_map(fn ($p) => trim((string) $p), (array) ($data['phones'] ?? [])))));
        foreach ($person->contactPoints()->where('type', 'phone')->get() as $point) {
            in_array($point->value, $phones, true) ? $phones = array_values(array_diff($phones, [$point->value])) : $point->delete();
        }
        foreach ($phones as $phone) {
            $person->contactPoints()->create(['type' => 'phone', 'value' => $phone, 'purposes' => ['information', 'emergency'], 'is_primary' => $person->contactPoints()->where('type', 'phone')->doesntExist()]);
        }
        app(Notifier::class)->event('profile_updates', __('portal.notify_profile', ['name' => $person->fullName()]), url('/admin/people/'.$person->id));

        return back()->with('status', __('portal.saved'));
    }

    public function changeEmail(Request $request): RedirectResponse
    {
        $person = $this->person($request);
        $data = $request->validate(['email' => ['required', 'email', 'max:254'], 'replaces' => ['nullable', 'integer']]);
        $replaces = isset($data['replaces']) ? $person->contactPoints()->where('type', 'email')->findOrFail((int) $data['replaces']) : null;
        $this->addresses->request($person, (string) $data['email'], $replaces);

        return back()->with('status', __('portal.email_sent'));
    }

    public function removeEmail(Request $request, int $point): RedirectResponse
    {
        $person = $this->person($request);
        /** @var ContactPoint $model */
        $model = $person->contactPoints()->where('type', 'email')->findOrFail($point);
        $login = $person->user !== null && mb_strtolower($person->user->email) === $model->value;
        if ($login || $person->contactPoints()->where('type', 'email')->count() < 2) {
            return back()->withErrors(['email' => __('portal.email_needed')]);
        }
        $model->delete();

        return back()->with('status', __('portal.saved'));
    }

    public function saveFamily(Request $request, string $household): RedirectResponse
    {
        $person = $this->person($request);
        /** @var Household $model */
        $model = $person->households()->wherePivot('role', 'guardian')->where('households.public_id', $household)->firstOrFail();
        $data = $request->validate([
            'street' => ['nullable', 'string', 'max:200'], 'postal_code' => ['nullable', 'string', 'max:16', 'regex:/^[A-Za-z0-9 -]+$/'], 'city' => ['nullable', 'string', 'max:120'], 'country' => ['required', 'string', 'size:2', 'alpha'],
            'invoice_recipient_id' => ['nullable', Rule::in($model->guardians()->pluck('people.id')->all())],
        ]);
        $model->update(['country' => mb_strtoupper((string) $data['country'])] + $data);
        app(Notifier::class)->event('profile_updates', __('portal.notify_profile', ['name' => $model->name]), url('/admin/households/'.$model->id.'/edit'));

        return back()->with('status', __('portal.saved'));
    }

    public function export(Request $request): Response
    {
        $person = $this->person($request);
        Audit::record('privacy.data_exported', $person, null, ['source' => 'portal']);

        return response()->json(app(DataExport::class)->for($person), 200, [
            'Content-Disposition' => 'attachment; filename="kasmin-data.json"', 'Cache-Control' => 'private, no-store',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function confirmation(string $token): View
    {
        $change = $this->addresses->find($token);
        if ($change === null) {
            return view('public.confirm-simple', ['title' => __('public.invalid_title'), 'action' => null, 'text' => __('public.invalid')]);
        }
        // What exactly is confirmed: which address goes, which one comes.
        $old = $change->contact_point_id !== null ? ContactPoint::query()->whereKey($change->contact_point_id)->value('value') : null;

        return view('public.confirm-simple', ['title' => __('portal.confirm_address'), 'action' => route('address.confirm.store', $token), 'text' => null,
            'intro' => __($old !== null ? 'portal.address_replace' : 'portal.address_add', ['old' => (string) $old, 'new' => $change->email])]);
    }

    public function confirm(string $token): View
    {
        $change = $this->addresses->find($token);
        if ($change === null) {
            return view('public.confirm-simple', ['title' => __('public.invalid_title'), 'action' => null, 'text' => __('public.invalid')]);
        }
        $this->addresses->confirm($change);

        return view('public.confirm-simple', ['title' => __('portal.confirm_address'), 'action' => null, 'text' => __('portal.address_confirmed')]);
    }

    private function person(Request $request): Person
    {
        return PortalPerson::for($request) ?? abort(403);
    }
}
