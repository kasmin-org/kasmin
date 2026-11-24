<?php

namespace App\Http\Controllers\Api;

use App\Models\AuditEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Audit log, read only (AU05). Scope: audit:read.
 */
class AuditController extends ApiController
{
    /** Filter by action (prefix, e.g. "login."), actor type and time range. */
    public function index(Request $request): JsonResponse
    {
        $this->scope('audit:read');
        $data = $request->validate(['action' => ['nullable', 'string', 'max:64'], 'actor_type' => ['nullable', 'string', 'max:16'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $page = AuditEvent::query()
            ->when($data['action'] ?? null, fn ($q, $a) => $q->where('action', 'like', $a.'%'))
            ->when($data['actor_type'] ?? null, fn ($q, $t) => $q->where('actor_type', $t))
            ->when($data['from'] ?? null, fn ($q, $f) => $q->where('occurred_at', '>=', $f))
            ->when($data['to'] ?? null, fn ($q, $t) => $q->where('occurred_at', '<=', $t))
            ->orderByDesc('id')->paginate($this->perPage(), ['id', 'occurred_at', 'actor_type', 'actor_id', 'action', 'subject_type', 'subject_id', 'meta']);

        return response()->json(['data' => $page->items(), 'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }
}
