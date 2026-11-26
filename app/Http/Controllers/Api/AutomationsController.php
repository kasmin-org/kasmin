<?php

namespace App\Http\Controllers\Api;

use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\User;
use App\Support\Api\ApiContext;
use App\Support\Api\AutomationInput;
use App\Support\Api\Presenter;
use App\Support\Automations\Engine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Automations, runs and approvals (AP01, AP02, X05). Scopes: automations:read, automations:write, automations:run, approvals:decide.
 * Runs started via the API always wait for a human decision unless the token has approvals:decide.
 */
class AutomationsController extends ApiController
{
    public function index(): JsonResponse
    {
        $this->scope('automations:read');

        return response()->json(['data' => Automation::query()->orderBy('key')->get()->map(fn (Automation $a) => Presenter::automation($a))->values()]);
    }

    /** One automation with its trigger, settings, mode and approvers. */
    public function show(string $automation): JsonResponse
    {
        $this->scope('automations:read');

        return response()->json(['data' => Presenter::automation(Automation::query()->where('key', $automation)->firstOrFail())]);
    }

    /**
     * Changes what the automation form allows: name, active, mode, max_items, approvals_needed, approver_roles,
     * trigger, trigger_config and config (each of the two objects replaces the stored one). Key and action cannot
     * be changed, so standard automations stay standard. Via MCP, changes that touch an approval also need the
     * scope approvals:decide (see AutomationInput::protect).
     */
    public function update(Request $request, string $automation): JsonResponse
    {
        $this->scope('automations:write');
        $model = Automation::query()->where('key', $automation)->firstOrFail();
        $model->fill(AutomationInput::check($model, $request->all()));
        AutomationInput::protect($model);
        $model->save();

        return response()->json(['data' => Presenter::automation($model)]);
    }

    /**
     * Creates an own automation (action custom): name, trigger and config (target, conditions, steps)
     * are required; trigger_config, mode, max_items, approvals_needed and approver_roles as in PATCH. As in the
     * admin interface it is created switched off; switch it on with PATCH (active).
     */
    public function store(Request $request): JsonResponse
    {
        $this->scope('automations:write');
        $input = $request->all();
        $missing = array_values(array_diff(['name', 'trigger', 'config'], array_keys($input)));
        if ($missing !== [] || array_key_exists('active', $input)) {
            throw ValidationException::withMessages(array_fill_keys($missing, ['This field is required.']) + (array_key_exists('active', $input) ? ['active' => ['A new automation is switched off; switch it on with PATCH.']] : []));
        }
        $model = new Automation(['key' => 'own_'.Str::lower(Str::random(10)), 'action' => 'custom', 'is_standard' => false, 'active' => false, 'trigger' => 'manual']);
        $model->syncOriginal();
        $model->fill(AutomationInput::check($model, $input));
        AutomationInput::protect($model);
        $model->save();

        return response()->json(['data' => Presenter::automation($model->refresh())], 201);
    }

    /** Deletes an own automation with its runs. Standard automations cannot be deleted (403); switch them off instead. */
    public function destroy(string $automation): JsonResponse
    {
        $this->scope('automations:write');
        $model = Automation::query()->where('key', $automation)->firstOrFail();
        abort_if($model->is_standard, 403, 'Standard automations cannot be deleted. Switch them off instead.');
        $model->delete();

        return response()->json(['data' => null]);
    }

    /** Dry run: what the automation would do now (nothing is changed). */
    public function preview(Request $request, string $automation): JsonResponse
    {
        $this->scope('automations:read');
        $model = Automation::query()->where('key', $automation)->firstOrFail();
        $event = $request->input('event') ? $this->event((string) $request->input('event')) : null;

        return response()->json(['data' => app(Engine::class)->preview($model, $event)]);
    }

    /** Start a run. Without approvals:decide it becomes an approval request for the approvers. */
    public function run(Request $request, string $automation): JsonResponse
    {
        $this->scope('automations:run');
        $model = Automation::query()->where('key', $automation)->firstOrFail();
        $event = $request->input('event') ? $this->event((string) $request->input('event')) : null;
        /** @var User $user */
        $user = $request->user();
        $run = app(Engine::class)->trigger($model, ApiContext::viaMcp() ? 'mcp' : 'api', $event, null, $user, ! ApiContext::allows('approvals:decide'));

        return $run === null
            ? response()->json(['data' => null, 'message' => 'Nothing to do.'])
            : response()->json(['data' => Presenter::run($run->load('automation', 'event'))], 201);
    }

    /** Runs, newest first; default: pending approval requests. In pages (page, per_page up to 200, default 50). */
    public function runs(Request $request): JsonResponse
    {
        $this->scope('automations:read');
        $status = (string) $request->query('status', 'pending_approval');
        $page = AutomationRun::query()->with('automation', 'event', 'decidedBy')->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderByDesc('id')->paginate($this->perPage());

        return response()->json(['data' => $page->getCollection()->map(fn (AutomationRun $r) => Presenter::run($r))->values(), 'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    public function showRun(int $run): JsonResponse
    {
        $this->scope('automations:read');

        return response()->json(['data' => Presenter::run(AutomationRun::query()->with('automation', 'event', 'decidedBy')->findOrFail($run))]);
    }

    /** Approve (optionally without some items) or deny a pending run. Needs approvals:decide and an approver role. */
    public function decide(Request $request, int $run): JsonResponse
    {
        $this->scope('approvals:decide');
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'deny'])], 'excluded' => ['array'], 'excluded.*' => ['string'], 'comment' => ['nullable', 'string', 'max:490'],
        ]);
        /** @var User $user */
        $user = $request->user();
        try {
            $model = app(Engine::class)->decide(AutomationRun::query()->findOrFail($run), $user, $data['decision'] === 'approve', array_values($data['excluded'] ?? []), $data['comment'] ?? null);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['data' => Presenter::run($model->load('automation', 'event', 'decidedBy'))]);
    }
}
