<?php

namespace App\Support\Api;

use App\Models\ApiToken;
use App\Support\Settings;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The API token of the current request (REST or MCP), for scope checks and the audit log (AU02, AU03).
 */
class ApiContext
{
    private static ?ApiToken $token = null;

    private static bool $viaMcp = false;

    public static function set(?ApiToken $token, bool $viaMcp = false): void
    {
        self::$token = $token;
        self::$viaMcp = $viaMcp;
    }

    public static function token(): ?ApiToken
    {
        return self::$token;
    }

    public static function viaMcp(): bool
    {
        return self::$viaMcp;
    }

    public static function allows(string $scope): bool
    {
        if (self::$token === null || ! self::$token->can($scope)) {
            return false;
        }
        // AI processing setting (MI12): via MCP, health data only if the organisation allows all data.
        if (self::$viaMcp && in_array($scope, ['health:read', 'health:write'], true)) {
            return Settings::get('ai_processing') === 'all';
        }

        return true;
    }

    /** @throws AuthorizationException */
    public static function require(string $scope): void
    {
        if (! self::allows($scope)) {
            throw new AuthorizationException("The token lacks the scope {$scope}.");
        }
    }
}
