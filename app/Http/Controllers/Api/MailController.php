<?php

namespace App\Http\Controllers\Api;

use App\Models\Event;
use App\Models\Mailing;
use App\Models\MailTemplate;
use App\Models\MailTemplateContent;
use App\Models\Person;
use App\Models\User;
use App\Support\Api\ApiContext;
use App\Support\Mail\Mailings;
use App\Support\Mail\Placeholders;
use App\Support\Mail\TemplateRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Mail templates and mailings. Scopes: mail:read, mail:write. Sending a mailing always needs a human decision unless the token
 * has approvals:decide (X05).
 */
class MailController extends ApiController
{
    /** All mail templates. */
    public function templates(): JsonResponse
    {
        $this->scope('mail:read');

        return response()->json(['data' => MailTemplate::query()->orderBy('key')->get()->map(fn (MailTemplate $t) => $this->presentTemplate($t))->values()]);
    }

    /** One template with its contents per language. */
    public function template(string $template): JsonResponse
    {
        $this->scope('mail:read');

        return response()->json(['data' => $this->presentTemplate($this->find($template), true)]);
    }

    /** Changes the contents of a template; every change is a new version. */
    public function updateTemplate(Request $request, string $template): JsonResponse
    {
        $this->scope('mail:write');
        $model = $this->find($template);
        $data = $request->validate([
            'contents' => ['required', 'array', 'min:1', 'max:2'],
            'contents.*.locale' => ['required', Rule::in(['de', 'en'])],
            'contents.*.subject' => ['required', 'string', 'max:250'],
            'contents.*.html' => ['required', 'string', 'max:200000'],
            'contents.*.text' => ['nullable', 'string', 'max:200000'],
        ]);
        foreach ($data['contents'] as $i => $content) {
            $errors = app(TemplateRenderer::class)->validate($content['subject'], $content['html'], $content['text'] ?? null);
            if ($errors !== []) {
                throw ValidationException::withMessages(["contents.{$i}" => $errors]);
            }
        }
        $model->keepFirstVersion();
        foreach ($data['contents'] as $content) {
            $model->contents()->updateOrCreate(['locale' => $content['locale']], ['subject' => $content['subject'], 'html' => $content['html'], 'text' => $content['text'] ?? null]);
        }
        $model->newVersion();

        return response()->json(['data' => $this->presentTemplate($model->refresh(), true)]);
    }

    /** Renders a template with example data, or the given contents without saving them. */
    public function preview(Request $request, string $template): JsonResponse
    {
        $this->scope('mail:read');
        $data = $request->validate(['locale' => ['nullable', Rule::in(['de', 'en'])], 'subject' => ['nullable', 'string', 'max:250'], 'html' => ['nullable', 'string', 'max:200000']]);
        $content = $this->find($template)->content($data['locale'] ?? 'en');
        $subject = (string) ($data['subject'] ?? $content?->subject);
        $html = (string) ($data['html'] ?? $content?->html);
        $errors = app(TemplateRenderer::class)->validate($subject, $html, null);
        if ($errors !== []) {
            return response()->json(['message' => $errors[0]], 422);
        }

        return response()->json(['data' => app(TemplateRenderer::class)->renderStrings($subject, $html, null, Placeholders::sample())]);
    }

    /** Mailings, newest first. */
    public function mailings(): JsonResponse
    {
        $this->scope('mail:read');

        return response()->json(['data' => Mailing::query()->with('template')->withCount('mails')->orderByDesc('id')->limit(100)->get()->map(fn (Mailing $m) => $this->presentMailing($m))->values()]);
    }

    /** One mailing with its recipients and the people who are left out, with the reason. */
    public function mailing(int $mailing): JsonResponse
    {
        $this->scope('mail:read');
        $model = Mailing::query()->with('template')->withCount('mails')->findOrFail($mailing);
        $plan = app(Mailings::class)->plan($model);

        return response()->json(['data' => $this->presentMailing($model) + [
            'recipients' => array_map(fn (array $m) => ['id' => $m['to']->public_id, 'name' => $m['to']->fullName(), 'about' => array_map(fn (Person $c) => $c->fullName(), $m['children'])], $plan['mails']),
            'excluded' => array_map(fn (array $e) => ['id' => $e['person']->public_id, 'name' => $e['person']->fullName(), 'reason' => $e['reason']], $plan['excluded']),
        ]]);
    }

    /** Creates a draft. Nothing is sent. */
    public function createMailing(Request $request): JsonResponse
    {
        $this->scope('mail:write');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'template' => ['required', 'string'],
            'groups' => ['nullable', 'array', 'max:50'], 'groups.*' => ['integer', 'exists:groups,id'],
            'people' => ['nullable', 'array', 'max:1000'], 'people.*' => ['string'],
            'event' => ['nullable', 'string'],
            'statuses' => ['nullable', 'array'], 'statuses.*' => ['string', 'max:20'],
            'routing' => ['nullable', Rule::in(['guardians', 'person', 'both'])],
            'one_per_family' => ['nullable', 'boolean'],
            'scheduled_at' => ['nullable', 'date'],
        ]);
        $mailing = Mailing::query()->create([
            'name' => $data['name'], 'mail_template_id' => $this->find($data['template'])->id, 'routing' => $data['routing'] ?? 'guardians', 'one_per_family' => $data['one_per_family'] ?? true, 'scheduled_at' => $data['scheduled_at'] ?? null,
            'recipients' => [
                'group_ids' => array_map('intval', $data['groups'] ?? []),
                'event_id' => isset($data['event']) ? Event::query()->where('public_id', $data['event'])->firstOrFail()->id : null,
                'statuses' => $data['statuses'] ?? [],
                'person_ids' => Person::query()->whereIn('public_id', $data['people'] ?? [])->whereNull('anonymised_at')->pluck('id')->all(),
            ],
        ]);

        return response()->json(['data' => $this->presentMailing($mailing->load('template')->loadCount('mails'))], 201);
    }

    /** Renders all mails of a draft for review. Nothing is sent. */
    public function prepareMailing(int $mailing): JsonResponse
    {
        $this->scope('mail:write');
        $model = Mailing::query()->findOrFail($mailing);
        abort_unless(in_array($model->status, ['draft', 'prepared'], true), 422, 'Only drafts can be prepared.');
        app(Mailings::class)->prepare($model);

        return response()->json(['data' => $this->presentMailing($model->refresh()->load('template')->loadCount('mails'))]);
    }

    /** Asks for the approval to send a prepared mailing; sends at once only with approvals:decide. */
    public function releaseMailing(int $mailing): JsonResponse
    {
        $this->scope('mail:write');
        $model = Mailing::query()->findOrFail($mailing);
        abort_unless($model->status === 'prepared', 422, 'Only prepared mailings can be sent.');
        $user = auth()->user();
        app(Mailings::class)->release($model, $user instanceof User ? $user : null, ! ApiContext::allows('approvals:decide'));

        return response()->json(['data' => $this->presentMailing($model->refresh()->load('template')->loadCount('mails'))], 202);
    }

    private function find(string $key): MailTemplate
    {
        return MailTemplate::query()->where('key', $key)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function presentTemplate(MailTemplate $t, bool $contents = false): array
    {
        $data = ['key' => $t->key, 'name' => $t->name, 'category' => $t->category, 'audience' => $t->audience, 'version' => $t->version, 'active' => (bool) $t->getAttribute('active')];

        return $contents ? $data + ['contents' => $t->contents()->get()->map(fn (MailTemplateContent $c) => ['locale' => $c->locale, 'subject' => $c->subject, 'html' => $c->html, 'text' => $c->text])->values()->all()] : $data;
    }

    /** @return array<string, mixed> */
    private function presentMailing(Mailing $m): array
    {
        return ['id' => $m->id, 'name' => $m->name, 'template' => $m->template?->key, 'status' => $m->status, 'routing' => $m->routing, 'one_per_family' => $m->one_per_family, 'mails_count' => (int) $m->getAttribute('mails_count')];
    }
}
