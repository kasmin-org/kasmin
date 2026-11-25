<?php

namespace App\Http\Controllers\Api;

use App\Models\FormField;
use App\Models\PublicForm;
use App\Models\PublicSubmission;
use App\Support\Events\PublicForms;
use App\Support\Forms\FormValue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Public forms and what was sent with them. Scopes: people:read, people:write. Answers to confidential questions
 * are never part of an answer here.
 */
class PublicFormsController extends ApiController
{
    /** The public forms with their address, kind and where they lead (event, role, group). */
    public function index(): JsonResponse
    {
        $this->scope('people:read');

        return response()->json(['data' => PublicForm::query()->with('event')->orderBy('slug')->get()->map(fn (PublicForm $f) => [
            'id' => $f->id, 'slug' => $f->slug, 'kind' => $f->kind, 'title' => $f->title, 'event' => $f->event?->public_id, 'role' => $f->role_key, 'group' => $f->group_id,
            'needs_approval' => $f->needs_approval, 'active' => $f->active, 'opens_at' => $f->opens_at?->toIso8601String(), 'closes_at' => $f->closes_at?->toIso8601String(),
            'url' => route('public.form', $f->slug),
        ])->values()]);
    }

    /**
     * Sent forms whose address was confirmed, newest first; filter by status (pending, done, declined, failed). In
     * pages. The answers are deleted once a submission is carried out or declined.
     */
    public function submissions(Request $request): JsonResponse
    {
        $this->scope('people:read');
        $data = $request->validate(['status' => ['sometimes', 'nullable', Rule::in(['pending', 'done', 'declined', 'failed'])]]);
        $page = PublicSubmission::query()->where('status', '!=', 'unconfirmed')->with('publicForm', 'person')
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))->orderByDesc('id')->paginate($this->perPage());

        return response()->json(['data' => $page->getCollection()->map(fn (PublicSubmission $s) => $this->present($s))->values(), 'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    /** Approves a pending application: it is carried out (person, group, registration – as the form says). */
    public function accept(int $submission): JsonResponse
    {
        $this->scope('people:write');
        $model = PublicSubmission::query()->where('status', '!=', 'unconfirmed')->findOrFail($submission);
        abort_unless($model->status === 'pending', 422, 'Only a pending submission can be accepted.');
        app(PublicForms::class)->approve($model);

        return response()->json(['data' => $this->present($model->refresh()->load('publicForm', 'person'))]);
    }

    /** Declines a pending application, with an optional note; its answers are deleted. */
    public function reject(Request $request, int $submission): JsonResponse
    {
        $this->scope('people:write');
        $model = PublicSubmission::query()->where('status', '!=', 'unconfirmed')->findOrFail($submission);
        $data = $request->validate(['note' => ['sometimes', 'nullable', 'string', 'max:250']]);
        abort_unless($model->status === 'pending', 422, 'Only a pending submission can be rejected.');
        app(PublicForms::class)->decline($model, $data['note'] ?? null);

        return response()->json(['data' => $this->present($model->refresh()->load('publicForm', 'person'))]);
    }

    /** @return array<string, mixed> */
    private function present(PublicSubmission $s): array
    {
        $data = $s->data ?? [];
        $answers = null;
        $hidden = [];
        if ($s->data !== null) {
            $answers = array_intersect_key($data, array_flip(['first_name', 'last_name', 'email', 'birth_date', 'phone']));
            $form = $s->publicForm !== null ? app(PublicForms::class)->questions($s->publicForm) : null;
            foreach ($form->fields ?? [] as $field) {
                /** @var FormField $field */
                if ($field->isConfidential()) {
                    $hidden[] = $field->key;
                } else {
                    $answers['values'][$field->key] = FormValue::text($field, $data['values'][$field->key] ?? null);
                }
            }
        }
        $name = $s->person?->fullName() ?? trim(($data['first_name'] ?? '').' '.($data['last_name'] ?? ''));

        return [
            'id' => $s->id, 'form' => $s->publicForm?->slug, 'status' => $s->status, 'name' => $name !== '' ? $name : null, 'person' => $s->person?->public_id,
            'note' => $s->note, 'created_at' => $s->created_at->toIso8601String(), 'confirmed_at' => $s->confirmed_at?->toIso8601String(),
            'answers' => $answers, 'hidden' => $hidden,
        ];
    }
}
