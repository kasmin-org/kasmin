<?php

namespace App\Http\Middleware;

use App\Support\Auth\PortalPerson;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * In the preview "view as legal guardian" (AD25) nothing can be changed: every write request is refused.
 */
class ReadOnlyPortalPreview
{
    public function handle(Request $request, Closure $next): Response
    {
        if (PortalPerson::previewing($request) && ! $request->isMethodSafe() && ! $request->routeIs('portal.view-as.end')) {
            return back()->withErrors(['preview' => __('portal.preview_read_only')]);
        }

        return $next($request);
    }
}
