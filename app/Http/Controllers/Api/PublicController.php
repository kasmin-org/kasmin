<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventRole;
use App\Models\FormField;
use App\Models\PublicForm;
use App\Support\Events\PublicForms;
use App\Support\Forms\RegistrationAnswers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public events and forms without a token (PF02, RG09), for own websites. Sending a form only sends the confirmation mail.
 */
class PublicController extends Controller
{
    public function __construct(private readonly PublicForms $forms, private readonly RegistrationAnswers $answers) {}

    /**
     * Events with a public page.
     *
     * @unauthenticated
     */
    public function events(): JsonResponse
    {
        $events = Event::query()->where('public_page', true)->whereNotIn('status', ['draft', 'archived'])->where('ends_at', '>=', now())->with('roles')->orderBy('starts_at')->limit(50)->get();

        return response()->json(['data' => $events->map(fn (Event $e) => [
            'id' => $e->public_id, 'title' => $e->title, 'status' => $e->status, 'starts_at' => $e->starts_at->toIso8601String(), 'ends_at' => $e->ends_at->toIso8601String(), 'location' => $e->getAttribute('location'), 'url' => route('public.event', $e->public_id),
            'roles' => $e->roles->where('registered_by', '!=', 'admin')->map(fn (EventRole $r) => ['role' => $r->role_key, 'min_age' => $r->min_age, 'max_age' => $r->max_age, 'price' => $r->price_cents / 100, 'places_left' => $r->directLeft()])->values()->all(),
        ])->values()]);
    }

    /**
     * Definition of a public form.
     *
     * @unauthenticated
     */
    public function form(string $slug): JsonResponse
    {
        $form = $this->find($slug);

        return response()->json(['data' => ['slug' => $form->slug, 'kind' => $form->kind, 'title' => $form->title, 'intro' => $form->intro, 'state' => $form->state(),
            'fields' => ($this->forms->questions($form)->fields ?? collect())->map(fn (FormField $f) => ['key' => $f->key, 'type' => $f->type, 'label' => $f->label, 'help' => $f->help, 'required' => $f->required, 'choices' => $f->choices()])->values()->all()]]);
    }

    /**
     * Sends a public form. The answer is always the same; the person confirms with the link in the mail.
     *
     * @unauthenticated
     */
    public function submit(Request $request, string $slug): JsonResponse
    {
        $form = $this->find($slug);
        abort_unless($form->state() === 'open', 403, 'This form is closed.');
        $questions = $this->forms->questions($form);
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:120'], 'last_name' => ['required', 'string', 'max:120'], 'email' => ['required', 'email', 'max:254'], 'phone' => ['nullable', 'string', 'max:40'],
            'locale' => ['required', 'in:de,en'], 'privacy' => ['accepted'],
        ] + ($form->kind !== 'interest' ? ['birth_date' => ['required', 'date', 'before:today', 'after:1900-01-01']] : []) + ($questions !== null ? $this->answers->rules($questions) : []));
        $this->forms->submit($form, $data);

        return response()->json(['message' => 'Please confirm with the link in the mail.'], 202);
    }

    private function find(string $slug): PublicForm
    {
        return PublicForm::query()->where('slug', $slug)->where('active', true)->with('event.roles')->firstOrFail();
    }
}
