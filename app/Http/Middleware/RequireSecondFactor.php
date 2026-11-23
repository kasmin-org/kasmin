<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireSecondFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user instanceof User && $user->requiresSecondFactor() && ! $request->session()->get('two_factor_passed')) {
            return redirect()->guest(route('two-factor'));
        }

        return $next($request);
    }
}
