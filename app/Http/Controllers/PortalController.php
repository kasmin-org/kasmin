<?php

namespace App\Http\Controllers;

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
            'person' => $person,
            'households' => $households,
            'limited' => $request->session()->get('login_scope') === 'limited',
        ]);
    }
}
