<?php

namespace App\Http\Controllers\Api;

use App\Casts\OneText;
use App\Models\Automation;
use App\Models\Event;
use App\Models\EventInstallment;
use App\Models\EventRole;
use App\Models\EventTemplate;
use App\Models\Form;
use App\Models\Invoice;
use App\Models\MembershipType;
use App\Models\Participation;
use App\Support\Api\Presenter;
use App\Support\Automations\Schedule;
use App\Support\Events\Bus;
use App\Support\Events\EventCopier;
use App\Support\Events\Offers;
use App\Support\Events\RegistrationException;
use App\Support\Events\Registrations;
use App\Support\Events\ReservePlaces;
use App\Support\Events\Waitlist;
use App\Support\Finance\Corrections;
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
    /** Events, newest first; filter by status. In pages (page, per_page up to 200, default 50). */
    public function index(Request $request): JsonResponse
    {
        $this->scope('events:read');
        $page = Event::query()->with('series')->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('starts_at')->orderByDesc('id')->paginate($this->perPage());

        return response()->json(['data' => $page->getCollection()->map(fn (Event $e) => Presenter::event($e))->values(), 'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    /** One event with roles, capacities, free places, waitlist lengths, prices, instalments and the bus (seats, riders, free or missing seats). */
    public function show(string $event): JsonResponse
    {
        $this->scope('events:read');

        return response()->json(['data' => Presenter::event($this->event($event)->load('series', 'roles', 'installments'), true)]);
    }

    /**
     * Creates an event with the fields of the event form: basic data (incl. bus_booked and bus_seats), roles
     * (capacity, ages, price, registration_form), the sibling discount, cancellation fee and text, reminder days,
     * prices by age or date (prices) and the payment plan (installments). With "template" (id of an event
     * template) or "copy_of" (id of an event), "title" and "starts_at" the event is a copy with everything that is
     * configured, all dates moved – as "From a template" and "Copy" in the admin interface; change it with PATCH.
     * Registration rounds, costs and documents have their own endpoints; the image is set in the admin interface.
     */
    public function store(Request $request): JsonResponse
    {
        $this->scope('events:write');
        if ($request->has('template') || $request->has('copy_of')) {
            $copy = $request->validate([
                'template' => ['required_without:copy_of', 'prohibits:copy_of', 'string'], 'copy_of' => ['required_without:template', 'string'],
                'title' => ['required', 'string', 'max:200'], 'starts_at' => ['required', 'date'],
            ]);
            $source = isset($copy['template']) ? EventTemplate::query()->where('public_id', $copy['template'])->firstOrFail() : $this->event((string) $copy['copy_of']);
            $event = app(EventCopier::class)->copy($source, $copy['title'], Carbon::parse((string) $copy['starts_at']));

            return response()->json(['data' => Presenter::event($this->event($event->public_id)->load('series', 'roles', 'installments', 'prices'), true)], 201);
        }
        $event = $this->create($request, new Event);

        return response()->json(['data' => Presenter::event($event->load('series', 'roles', 'installments', 'prices'), true)], 201);
    }

    /**
     * Changes the fields of the event form. With "roles", the listed roles are created or changed (what is not sent
     * stays, null empties a field) and the roles that are not listed are removed; a role with registrations cannot
     * be removed. "prices" replaces all prices by age or date. "installments" works like roles, by key; an
     * instalment that already has invoices cannot be removed.
     */
    public function update(Request $request, string $event): JsonResponse
    {
        $this->scope('events:write');
        $model = $this->change($request, $this->event($event));

        return response()->json(['data' => Presenter::event($model->load('series', 'roles', 'installments', 'prices'), true)]);
    }

    /** Event templates: events that are never shown or registered for, only the source of new events. */
    public function templates(): JsonResponse
    {
        $this->scope('events:read');

        return response()->json(['data' => EventTemplate::query()->with('series', 'roles', 'installments', 'prices')->orderBy('title')->get()->map(fn (EventTemplate $t) => Presenter::event($t, true))->values()]);
    }

    /**
     * Creates an event template. With "event" (id of an event) and "title" the template is a copy of that event
     * with everything that is configured, as "Save as template" does. Without "event" it is created from the
     * fields of the event form, like POST /events.
     */
    public function storeTemplate(Request $request): JsonResponse
    {
        $this->scope('events:write');
        if ($request->has('event')) {
            $data = $request->validate(['event' => ['required', 'string'], 'title' => ['required', 'string', 'max:200']]);
            $source = $this->event($data['event']);
            $template = EventTemplate::query()->findOrFail(app(EventCopier::class)->copy($source, $data['title'], $source->starts_at, asTemplate: true)->id);
        } else {
            $template = $this->create($request, new EventTemplate(['is_template' => true]));
        }

        return response()->json(['data' => Presenter::event($template->load('series', 'roles', 'installments', 'prices'), true)], 201);
    }

    /** Changes an event template, with the same fields as an event. A template is always a draft without a public page. */
    public function updateTemplate(Request $request, string $template): JsonResponse
    {
        $this->scope('events:write');
        $model = $this->change($request, EventTemplate::query()->where('public_id', $template)->firstOrFail());

        return response()->json(['data' => Presenter::event($model->load('series', 'roles', 'installments', 'prices'), true)]);
    }

    /** Deletes an event template. Events made from it stay. */
    public function destroyTemplate(string $template): JsonResponse
    {
        $this->scope('events:write');
        EventTemplate::query()->where('public_id', $template)->firstOrFail()->delete();

        return response()->json(['data' => null]);
    }

    private function create(Request $request, Event $event): Event
    {
        $data = $this->data($request, null, $event instanceof EventTemplate);

        return DB::transaction(function () use ($data, $event) {
            $event->fill($data['event'])->save();
            $this->saveRoles($event, $data['roles'] ?? []);
            $this->savePrices($event, $data['prices']);
            $this->saveInstallments($event, $data['installments']);

            return $event->refresh();
        });
    }

    private function change(Request $request, Event $model): Event
    {
        $data = $this->data($request, $model, $model instanceof EventTemplate);
        DB::transaction(function () use ($model, $data) {
            $model->update($data['event']);
            if ($data['roles'] !== null) {
                $this->saveRoles($model, $data['roles']);
            }
            $this->savePrices($model, $data['prices']);
            $this->saveInstallments($model, $data['installments']);
        });

        return $model->refresh();
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

    /**
     * Change the status or the travel of a participation. Cancelling (status cancelled, with an optional reason
     * that is stored) frees the place for the waitlist, tells the family and recalculates draft invoices, as in the
     * admin interface; a participation that is cancelled, declined, attended or a no-show cannot be cancelled.
     */
    public function updateParticipation(Request $request, int $participation): JsonResponse
    {
        $this->scope('events:write');
        $model = Participation::query()->with('event', 'person')->findOrFail($participation);
        $data = $request->validate([
            'status' => ['required_without:travel', Rule::in(Participation::STATUSES)], 'travel' => ['sometimes', Rule::in(['bus', 'car'])],
            'reason' => ['sometimes', 'nullable', 'string', 'max:250', 'prohibited_unless:status,cancelled'],
        ]);
        if (isset($data['travel'])) {
            Bus::set($model, (string) $data['travel']);
        }
        if (! isset($data['status'])) {
            // only the travel was changed
        } elseif ($data['status'] === 'cancelled') {
            abort_if(in_array($model->status, ['cancelled', 'declined', 'attended', 'no_show'], true), 422, 'This participation cannot be cancelled any more.');
            app(Registrations::class)->cancel($model, isset($data['reason']) && trim($data['reason']) !== '' ? trim($data['reason']) : null);
            if ($model->event !== null) {
                app(Corrections::class)->refreshDrafts($model->event);
            }
        } else {
            $model->fill(['status' => $data['status']])->save();
        }

        return response()->json(['data' => Presenter::participation($model->refresh()->load('event', 'person'))]);
    }

    /**
     * Offers a place out of turn, with a reason, until a date (end of that day). The family gets the offer by mail
     * and accepts or declines in the portal. 422 when there is no free place or the person already has one.
     */
    public function offer(Request $request, string $event): JsonResponse
    {
        $this->scope('events:write');
        $model = $this->event($event);
        $data = $request->validate([
            'person' => ['required', 'string'], 'role' => ['required', Rule::in(EventRole::KEYS)],
            'until' => ['required', 'date', 'after_or_equal:today'], 'reason' => ['required', 'string', 'max:250'],
        ]);
        $offered = app(Offers::class)->offer($model, $this->person($data['person']), $data['role'], Carbon::parse((string) $data['until'])->endOfDay(), $data['reason']);
        if ($offered === null) {
            return response()->json(['message' => (string) __('events.errors.no_place')], 422);
        }

        return response()->json(['data' => Presenter::participation($offered->load('event', 'person'))], 201);
    }

    /** Gives a free reserve place to somebody on the waitlist; the reason is required and recorded. */
    public function reservePlace(Request $request, int $participation): JsonResponse
    {
        $this->scope('events:write');
        $model = Participation::query()->with('event', 'person')->findOrFail($participation);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        abort_unless($model->status === 'waitlist', 422, 'Only somebody on the waitlist can get a reserve place.');
        try {
            app(Registrations::class)->assignReservePlace($model, $data['reason']);
        } catch (RegistrationException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => Presenter::participation($model->refresh()->load('event', 'person'))]);
    }

    /** Gives free reserve places of a role to the waitlist; answers with the number of places released. */
    public function releaseReserve(Request $request, string $event): JsonResponse
    {
        $this->scope('events:write');
        $model = $this->event($event);
        $data = $request->validate(['role' => ['required', Rule::in(EventRole::KEYS)], 'count' => ['required', 'integer', 'min:1', 'max:100000'], 'reason' => ['required', 'string', 'max:250']]);
        $role = $model->roles()->where('role_key', $data['role'])->firstOrFail();
        $released = app(ReservePlaces::class)->release($role, (int) $data['count'], $data['reason']);

        return response()->json(['data' => ['released' => $released, 'reserve_left' => $role->refresh()->reserveLeft()]]);
    }

    /** Dates of the event, automations planned before and after it, and their runs so far, in order of time. */
    public function schedule(string $event): JsonResponse
    {
        $this->scope('events:read');
        $keys = Automation::query()->pluck('key', 'id');
        $items = array_map(fn (array $i) => [
            'at' => $i['at']->toIso8601String(), 'what' => $i['what'], 'kind' => $i['kind'], 'state' => $i['state'],
            'automation' => $i['automation'] !== null ? $keys->get($i['automation']) : null, 'run' => $i['run'],
        ], app(Schedule::class)->ofEvent($this->event($event)));

        return response()->json(['data' => $items]);
    }

    /**
     * @return array{event: array<string, mixed>, roles: list<array<string, mixed>>|null, prices: list<array<string, mixed>>|null, installments: list<array<string, mixed>>|null}
     */
    private function data(Request $request, ?Event $event, bool $template = false): array
    {
        $need = $event === null ? 'required' : 'sometimes';
        $text = fn (int $max) => ['sometimes', 'nullable', 'string', 'max:'.$max];
        $request->merge(OneText::collapse($request->only('cancellation_text', 'installments'), ['cancellation_text', 'installments.*.label']));
        $data = $request->validate([
            'title' => [$need, 'string', 'max:200'],
            'series_id' => ['sometimes', 'nullable', 'integer', 'exists:event_series,id'],
            'status' => $template ? ['sometimes', Rule::in(['draft'])] : ['sometimes', Rule::in(Event::STATUSES)],
            'starts_at' => [$need, 'date'],
            'ends_at' => [$need, 'date'],
            'location' => $text(200), 'meeting_point' => $text(200), 'return_point' => $text(200), 'address' => $text(250),
            'map_url' => ['sometimes', 'nullable', 'url', 'starts_with:https://', 'max:500'],
            'contact_name' => $text(150), 'contact_email' => ['sometimes', 'nullable', 'email', 'max:254'], 'contact_phone' => $text(40),
            'description' => $text(20000),
            'registration_binding' => ['sometimes', 'boolean'], 'public_page' => $template ? ['sometimes', 'declined'] : ['sometimes', 'boolean'], 'reserve_auto_release' => ['sometimes', 'boolean'],
            'max_guests' => ['sometimes', 'integer', 'between:0,10'],
            'bus_booked' => ['sometimes', 'boolean'], 'bus_seats' => ['sometimes', 'nullable', 'integer', 'between:1,1000'],
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
            'roles.*.registration_form' => ['nullable', 'string', Rule::exists('forms', 'key')->where('kind', 'registration')],
            // Prices, payment plan and sibling discount, as in the section of the form.
            'sibling_mode' => ['sometimes', Rule::in(['none', 'staggered', 'all'])], 'sibling_order' => ['sometimes', Rule::in(['oldest', 'highest_price', 'registration'])],
            'sibling_amount' => ['sometimes', 'numeric', 'min:0', 'max:1000000'],
            'sibling_cap_position' => ['sometimes', 'nullable', 'integer', 'between:2,255'], 'sibling_min_children' => ['sometimes', 'integer', 'between:2,255'],
            'reminder_days_before' => ['sometimes', 'nullable', 'array', 'max:10'], 'reminder_days_before.*' => ['integer', 'between:0,365'],
            'cancellation_fee' => ['sometimes', 'numeric', 'min:0', 'max:1000000'],
            'cancellation_text' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'currency' => ['prohibited'],
            'prices' => ['sometimes', 'array', 'max:100'],
            'prices.*.role' => ['required', Rule::in(EventRole::KEYS)],
            'prices.*.min_age' => ['nullable', 'integer', 'between:0,120'], 'prices.*.max_age' => ['nullable', 'integer', 'between:0,120'],
            'prices.*.from_date' => ['nullable', 'date'], 'prices.*.until_date' => ['nullable', 'date'],
            'prices.*.price' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'installments' => ['sometimes', 'array', 'max:20'],
            'installments.*.key' => ['required', 'string', 'alpha_dash', 'max:32', 'distinct'],
            'installments.*.label' => ['sometimes', 'string', 'max:200'],
            'installments.*.kind' => ['sometimes', Rule::in(['fixed', 'rest', 'member'])],
            'installments.*.amount' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'installments.*.due_date' => ['nullable', 'date'], 'installments.*.due_days' => ['nullable', 'integer', 'between:0,3650'],
            'installments.*.membership_type' => ['nullable', 'string', 'exists:membership_types,key'],
            'installments.*.refundable' => ['sometimes', 'boolean'], 'installments.*.refund_until_days' => ['nullable', 'integer', 'between:0,3650'],
            'installments.*.confirms_place' => ['sometimes', 'boolean'], 'installments.*.discounts_apply' => ['sometimes', 'boolean'],
            'installments.*.paid_add_group' => ['nullable', 'integer', Rule::exists('groups', 'id')->where('kind', 'manual')],
            'installments.*.paid_remove_group' => ['nullable', 'integer', Rule::exists('groups', 'id')->where('kind', 'manual')],
        ]);
        $prices = array_key_exists('prices', $data) ? array_values($data['prices']) : null;
        $installments = array_key_exists('installments', $data) ? array_values($data['installments']) : null;
        unset($data['prices'], $data['installments']);
        foreach (['sibling_amount' => 'sibling_amount_cents', 'cancellation_fee' => 'cancellation_fee_cents'] as $key => $column) {
            if (array_key_exists($key, $data)) {
                $data[$column] = (int) round(((float) $data[$key]) * 100);
                unset($data[$key]);
            }
        }
        if (array_key_exists('reminder_days_before', $data)) {
            $data['reminder_days_before'] = $data['reminder_days_before'] ? array_values(array_map('intval', $data['reminder_days_before'])) : null;
        }
        if ($template) {
            $data = ['is_template' => true, 'status' => 'draft', 'public_page' => false] + $data;
        }
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
        // As in the form: a booked bus needs its number of seats.
        $value = fn (string $key) => array_key_exists($key, $data) ? $data[$key] : $event?->getAttribute($key);
        if ($value('bus_booked') && $value('bus_seats') === null) {
            $errors['bus_seats'][] = 'A booked bus needs the number of seats.';
        }
        foreach ($roles ?? [] as $i => $role) {
            if (isset($role['min_age'], $role['max_age']) && $role['max_age'] < $role['min_age']) {
                $errors["roles.{$i}.max_age"][] = 'The highest age must not be below the lowest age.';
            }
        }
        foreach ($prices ?? [] as $i => $price) {
            if (isset($price['min_age'], $price['max_age']) && $price['max_age'] < $price['min_age']) {
                $errors["prices.{$i}.max_age"][] = 'The highest age must not be below the lowest age.';
            }
            if (isset($price['from_date'], $price['until_date']) && Carbon::parse((string) $price['until_date'])->lt(Carbon::parse((string) $price['from_date']))) {
                $errors["prices.{$i}.until_date"][] = 'The last day must not be before the first day.';
            }
        }
        foreach ($installments ?? [] as $i => $installment) {
            $known = $event?->installments()->where('key', $installment['key'])->first();
            if ($known === null && ! isset($installment['label'])) {
                $errors["installments.{$i}.label"][] = 'A new instalment needs its label in German and English.';
            }
            if (($installment['kind'] ?? $known->kind ?? 'fixed') === 'member' && ! isset($installment['membership_type']) && $known?->membership_type_id === null) {
                $errors["installments.{$i}.membership_type"][] = 'An instalment for the membership fee needs the membership type.';
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['event' => $data, 'roles' => $roles, 'prices' => $prices, 'installments' => $installments];
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
            if (array_key_exists('registration_form', $role)) {
                $values['registration_form_id'] = $role['registration_form'] !== null ? Form::query()->where('key', $role['registration_form'])->where('kind', 'registration')->value('id') : null;
            }
            $model = $event->roles()->firstOrNew(['role_key' => $role['role']]);
            $model->fill($values)->save();
        }
    }

    /**
     * Prices by age or date: the list replaces the stored one, in its order.
     *
     * @param  list<array<string, mixed>>|null  $prices
     */
    private function savePrices(Event $event, ?array $prices): void
    {
        if ($prices === null) {
            return;
        }
        $event->prices()->get()->each->delete();
        foreach ($prices as $sort => $price) {
            $event->prices()->create([
                'role_key' => $price['role'], 'min_age' => $price['min_age'] ?? null, 'max_age' => $price['max_age'] ?? null, 'from_date' => $price['from_date'] ?? null,
                'until_date' => $price['until_date'] ?? null, 'price_cents' => (int) round(((float) $price['price']) * 100), 'sort' => $sort,
            ]);
        }
    }

    /**
     * The payment plan, by key: listed instalments are created or changed, the others are removed.
     *
     * @param  list<array<string, mixed>>|null  $installments
     */
    private function saveInstallments(Event $event, ?array $installments): void
    {
        if ($installments === null) {
            return;
        }
        foreach ($event->installments()->whereNotIn('key', array_column($installments, 'key'))->get() as $gone) {
            if (Invoice::query()->where('event_id', $event->id)->where('installment_key', $gone->key)->exists()) {
                throw ValidationException::withMessages(['installments' => ["The instalment {$gone->key} has invoices and cannot be removed."]]);
            }
            $gone->delete();
        }
        foreach ($installments as $sort => $item) {
            /** @var EventInstallment $model */
            $model = $event->installments()->firstOrNew(['key' => $item['key']]);
            $values = ['sort' => $sort] + array_intersect_key($item, array_flip(['label', 'kind', 'due_date', 'due_days', 'refundable', 'refund_until_days', 'confirms_place', 'discounts_apply']));
            if (array_key_exists('amount', $item)) {
                $values['amount_cents'] = $item['amount'] !== null ? (int) round(((float) $item['amount']) * 100) : null;
            }
            if (array_key_exists('membership_type', $item)) {
                $values['membership_type_id'] = $item['membership_type'] !== null ? MembershipType::query()->where('key', $item['membership_type'])->value('id') : null;
            }
            foreach (['paid_add_group' => 'paid_add_group_id', 'paid_remove_group' => 'paid_remove_group_id'] as $key => $column) {
                if (array_key_exists($key, $item)) {
                    $values[$column] = $item[$key];
                }
            }
            $model->fill($values + ($model->exists ? [] : ['kind' => 'fixed']))->save();
        }
    }
}
