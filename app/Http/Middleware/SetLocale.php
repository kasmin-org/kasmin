<?php

namespace App\Http\Middleware;

use App\Support\Language;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The language of the installation is used for every request – there is none per person or per visit. Before the
 * installation the standard of the configuration applies; the installer offers both languages itself.
 */
class SetLocale
{
    public const LOCALES = Language::SUPPORTED;

    public function handle(Request $request, Closure $next): Response
    {
        Language::apply();

        return $next($request);
    }
}
