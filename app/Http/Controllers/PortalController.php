<?php

namespace App\Http\Controllers;

use App\Models\Household;
use App\Models\Invoice;
use App\Models\OutgoingMail;
use App\Models\User;
use App\Support\Auth\PortalPerson;
use App\Support\People\Guardians;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Portal for legal guardians and participants (mobile first).
 */
class PortalController extends Controller
{
    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();
        $person = PortalPerson::for($request);
        $households = $person?->households()->with(['members.contactPoints'])->get() ?? collect();

        $householdIds = $person?->households()->wherePivot('role', 'guardian')->pluck('households.id') ?? collect();
        $invoices = $person !== null ? Invoice::query()->where('status', '!=', 'draft')
            ->where(fn ($q) => $q->where('payer_id', $person->id)->orWhereIn('household_id', $householdIds))
            ->with('event')->latest('id')->limit(20)->get() : collect();

        return view('portal.index', [
            'invoices' => $invoices,
            'mails' => $person !== null ? OutgoingMail::query()->where('kind', '!=', 'notification')->where('person_id', $person->id)->whereIn('status', ['sent', 'queued', 'sending'])->latest('id')->limit(10)->get() : collect(),
            'person' => $person,
            'households' => $households,
            'wardIds' => $person !== null ? $person->wards()->pluck('people.id')->all() : [],
            'guardiansLeft' => $households->mapWithKeys(fn (Household $h) => [$h->id => app(Guardians::class)->left($h)])->all(),
            'limited' => $request->session()->get('login_scope') === 'limited',
        ]);
    }

    /** A mail that was sent to the logged-in person (ML02). */
    public function mail(Request $request, string $uuid): View
    {
        /** @var User $user */
        $user = $request->user();
        $mail = OutgoingMail::query()->where('kind', '!=', 'notification')->where('uuid', $uuid)->where('person_id', PortalPerson::for($request)?->id)->firstOrFail();

        return view('portal.mail', ['mail' => $mail]);
    }
}
