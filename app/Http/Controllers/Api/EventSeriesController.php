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

    /** Creates a series; type is e.g. camp, alumni, meeting or other (default camp). */
    public function store(Request $request): JsonResponse
    {
        $this->scope('events:write');
        $data = $request->validate(['name' => ['required', 'string', 'max:200'], 'type' => ['sometimes', 'string', 'alpha_dash', 'max:32']]);
        $series = EventSeries::query()->create($data + ['type' => 'camp']);

        return response()->json(['data' => $this->present($series->loadCount('events'))], 201);
    }

    /** Changes the name or the type. */
    public function update(Request $request, int $series): JsonResponse
    {
        $this->scope('events:write');
        $model = EventSeries::query()->findOrFail($series);
        $model->update($request->validate(['name' => ['sometimes', 'string', 'max:200'], 'type' => ['sometimes', 'string', 'alpha_dash', 'max:32']]));

        return response()->json(['data' => $this->present($model->loadCount('events'))]);
    }

    /** @return array<string, mixed> */
    private function present(EventSeries $s): array
    {
        return ['id' => (int) $s->getKey(), 'name' => $s->name, 'type' => $s->type, 'events' => (int) $s->getAttribute('events_count')];
    }
}
