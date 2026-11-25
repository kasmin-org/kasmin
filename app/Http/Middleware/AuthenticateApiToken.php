<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use App\Support\Api\ApiContext;
use App\Support\Audit\Audit;
use App\Support\Settings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer token authentication for the REST API and the MCP endpoint (X03, X04). The request acts on behalf of the
 * token's user, limited to the token's scopes. MCP needs a token marked for MCP and the AI setting not "none" (MI12).
 */
class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next, string $channel = 'api'): Response
    {
        // Internal calls of MCP tools to the REST API (App\Mcp\ApiTool) keep the MCP channel.
        if ($request->attributes->get('kasmin_channel') === 'mcp') {
            $channel = 'mcp';
        }
        $plain = (string) $request->bearerToken();
        $token = $plain !== '' ? ApiToken::findValid($plain) : null;
        if ($token === null) {
            Audit::record('api.auth_failed', null, null, ['channel' => $channel], 'system');

            return response()->json(['message' => 'Unauthenticated.'], 401, ['WWW-Authenticate' => 'Bearer']);
        }
        if ($channel === 'mcp' && (! $token->mcp || Settings::get('ai_processing', 'none') === 'none')) {
            return response()->json(['message' => 'This token may not be used via MCP, or AI processing is switched off in the settings.'], 403);
        }
        $previous = [ApiContext::token(), ApiContext::viaMcp()];
        ApiContext::set($token, $channel === 'mcp');
        Auth::setUser($token->user ?? abort(401));
        if ($token->last_used_at === null || $token->last_used_at->lt(now()->subMinute())) {
            $token->forceFill(['last_used_at' => now(), 'last_used_ip' => $request->ip()])->saveQuietly();
        }

        try {
            return $next($request);
        } finally {
            ApiContext::set(...$previous);
        }
    }
}
