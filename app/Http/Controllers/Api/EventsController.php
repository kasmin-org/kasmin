<?php

namespace App\Http\Controllers\Api;

use App\Models\Event;
use App\Models\EventRole;
use App\Models\Participation;
use App\Support\Api\Presenter;
use App\Support\Events\Bus;
use App\Support\Events\RegistrationException;
use App\Support\Events\Registrations;
use App\Support\Events\Waitlist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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

    /**
     * Creates an event with the basic fields of the event form and its roles (capacity, ages, price). Prices by age
     * or date, instalments, discounts, registration rounds, costs, documents and the image are set in the admin
     * interface.
     */
    public function store(Request $request): JsonResponse
    {
        $this->scope('events:write');
        $data = $this->data($request, null);
        $event = DB::transaction(function () use ($data) {
            $event = Event::query()->create($data['event']);
            $this->saveRoles($event, $data['roles'] ?? []);

            return $event;
        });

        return response()->json(['data' => Presenter::event($event->load('series', 'roles', 'installments'), true)], 201);
    }

    /**
     * Changes the basic fields. With "roles", the listed roles are created or changed (what is not sent stays, null
     * empties a field) and the roles that are not listed are removed; a role with registrations cannot be removed.
     */
    public function update(Request $request, string $event): JsonResponse
    {
        $this->scope('events:write');
        $model = $this->event($event);
        $data = $this->data($request, $model);
        DB::transaction(function () use ($model, $data) {
            $model->update($data['event']);
            if ($data['roles'] !== null) {
                $this->saveRoles($model, $data['roles']);
            }
        });

        return response()->json(['data' => Presenter::event($model->refresh()->load('series', 'roles', 'installments'), true)]);
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
        $data = $request->validate(['status' => ['required_without:travel', Rule::in(Participation::STATUSES)], 'travel' => ['sometimes', Rule::in(['bus', 'car'])]]);
        if (isset($data['travel'])) {
            abort_unless(Bus::set($model, (string) $data['travel']), 422, 'Only adults can travel by car.');
        }
        if (! isset($data['status'])) {
            // only the travel was changed
        } elseif ($data['status'] === 'cancelled') {
            app(Registrations::class)->cancel($model);
        } else {
            $model->fill(['status' => $data['status']])->save();
        }

        return response()->json(['data' => Presenter::participation($model->refresh()->load('event', 'person'))]);
    }

    /**
     * @return array{event: array<string, mixed>, roles: list<array<string, mixed>>|null}
     */
    private function data(Request $request, ?Event $event): array
    {
        $need = $event === null ? 'required' : 'sometimes';
        $text = fn (int $max) => ['sometimes', 'nullable', 'string', 'max:'.$max];
        $data = $request->validate([
            'title' => [$need, 'string', 'max:200'],
            'series_id' => ['sometimes', 'nullable', 'integer', 'exists:event_series,id'],
            'status' => ['sometimes', Rule::in(Event::STATUSES)],
            'starts_at' => [$need, 'date'],
            'ends_at' => [$need, 'date'],
            'location' => $text(200), 'meeting_point' => $text(200), 'return_point' => $text(200), 'address' => $text(250),
            'map_url' => ['sometimes', 'nullable', 'url', 'starts_with:https://', 'max:500'],
            'contact_name' => $text(150), 'contact_email' => ['sometimes', 'nullable', 'email', 'max:254'], 'contact_phone' => $text(40),
            'description' => $text(20000),
            'registration_binding' => ['sometimes', 'boolean'], 'public_page' => ['sometimes', 'boolean'], 'reserve_auto_release' => ['sometimes', 'boolean'],
            'max_guests' => ['sometimes', 'integer', 'between:0,10'],
            'safety_confirm_days' => ['sometimes', 'nullable', 'integer', 'between:1,365'],
            'age_reference_date' => ['sometimes', 'nullable', 'date'], 'answers_until' => ['sometimes', 'nullable', 'date'], 'reserve_reminder_on' => ['sometimes', 'nullable', 'date'],
            'announce_at' => ['sometimes', 'nullable', 'date'], 'registration_opens_at' => ['sometimes', 'nullable', 'date'], 'registration_closes_at' => ['sometimes', 'nullable', 'date'],
            'roles' => ['sometimes', 'array', 'max:'.count(EventRole::KEYS)],
            'roles.*.role' => ['required', 'distinct', Rule::in(EventRole::KEYS)],
            'roles.*.capacity' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'roles.*.reserve_places' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'roles.*.min_age' => ['nullable', 'integer', 'between:0,120'],
            'roles.*.max_age' => ['nullable', 'integer', 'between:0,120'],
            'roles.*.registered_by' => ['nullable', Rule::in(['guardian', 'self', 'admin'])],
            'roles.*.price' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'roles.*.role_discount' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'roles.*.counts_as_sibling' => ['nullable', 'boolean'],
            'roles.*.waitlist_order' => ['nullable', 'array'],
            'roles.*.waitlist_order.*' => ['string', 'distinct', Rule::in(Waitlist::CRITERIA)],
        ]);
        $roles = array_key_exists('roles', $data) ? array_values($data['roles']) : null;
        unset($data['roles']);
        if (array_key_exists('series_id', $data)) {
            $data['event_series_id'] = $data['series_id'];
            unset($data['series_id']);
        }
        // The same rules between two dates as in the form, also when only one of them is sent.
        $date = fn (string $key) => array_key_exists($key, $data) ? ($data[$key] !== null ? Carbon::parse((string) $data[$key]) : null) : $event?->getAttribute($key);
        $errors = [];
        if ($date('ends_at')?->lt($date('starts_at')) === true) {
            $errors['ends_at'][] = 'The end must not be before the start.';
        }
        if ($date('registration_opens_at') !== null && $date('registration_closes_at')?->lte($date('registration_opens_at')) === true) {
            $errors['registration_closes_at'][] = 'The registration must close after it opens.';
        }
        foreach ($roles ?? [] as $i => $role) {
            if (isset($role['min_age'], $role['max_age']) && $role['max_age'] < $role['min_age']) {
                $errors["roles.{$i}.max_age"][] = 'The highest age must not be below the lowest age.';
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['event' => $data, 'roles' => $roles];
    }

    /**
     * @param  list<array<string, mixed>>  $roles
     */
    private function saveRoles(Event $event, array $roles): void
    {
        $keys = array_column($roles, 'role');
        foreach ($event->roles()->whereNotIn('role_key', $keys)->get() as $gone) {
            if ($event->participations()->where('role_key', $gone->role_key)->exists()) {
                throw ValidationException::withMessages(['roles' => ["The role {$gone->role_key} has registrations and cannot be removed."]]);
            }
            $gone->delete();
        }
        foreach ($roles as $role) {
            $values = array_intersect_key($role, array_flip(['capacity', 'min_age', 'max_age']));
            foreach (['reserve_places', 'registered_by', 'counts_as_sibling', 'waitlist_order'] as $key) {
                if (isset($role[$key])) {
                    $values[$key] = $role[$key];
                }
            }
            foreach (['price' => 'price_cents', 'role_discount' => 'role_discount_cents'] as $key => $column) {
                if (isset($role[$key])) {
                    $values[$column] = (int) round(((float) $role[$key]) * 100);
                }
            }
            $model = $event->roles()->firstOrNew(['role_key' => $role['role']]);
            $model->fill($values)->save();
        }
    }
}
