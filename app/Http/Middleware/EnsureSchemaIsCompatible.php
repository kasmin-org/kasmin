<?php

namespace App\Http\Middleware;

use App\Support\Schema\SchemaGuard;
use App\Support\Schema\SchemaStatus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stops every web request while the database does not match the code (see SchemaGuard).
 */
class EnsureSchemaIsCompatible
{
    public function __construct(private readonly SchemaGuard $guard) {}

    public function handle(Request $request, Closure $next): Response
    {
        $status = $this->guard->status();

        if ($status === SchemaStatus::Ok) {
            return $next($request);
        }

        return response()->view('system.schema', [
            'status' => $status,
            'codeVersion' => $this->guard->codeVersion(),
            'databaseVersion' => $this->guard->databaseVersion(),
        ], 503);
    }
}
