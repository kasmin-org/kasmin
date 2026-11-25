<?php

namespace App\Http\Controllers\Api;

use App\Models\Event;
use App\Models\Participation;
use App\Support\Api\Presenter;
use App\Support\Events\RegistrationException;
use App\Support\Events\Registrations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Events, participants and waitlists. Scopes: events:read, events:write.
 */
class EventsController extends ApiController
{
    /** Events, newest first; filter by status. */
    public function index(Request $request): JsonResponse
    {
        $this->scope('events:read');
        $events = Event::query()->with('series')->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('starts_at')->limit(200)->get();

        return response()->json(['data' => $events->map(fn (Event $e) => Presenter::event($e))->values()]);
    }

    /** One event with roles, capacities, free places, waitlist lengths, prices and instalments. */
    public function show(string $event): JsonResponse
    {
        $this->scope('events:read');

        return response()->json(['data' => Presenter::event($this->event($event)->load('series', 'roles', 'installments'), true)]);
    }

    /** Participants of an event; filter by status and role. */
    public function participations(Request $request, string $event): JsonResponse
    {
        $this->scope('events:read');
        $model = $this->event($event);
        $items = Participation::query()->where('event_id', $model->id)->with('person', 'event')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('role'), fn ($q, $r) => $q->where('role_key', $r))->orderBy('registered_at')->get();

        return response()->json(['data' => $items->map(fn (Participation $p) => Presenter::participation($p))->values()]);
    }

    /** Register a person (age limits and capacity apply; full events put the person on the waitlist). */
    public function register(Request $request, string $event): JsonResponse
    {
        $this->scope('events:write');
        $data = $request->validate(['person' => ['required', 'string'], 'role' => ['required', 'string', 'max:32']]);
        $person = $this->person($data['person']);
        try {
            $participation = app(Registrations::class)->register($this->event($event), $person, $data['role']);
        } catch (RegistrationException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => Presenter::participation($participation->load('event', 'person'))], 201);
    }

    /** Change the status of a participation; cancelling frees the place for the waitlist. */
    public function updateParticipation(Request $request, int $participation): JsonResponse
    {
        $this->scope('events:write');
        $model = Participation::query()->with('event', 'person')->findOrFail($participation);
        $data = $request->validate(['status' => ['required', Rule::in(Participation::STATUSES)]]);
        if ($data['status'] === 'cancelled') {
            app(Registrations::class)->cancel($model);
        } else {
            $model->fill(['status' => $data['status']])->save();
        }

        return response()->json(['data' => Presenter::participation($model->refresh()->load('event', 'person'))]);
    }
}
