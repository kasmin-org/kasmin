<?php

namespace App\Http\Controllers\Api;

use App\Models\Webhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Webhooks: addresses Kasmin calls when something happens. Scope: settings:write. The signing secret is part of
 * the answer exactly once – when the webhook is created or gets a new secret – and never again.
 */
class WebhooksController extends ApiController
{
    public function index(): JsonResponse
    {
        $this->scope('settings:write');

        return response()->json(['data' => Webhook::query()->orderBy('id')->get()->map(fn (Webhook $w) => $this->present($w))->values()]);
    }

    /** Creates a webhook. The answer holds "secret" – store it now, it is not shown again. */
    public function store(Request $request): JsonResponse
    {
        $this->scope('settings:write');
        $secret = Str::random(48);
        $hook = Webhook::query()->create($this->data($request, true) + ['active' => true, 'secret' => $secret]);

        return response()->json(['data' => $this->present($hook) + ['secret' => $secret]], 201);
    }

    /** Changes name, address, events or whether the webhook is active. */
    public function update(Request $request, int $webhook): JsonResponse
    {
        $this->scope('settings:write');
        $hook = Webhook::query()->findOrFail($webhook);
        $hook->update($this->data($request, false));

        return response()->json(['data' => $this->present($hook)]);
    }

    /** Replaces the signing secret. The answer holds the new "secret" once; the old one stops working at once. */
    public function newSecret(int $webhook): JsonResponse
    {
        $this->scope('settings:write');
        $hook = Webhook::query()->findOrFail($webhook);
        $secret = Str::random(48);
        $hook->forceFill(['secret' => $secret])->save();

        return response()->json(['data' => $this->present($hook) + ['secret' => $secret]]);
    }

    /** Deletes a webhook with its delivery log. */
    public function destroy(int $webhook): JsonResponse
    {
        $this->scope('settings:write');
        Webhook::query()->findOrFail($webhook)->delete();

        return response()->json(['data' => null]);
    }

    /** @return array<string, mixed> */
    private function data(Request $request, bool $new): array
    {
        $need = $new ? 'required' : 'sometimes';
        $data = $request->validate([
            'name' => [$need, 'string', 'max:120'],
            'url' => [$need, 'url', 'starts_with:https://', 'max:500'],
            'events' => [$need, 'array', 'min:1'], 'events.*' => ['string', 'distinct', Rule::in(Webhook::EVENTS)],
            'active' => ['sometimes', 'boolean'],
            'secret' => ['prohibited'],
        ]);
        if (isset($data['events'])) {
            $data['events'] = array_values($data['events']);
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function present(Webhook $w): array
    {
        return ['id' => $w->id, 'name' => $w->name, 'url' => $w->url, 'events' => $w->events, 'active' => $w->active];
    }
}
