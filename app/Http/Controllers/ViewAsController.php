<?php

namespace App\Http\Controllers;

use App\Models\Person;
use App\Support\Audit\Audit;
use App\Support\Auth\PortalPerson;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Ends the read-only preview (started on the person page in the admin interface) of the portal as a legal guardian (AD25). Logged in the audit log.
 */
class ViewAsController extends Controller
{
    public function end(Request $request): RedirectResponse
    {
        $id = $request->session()->pull(PortalPerson::SESSION_KEY);
        if ($id !== null) {
            Audit::record('portal.view_as_ended', Person::query()->find((int) $id));
        }

        return redirect($id !== null ? '/admin/people/'.$id : '/admin');
    }
}
