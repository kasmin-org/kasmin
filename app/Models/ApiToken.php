<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * API/MCP token (X03): acts on behalf of a user, limited to its scopes; only the hash is stored.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $token_hash
 * @property string $prefix
 * @property list<string> $scopes
 * @property bool $mcp
 * @property Carbon|null $expires_at
 * @property Carbon|null $last_used_at
 * @property Carbon|null $revoked_at
 */
class ApiToken extends Model
{
    /** Scopes; health data, deciding approvals and settings are separate and off by default (X03, AP02, S02). */
    public const SCOPES = [
        'people:read', 'people:write', 'events:read', 'events:write', 'finance:read', 'finance:write',
        'mail:read', 'mail:write', 'automations:read', 'automations:write', 'automations:run', 'approvals:decide',
        'import:write', 'audit:read', 'health:read', 'health:write', 'settings:write',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['scopes' => 'array', 'mcp' => 'boolean', 'expires_at' => 'datetime', 'last_used_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Creates a token; the plain value is returned once and never stored.
     *
     * @param  list<string>  $scopes
     * @return array{token: ApiToken, plain: string}
     */
    public static function issue(User $user, string $name, array $scopes, bool $mcp = false, ?Carbon $expiresAt = null): array
    {
        $plain = 'kas_'.Str::random(48);
        $token = self::query()->create([
            'user_id' => $user->id, 'name' => $name, 'token_hash' => hash('sha256', $plain), 'prefix' => substr($plain, 0, 10),
            'scopes' => array_values(array_intersect($scopes, self::SCOPES)), 'mcp' => $mcp, 'expires_at' => $expiresAt,
        ]);

        return ['token' => $token, 'plain' => $plain];
    }

    public static function findValid(string $plain): ?self
    {
        $token = self::query()->where('token_hash', hash('sha256', $plain))->with('user.person')->first();

        return $token !== null && $token->isUsable() ? $token : null;
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null && ($this->expires_at === null || $this->expires_at->isFuture())
            && $this->user !== null && $this->user->hasAnyRole(['administrator']); // not after the owner lost the role
    }

    public function can(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }
}
