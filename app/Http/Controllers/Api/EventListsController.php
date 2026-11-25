<?php

namespace App\Http\Controllers\Api;

use App\Models\EventRole;
use App\Support\Api\ApiContext;
use App\Support\Audit\Audit;
use App\Support\Lists\EventLists;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Lists of an event (participants, contacts, bus, groups, kitchen …) as headers and rows. Scope: events:read. The
 * lists made from the safety sheet (emergency, kitchen, medical, medication, insurance, swimmers, consents,
 * changes) are confidential: they need health:read in addition, and every read is recorded in the audit log.
 */
class EventListsController extends ApiController
{
    /** The lists this token may read, with their titles. */
    public function index(string $event): JsonResponse
    {
        $this->scope('events:read');
        $this->event($event);
        $health = ApiContext::allows('health:read');
        $lists = [];
        foreach (EventLists::LISTS as $key => $confidential) {
            if (! $confidential || $health) {
                $lists[] = ['key' => $key, 'title' => (string) __('lists.titles.'.$key), 'confidential' => $confidential];
            }
        }

        return response()->json(['data' => $lists]);
    }

    /** One list; role limits it to one role of the event. marks names the cells (row, column) that changed since the last event. */
    public function show(Request $request, string $event, string $key): JsonResponse
    {
        $this->scope('events:read');
        $model = $this->event($event);
        abort_unless(array_key_exists($key, EventLists::LISTS), 404);
        if (EventLists::LISTS[$key]) {
            $this->scope('health:read');
        }
        $role = $request->validate(['role' => ['sometimes', 'nullable', Rule::in(EventRole::KEYS)]])['role'] ?? null;
        $list = app(EventLists::class)->build($model, $key, $role);
        if (EventLists::LISTS[$key]) {
            Audit::record('list.viewed', $model, null, ['list' => $key]);
        }
        $marks = array_map(fn (string $mark) => array_map('intval', explode(':', $mark)), array_keys($list->marks));

        return response()->json(['data' => [
            'key' => $key, 'title' => $list->title, 'confidential' => $list->confidential, 'headers' => $list->headers, 'rows' => $list->rows,
            'marks' => array_map(fn (array $m) => ['row' => $m[0], 'column' => $m[1] ?? 0], $marks),
        ]]);
    }
}
