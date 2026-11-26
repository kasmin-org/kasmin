<?php

namespace App\Http\Controllers\Api;

use App\Models\Event;
use App\Models\EventRole;
use App\Support\Api\ApiContext;
use App\Support\Reports\DashboardData;
use App\Support\Reports\Statistics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Statistics of an event and the figures of the dashboard. Scope: events:read; figures about money only with
 * finance:read in addition. Counts and names only, no confidential values.
 */
class ReportsController extends ApiController
{
    /**
     * The reports of the page "Statistics" for one event (default: the next event with participants): age
     * distribution, age groups for activities (groups = how many, default 3), costs and discounts (finance:read),
     * and participants per year. role limits the ages to one role (default child, "all" for everybody).
     */
    public function statistics(Request $request): JsonResponse
    {
        $this->scope('events:read');
        $data = $request->validate([
            'event' => ['sometimes', 'nullable', 'string'], 'role' => ['sometimes', 'nullable', Rule::in([...EventRole::KEYS, 'all'])], 'groups' => ['sometimes', 'integer', 'between:1,20'],
        ]);
        $event = isset($data['event']) ? $this->event($data['event']) : $this->defaultEvent();
        $roles = ($data['role'] ?? 'child') === 'all' ? [] : [$data['role'] ?? 'child'];
        $stats = app(Statistics::class);
        $finance = ApiContext::allows('finance:read');

        return response()->json(['data' => [
            'event' => $event?->public_id, 'title' => $event?->title, 'roles' => $roles,
            'ages' => $event !== null ? (object) $stats->ageDistribution($event, $roles) : null,
            'age_groups' => $event !== null ? $stats->ageGroups($event, (int) ($data['groups'] ?? 3), $roles) : [],
            'costs' => $event !== null && $finance ? $stats->costs($event) : null,
            'discounts' => $event !== null && $finance ? $stats->discounts($event) : null,
            'years' => $stats->years(),
        ]]);
    }

    /**
     * The dashboard: the next event of every series with registrations, free places, waitlist and bus; to-dos;
     * safety sheets confirmed and missing per upcoming event (counts only). With finance:read also open and overdue
     * invoices; with automations:read the runs that are due and failed. Amounts are in cents.
     */
    public function dashboard(): JsonResponse
    {
        $this->scope('events:read');
        $finance = ApiContext::allows('finance:read');
        $dashboard = app(DashboardData::class);
        $events = array_map(function (array $block): array {
            $block['id'] = Event::query()->whereKey($block['id'])->value('public_id');

            return $block;
        }, $dashboard->series($finance));
        $todos = $dashboard->todos();
        if (! $finance) {
            unset($todos['unmatched_payments']);
        }
        $data = ['events' => $events, 'todos' => $todos, 'safety_sheets' => $dashboard->safetySheets()];
        if ($finance) {
            $data['finance'] = $dashboard->finance();
        }
        if (ApiContext::allows('automations:read')) {
            $data['automations'] = $dashboard->automations();
        }

        return response()->json(['data' => $data]);
    }

    /** As on the page: the next event with participants, otherwise the next or the latest one. */
    private function defaultEvent(): ?Event
    {
        $current = fn () => Event::query()->whereNotIn('status', ['draft', 'cancelled'])->where('ends_at', '>=', now()->subMonths(2));

        return $current()->whereHas('participations')->orderBy('starts_at')->first() ?? $current()->orderBy('starts_at')->first() ?? Event::query()->orderByDesc('starts_at')->first();
    }
}
