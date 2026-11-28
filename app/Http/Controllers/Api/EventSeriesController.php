<?php

namespace App\Http\Controllers\Api;

use App\Models\CostCenter;
use App\Models\EventSeries;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Event series: events of the same kind over the years. Scopes: events:read, events:write.
 */
class EventSeriesController extends ApiController
{
    /** All series with the number of their events. */
    public function index(): JsonResponse
    {
        $this->scope('events:read');

        return response()->json(['data' => EventSeries::query()->withCount('events')->orderBy('name')->get()->map(fn (EventSeries $s) => $this->present($s))->values()]);
    }

    /** Creates a series. */
    public function store(Request $request): JsonResponse
    {
        $this->scope('events:write');
        $series = EventSeries::query()->create($this->data($request, true));

        return response()->json(['data' => $this->present($series->loadCount('events'))], 201);
    }

    /** Changes the name or the cost centre. */
    public function update(Request $request, int $series): JsonResponse
    {
        $this->scope('events:write');
        $model = EventSeries::query()->findOrFail($series);
        $model->update($this->data($request, false));

        return response()->json(['data' => $this->present($model->loadCount('events'))]);
    }

    /** @return array<string, mixed> name, and the cost centre (id or exact name) invoices of its events are booked on */
    private function data(Request $request, bool $new): array
    {
        $data = $request->validate(['name' => [$new ? 'required' : 'sometimes', 'string', 'max:200'], 'cost_center' => ['sometimes', 'nullable']]);
        if (array_key_exists('cost_center', $data)) {
            $data['cost_center_id'] = $this->costCenterRef($data['cost_center'], 'cost_center');
            unset($data['cost_center']);
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function present(EventSeries $s): array
    {
        return ['id' => (int) $s->getKey(), 'name' => $s->name, 'events' => (int) $s->getAttribute('events_count'),
            'cost_center' => $s->cost_center_id !== null ? CostCenter::query()->whereKey($s->cost_center_id)->value('name') : null, 'cost_center_id' => $s->cost_center_id];
    }
}
