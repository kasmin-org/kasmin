<?php

namespace App\Http\Controllers\Api;

use App\Models\Consent;
use App\Models\FormField;
use App\Models\FormResponse;
use App\Models\Person;
use App\Support\Api\ApiContext;
use App\Support\Audit\Audit;
use App\Support\Forms\RegistrationAnswers;
use App\Support\Privacy\DataExport;
use App\Support\Privacy\Privacy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Data protection: consents, deletion requests, the data export of a person and anonymising. Scopes: audit:read to
 * read, privacy:write to anonymise.
 */
class PrivacyController extends ApiController
{
    /** Consents and opt-outs with their proof, newest first; filter by kind and person. In pages. */
    public function consents(Request $request): JsonResponse
    {
        $this->scope('audit:read');
        $data = $request->validate(['kind' => ['sometimes', 'nullable', 'string', 'max:32'], 'person' => ['sometimes', 'nullable', 'string']]);
        $person = isset($data['person']) ? Person::query()->where('public_id', $data['person'])->firstOrFail() : null;
        $page = Consent::query()->with('person')->when($data['kind'] ?? null, fn ($q, $k) => $q->where('kind', $k))
            ->when($person !== null, fn ($q) => $q->where('person_id', $person?->id))->orderByDesc('id')->paginate($this->perPage());

        return response()->json(['data' => $page->getCollection()->map(fn (Consent $c) => [
            'id' => (int) $c->getKey(), 'person' => $c->person?->public_id, 'name' => $c->person?->fullName(), 'kind' => $c->kind, 'granted' => $c->granted,
            'source' => $c->getAttribute('source'), 'at' => $c->getAttribute('created_at')?->toIso8601String(),
        ])->values(), 'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    /** People who asked for their data to be deleted and are not anonymised yet, each with what would be deleted and kept. */
    public function deletionRequests(): JsonResponse
    {
        $this->scope('audit:read');
        $privacy = app(Privacy::class);

        return response()->json(['data' => $privacy->openRequests()->map(fn (Person $p) => [
            'person' => $p->public_id, 'name' => $p->fullName(), 'plan' => $privacy->plan($p), 'refused' => $this->refusals($p),
        ])->values()]);
    }

    /**
     * The copy of the data of a person and the children it is a legal guardian of – the same export as in the admin
     * interface and the portal. Needs audit:read and people:read, and health:read when the export holds answers to
     * confidential questions (e.g. a safety sheet). Every export is recorded in the audit log.
     */
    public function export(string $person): JsonResponse
    {
        $this->scope('audit:read');
        $this->scope('people:read');
        $model = $this->person($person);
        if ($this->hasConfidentialAnswers($model)) {
            $this->scope('health:read');
        }
        Audit::record('privacy.data_exported', $model, null, ['source' => ApiContext::viaMcp() ? 'mcp' : 'api']);

        return response()->json(['data' => app(DataExport::class)->for($model)]);
    }

    /**
     * Anonymises a person; this cannot be undone. With preview true nothing is changed and the answer shows what
     * would be deleted and kept. Otherwise a reason is required. Refused (422, with "reasons") when the admin
     * interface would refuse, e.g. while the person still has a role.
     */
    public function anonymise(Request $request, string $person): JsonResponse
    {
        $this->scope('privacy:write');
        $model = $this->person($person);
        $data = $request->validate(['preview' => ['sometimes', 'boolean'], 'reason' => ['required_unless:preview,true,1', 'nullable', 'string', 'max:250']]);
        $privacy = app(Privacy::class);
        $plan = $privacy->plan($model);
        $refused = $this->refusals($model);
        if ((bool) ($data['preview'] ?? false)) {
            return response()->json(['data' => ['anonymised' => false, 'plan' => $plan, 'refused' => $refused]]);
        }
        if ($refused !== []) {
            return response()->json(['message' => $refused[0], 'reasons' => $refused], 422);
        }
        try {
            $privacy->anonymise($model, trim((string) $data['reason']));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'reasons' => [$e->getMessage()]], 422);
        }

        return response()->json(['data' => ['anonymised' => true, 'plan' => $plan, 'refused' => []]]);
    }

    /** @return list<string> why the person cannot be anonymised now */
    private function refusals(Person $person): array
    {
        return $person->activeRoleKeys() !== [] ? [(string) __('privacy.has_roles')] : [];
    }

    /** Whether the export of the person (with the children it is a legal guardian of) holds answers to confidential questions. */
    private function hasConfidentialAnswers(Person $person): bool
    {
        $answers = app(RegistrationAnswers::class);
        foreach ([$person, ...$person->wards()->get()->all()] as $one) {
            foreach (FormResponse::query()->where('person_id', $one->id)->with('form.fields')->get() as $response) {
                if ($response->form?->fields->contains(fn (FormField $f) => $f->isConfidential()) === true) {
                    return true;
                }
            }
            foreach ($one->participations()->with('event')->get() as $participation) {
                $form = $participation->event !== null ? $answers->form($participation->event, $participation->role_key) : null;
                if ($form !== null && $form->fields->contains(fn (FormField $f) => $f->isConfidential()) && $answers->values($participation, $form) !== []) {
                    return true;
                }
            }
        }

        return false;
    }
}
