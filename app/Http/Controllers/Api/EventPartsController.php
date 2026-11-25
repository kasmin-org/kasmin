<?php

namespace App\Http\Controllers\Api;

use App\Models\Event;
use App\Models\EventCost;
use App\Models\EventDocument;
use App\Models\EventRole;
use App\Models\RegistrationRound;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Registration rounds, costs and documents of an event – the sections of the event form. Scopes: events:read,
 * events:write; costs need finance:read, finance:write.
 */
class EventPartsController extends ApiController
{
    /** The registration rounds of an event: who gets places of a role, and when. */
    public function rounds(string $event): JsonResponse
    {
        $this->scope('events:read');

        return $this->roundList($this->event($event));
    }

    /**
     * Replaces the registration rounds, in the order sent. A round with "id" is changed, one without is new, and
     * rounds that are not listed are removed. kind: returning, selection, waitlist or open; mode (accept or
     * payment), closes_at (required) and group only for returning and selection; min_events only for selection.
     */
    public function saveRounds(Request $request, string $event): JsonResponse
    {
        $this->scope('events:write');
        $model = $this->event($event);
        $data = $request->validate([
            'rounds' => ['present', 'array', 'max:50'],
            'rounds.*.id' => ['sometimes', 'nullable', 'integer', 'distinct'],
            'rounds.*.role' => ['required', Rule::in(EventRole::KEYS)],
            'rounds.*.kind' => ['required', Rule::in(RegistrationRound::KINDS)],
            'rounds.*.mode' => ['sometimes', 'nullable', Rule::in(['accept', 'payment'])],
            'rounds.*.opens_at' => ['required', 'date'],
            'rounds.*.closes_at' => ['nullable', 'date'],
            'rounds.*.min_events' => ['sometimes', 'nullable', 'integer', 'between:0,20'],
            'rounds.*.group' => ['sometimes', 'nullable', 'integer', 'exists:groups,id'],
        ]);
        $rounds = array_values($data['rounds']);
        $errors = [];
        $known = $model->rounds()->pluck('id')->all();
        foreach ($rounds as $i => $round) {
            $offers = in_array($round['kind'], ['returning', 'selection'], true);
            if (isset($round['id']) && ! in_array((int) $round['id'], $known, true)) {
                $errors["rounds.{$i}.id"][] = 'This round does not belong to the event.';
            }
            if ($offers && ! isset($round['closes_at'])) {
                $errors["rounds.{$i}.closes_at"][] = 'A round that offers places needs the day it closes.';
            }
            if (isset($round['closes_at']) && Carbon::parse((string) $round['closes_at'])->lte(Carbon::parse((string) $round['opens_at']))) {
                $errors["rounds.{$i}.closes_at"][] = 'The round must close after it opens.';
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        DB::transaction(function () use ($model, $rounds) {
            $model->rounds()->whereNotIn('id', array_filter(array_column($rounds, 'id')))->get()->each->delete();
            foreach ($rounds as $sort => $round) {
                $offers = in_array($round['kind'], ['returning', 'selection'], true);
                $item = isset($round['id']) ? $model->rounds()->findOrFail((int) $round['id']) : new RegistrationRound(['event_id' => $model->id]);
                // What the form hides for a kind keeps its default, as there.
                $item->fill([
                    'role_key' => $round['role'], 'kind' => $round['kind'], 'mode' => $offers ? ($round['mode'] ?? 'accept') : 'accept',
                    'opens_at' => Carbon::parse((string) $round['opens_at']), 'closes_at' => isset($round['closes_at']) ? Carbon::parse((string) $round['closes_at']) : null,
                    'min_events' => $round['kind'] === 'selection' ? (int) ($round['min_events'] ?? 0) : 0, 'group_id' => $offers ? ($round['group'] ?? null) : null, 'sort' => $sort,
                ])->save();
            }
        });

        return $this->roundList($model);
    }

    /** The costs of an event, for the report "costs and income". Scope finance:read. */
    public function costs(string $event): JsonResponse
    {
        $this->scope('finance:read');

        return $this->costList($this->event($event));
    }

    /** Adds a cost: label, category (accommodation, food, transport, material, staff, other) and amount. Scope finance:write. */
    public function storeCost(Request $request, string $event): JsonResponse
    {
        $this->scope('finance:write');
        $model = $this->event($event);
        $model->costs()->create($this->costData($request, true) + ['amount_cents' => 0, 'sort' => (int) $model->costs()->max('sort') + 1]);

        return $this->costList($model, 201);
    }

    /** Changes a cost. Scope finance:write. */
    public function updateCost(Request $request, int $cost): JsonResponse
    {
        $this->scope('finance:write');
        $model = EventCost::query()->findOrFail($cost);
        $model->update($this->costData($request, false));

        return $this->costList($this->eventOf($model->event_id));
    }

    /** Removes a cost. Scope finance:write. */
    public function destroyCost(int $cost): JsonResponse
    {
        $this->scope('finance:write');
        $model = EventCost::query()->findOrFail($cost);
        $model->delete();

        return $this->costList($this->eventOf($model->event_id));
    }

    /** The documents of an event, e.g. the packing list, with who may see them (participants or public). */
    public function documents(string $event): JsonResponse
    {
        $this->scope('events:read');

        return $this->documentList($this->event($event));
    }

    /**
     * Adds a document. Send it as multipart/form-data with title, visibility (participants – the default – or
     * public) and file: PDF, JPEG or PNG, at most 10 MB, as in the event form.
     */
    public function storeDocument(Request $request, string $event): JsonResponse
    {
        $this->scope('events:write');
        $model = $this->event($event);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'], 'visibility' => ['sometimes', Rule::in(['participants', 'public'])],
            'file' => ['required', 'file', 'max:10240', 'mimetypes:application/pdf,image/jpeg,image/png'],
        ]);
        $path = $request->file('file')?->store(EventDocument::DIRECTORY, 'local');
        abort_if(! is_string($path), 422, 'The file could not be stored.');
        $model->documents()->create(['title' => $data['title'], 'visibility' => $data['visibility'] ?? 'participants', 'path' => $path, 'sort' => (int) $model->documents()->max('sort') + 1]);

        return $this->documentList($model, 201);
    }

    /** The file of a document. */
    public function downloadDocument(int $document): Response
    {
        $this->scope('events:read');
        $model = EventDocument::query()->findOrFail($document);
        $this->eventOf($model->event_id);
        abort_unless(Storage::disk('local')->exists($model->path), 404);
        $name = str($model->title)->ascii()->replaceMatches('/[^A-Za-z0-9_.-]+/', '-')->trim('-').'.'.pathinfo($model->path, PATHINFO_EXTENSION);

        return Storage::disk('local')->download($model->path, $name, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** Removes a document; its file is deleted unless a copy of the event still uses it. */
    public function destroyDocument(int $document): JsonResponse
    {
        $this->scope('events:write');
        $model = EventDocument::query()->findOrFail($document);
        $event = $this->eventOf($model->event_id);
        $model->delete();

        return $this->documentList($event);
    }

    /** Events only: parts of event templates are changed in the admin interface. */
    private function eventOf(int $id): Event
    {
        return Event::query()->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function costData(Request $request, bool $new): array
    {
        $data = $request->validate([
            'label' => [$new ? 'required' : 'sometimes', 'string', 'max:200'], 'category' => ['sometimes', 'nullable', Rule::in(EventCost::CATEGORIES)],
            'amount' => ['sometimes', 'numeric', 'min:0', 'max:10000000'],
        ]);
        if (array_key_exists('amount', $data)) {
            $data['amount_cents'] = (int) round(((float) $data['amount']) * 100);
            unset($data['amount']);
        }

        return $data;
    }

    private function roundList(Event $event): JsonResponse
    {
        return response()->json(['data' => $event->rounds()->get()->map(fn (RegistrationRound $r) => [
            'id' => $r->id, 'role' => $r->role_key, 'kind' => $r->kind, 'mode' => $r->mode, 'opens_at' => $r->opens_at?->toIso8601String(), 'closes_at' => $r->closes_at?->toIso8601String(),
            'min_events' => $r->min_events, 'group' => $r->group_id, 'active' => $r->active(),
        ])->values()]);
    }

    private function costList(Event $event, int $status = 200): JsonResponse
    {
        $costs = $event->costs()->get();

        return response()->json(['data' => $costs->map(fn (EventCost $c) => ['id' => (int) $c->getKey(), 'label' => $c->label, 'category' => $c->category, 'amount' => $c->amount_cents / 100])->values(), 'total' => $costs->sum('amount_cents') / 100], $status);
    }

    private function documentList(Event $event, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $event->documents()->get()->map(fn (EventDocument $d) => [
            'id' => $d->id, 'title' => $d->title, 'visibility' => $d->visibility, 'type' => pathinfo($d->path, PATHINFO_EXTENSION), 'download' => url('/api/v1/event-documents/'.$d->id.'/download'),
        ])->values()], $status);
    }
}
