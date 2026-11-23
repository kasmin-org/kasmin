<?php

namespace App\Http\Controllers;

use App\Models\OutgoingMail;
use App\Models\User;
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
        $person = $user->person;
        $households = $person?->households()->with(['members.contactPoints'])->get() ?? collect();

        return view('portal.index', [
            'mails' => $person !== null ? OutgoingMail::query()->where('person_id', $person->id)->whereIn('status', ['sent', 'queued', 'sending'])->latest('id')->limit(10)->get() : collect(),
            'person' => $person,
            'households' => $households,
            'limited' => $request->session()->get('login_scope') === 'limited',
        ]);
    }

    /** A mail that was sent to the logged-in person (ML02). */
    public function mail(Request $request, string $uuid): View
    {
        /** @var User $user */
        $user = $request->user();
        $mail = OutgoingMail::query()->where('uuid', $uuid)->where('person_id', $user->person_id)->firstOrFail();

        return view('portal.mail', ['mail' => $mail]);
    }
}
