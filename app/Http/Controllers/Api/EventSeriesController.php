<?php

namespace App\Http\Controllers\Api;

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
        $series = EventSeries::query()->create($request->validate(['name' => ['required', 'string', 'max:200']]));

        return response()->json(['data' => $this->present($series->loadCount('events'))], 201);
    }

    /** Changes the name. */
    public function update(Request $request, int $series): JsonResponse
    {
        $this->scope('events:write');
        $model = EventSeries::query()->findOrFail($series);
        $model->update($request->validate(['name' => ['sometimes', 'string', 'max:200']]));

        return response()->json(['data' => $this->present($model->loadCount('events'))]);
    }

    /** @return array<string, mixed> */
    private function present(EventSeries $s): array
    {
        return ['id' => (int) $s->getKey(), 'name' => $s->name, 'events' => (int) $s->getAttribute('events_count')];
    }
}
