<?php

namespace App\Http\Controllers\Api;

use App\Support\Api\ApiContext;
use Illuminate\Http\JsonResponse;

class MeController extends ApiController
{
    /** The token in use: name, scopes, expiry and the user it acts for. */
    public function show(): JsonResponse
    {
        $token = ApiContext::token();

        return response()->json(['data' => [
            'token' => $token?->name, 'scopes' => $token->scopes ?? [], 'mcp' => $token?->mcp, 'expires_at' => $token?->expires_at?->toIso8601String(),
            'user' => $token?->user?->name, 'version' => config('kasmin.version'),
        ]]);
    }
}
