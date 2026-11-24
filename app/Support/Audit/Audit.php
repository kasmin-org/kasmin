<?php

namespace App\Support\Audit;

use App\Models\AuditEvent;
use App\Support\Api\ApiContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Writes entries to the append-only audit log.
 */
class Audit
{
    private static ?string $requestId = null;

    /**
     * @param  array<string, mixed>|null  $changes
     * @param  array<string, mixed>|null  $meta
     */
    public static function record(string $action, ?Model $subject = null, ?array $changes = null, ?array $meta = null, ?string $actorType = null, ?int $actorId = null): AuditEvent
    {
        $request = app()->runningInConsole() ? null : request();
        $userId = Auth::id();
        $token = ApiContext::token();
        if ($token !== null && $actorType === null) {
            // API and MCP calls: the token is the actor, the user it acts for is kept in the meta data (AU02, AU03).
            $actorType = ApiContext::viaMcp() ? 'mcp' : 'api';
            $actorId = $token->id;
            $meta = ($meta ?? []) + ['on_behalf_of' => $userId];
        }

        return AuditEvent::query()->create([
            'occurred_at' => now(),
            'actor_type' => $actorType ?? ($userId !== null ? 'user' : 'system'),
            'actor_id' => $actorId ?? (is_int($userId) ? $userId : null),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'changes' => $changes,
            'meta' => $meta,
            'ip' => $request?->ip(),
            'user_agent' => $request !== null ? Str::limit((string) $request->userAgent(), 250, '') : null,
            'request_id' => self::requestId(),
        ]);
    }

    public static function requestId(): string
    {
        return self::$requestId ??= (string) Str::uuid();
    }
}
