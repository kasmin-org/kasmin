<?php

namespace App\Http\Controllers\Api;

use App\Models\AllocationRule;
use App\Models\CostCenter;
use App\Models\JournalEntry;
use App\Models\Period;
use App\Support\Accounting\AccountingException;
use App\Support\Accounting\Allocation;
use App\Support\Accounting\Budgets;
use App\Support\Accounting\Closing;
use App\Support\Accounting\DocumentBookings;
use App\Support\Accounting\Periods;
use App\Support\Api\AccountingPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Periods of the accounting (E1d.1, E1f, E1g, E1h): date ranges without gaps; dates of open periods can change and the
 * bookings move along; allocation keys; closing with a preview; reopening, which also reopens all later periods.
 * Scopes: finance:read, finance:write.
 */
class PeriodsController extends AccountingController
{
    /** All periods, newest first, with status and the cost centres active in them. */
    public function index(): JsonResponse
    {
        $this->scope('finance:read');

        return response()->json(['data' => Period::query()->orderByDesc('starts_on')->get()->map(fn (Period $p) => AccountingPresenter::period($p))->values()]);
    }

    /**
     * Creates a period. The first one starts the accounting; every further one follows the last without a gap
     * (starts_on may be left out then) and takes over its active cost centres and allocation keys.
     */
    public function store(Request $request): JsonResponse
    {
        $this->scope('finance:write');
        $last = Period::query()->orderByDesc('ends_on')->first();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'], 'starts_on' => [$last === null ? 'required' : 'sometimes', 'date'], 'ends_on' => ['required', 'date'],
        ]);
        $period = app(Periods::class)->create($data['name'], $data['starts_on'] ?? $last?->ends_on->copy()->addDay() ?? now(), $data['ends_on']);

        return response()->json(['data' => AccountingPresenter::period($period->refresh())], 201);
    }

    /**
     * Changes name and dates of an open period. Bookings belong to a period by their date, so they move along, and
     * the neighbouring period moves with it; moved says how many bookings now belong to another period. With
     * dry_run true nothing is saved and moved says how many would.
     */
    public function update(Request $request, string $period): JsonResponse
    {
        $this->scope('finance:write');
        $model = $this->find(Period::class, $period, 'period', true);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:80'], 'starts_on' => ['sometimes', 'date'], 'ends_on' => ['sometimes', 'date'], 'dry_run' => ['sometimes', 'boolean'],
        ]);
        if ($model->isClosed()) {
            throw AccountingException::because('closed', ['period' => $model->name]);
        }
        $start = Carbon::parse($data['starts_on'] ?? $model->starts_on);
        $end = Carbon::parse($data['ends_on'] ?? $model->ends_on);
        $periods = app(Periods::class);
        if ((bool) ($data['dry_run'] ?? false)) {
            return response()->json(['data' => AccountingPresenter::period($model), 'moved' => $periods->movingEntries($model, $start, $end)->count(), 'dry_run' => true]);
        }
        $moved = 0;
        DB::transaction(function () use ($data, $model, $start, $end, $periods, &$moved) {
            if (! $start->equalTo($model->starts_on) || ! $end->equalTo($model->ends_on)) {
                $moved = $periods->move($model, $start, $end)['moved'];
            }
            if (isset($data['name'])) {
                $model->update(['name' => $data['name']]);
            }
        });

        return response()->json(['data' => AccountingPresenter::period($model->refresh()), 'moved' => $moved]);
    }

    /**
     * The allocation key of every cost centre that is allocated: who bears how much of its costs at the closing.
     * kind fixed (amount), percent (percent of what is left after the fixed amounts), rest or share (of the income).
     */
    public function keys(string $period): JsonResponse
    {
        $this->scope('finance:read');
        $model = $this->find(Period::class, $period, 'period', true);

        return response()->json(['data' => CostCenter::query()->where('carries', false)->orderBy('sort')->orderBy('name')->get()
            ->map(fn (CostCenter $source) => $this->key($model, $source))->values()]);
    }

    /**
     * Replaces the key of one allocated cost centre in an open period: rules with target (a cost centre that carries
     * its result), kind and – for fixed – amount or – for percent – percent. The checks of the admin interface apply
     * (no target twice, at most 100 %, one rest, rest or share, nothing left unallocated). An empty list removes the key.
     */
    public function saveKey(Request $request, string $period, string $center): JsonResponse
    {
        $this->scope('finance:write');
        $model = $this->find(Period::class, $period, 'period', true);
        $source = $this->find(CostCenter::class, $center, 'cost_center', true);
        $data = $request->validate([
            'rules' => ['present', 'array', 'max:100'], 'rules.*.target' => ['required'], 'rules.*.kind' => ['required', Rule::in(Allocation::KINDS)],
            'rules.*.amount' => ['nullable', 'numeric', 'min:0', 'max:100000000'], 'rules.*.percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);
        if ($model->isClosed()) {
            throw AccountingException::because('closed', ['period' => $model->name]);
        }
        abort_if($source->carries, 422, 'Only a cost centre that is allocated has a key. Change its kind first.');
        $rules = [];
        foreach (array_values($data['rules']) as $i => $rule) {
            $target = $this->find(CostCenter::class, $rule['target'], "rules.{$i}.target");
            if (! $target->carries) {
                throw ValidationException::withMessages(["rules.{$i}.target" => [(string) __('accounting.errors.chain', ['name' => $target->name])]]);
            }
            $rules[] = ['target' => $target->id, 'kind' => (string) $rule['kind'], 'value' => match ($rule['kind']) {
                'fixed' => $this->cents($rule['amount'] ?? 0), 'percent' => (int) round(((float) ($rule['percent'] ?? 0)) * 100), default => 0,
            }];
        }
        $problems = Allocation::validate($rules);
        if ($problems !== []) {
            throw ValidationException::withMessages(['rules' => array_map(fn (string $p) => (string) __('accounting.'.$p), $problems)]);
        }
        DB::transaction(function () use ($model, $source, $rules) {
            AllocationRule::query()->where('period_id', $model->id)->where('source_cost_center_id', $source->id)->delete();
            foreach ($rules as $rule) {
                AllocationRule::query()->create(['period_id' => $model->id, 'source_cost_center_id' => $source->id, 'target_cost_center_id' => $rule['target'], 'kind' => $rule['kind'], 'value' => $rule['value']]);
            }
        });

        return response()->json(['data' => $this->key($model, $source)]);
    }

    /**
     * Books every invoice, credit note, payment and refund from the start of the first period on that has no booking
     * yet (e.g. after a booking failed); bookings that exist stay. Answers how many were made and what failed.
     */
    public function bookDocuments(): JsonResponse
    {
        $this->scope('finance:write');
        $bookings = app(DocumentBookings::class);
        $made = $bookings->backfill();

        return response()->json(['data' => ['booked' => $made, 'failures' => $bookings->failures]]);
    }

    /** The budget of a period: the planned result per cost centre (income minus costs, negative for planned costs). */
    public function budgets(string $period): JsonResponse
    {
        $this->scope('finance:read');

        return response()->json(['data' => $this->budgetList($this->find(Period::class, $period, 'period', true))]);
    }

    /**
     * Sets the planned result of the listed cost centres; amount 0 removes it, cost centres that are not listed keep
     * theirs. A budget books nothing, so closed periods can be planned too.
     */
    public function saveBudgets(Request $request, string $period): JsonResponse
    {
        $this->scope('finance:write');
        $model = $this->find(Period::class, $period, 'period', true);
        $data = $request->validate([
            'budgets' => ['required', 'array', 'max:500'], 'budgets.*.cost_center' => ['required'],
            'budgets.*.amount' => ['required', 'numeric', 'min:-1000000', 'max:1000000'],
        ]);
        $amounts = [];
        foreach (array_values($data['budgets']) as $i => $item) {
            $amounts[(int) $this->find(CostCenter::class, $item['cost_center'], "budgets.{$i}.cost_center")->getKey()] = $this->cents($item['amount']);
        }
        app(Budgets::class)->save($model, $amounts);

        return response()->json(['data' => $this->budgetList($model)]);
    }

    /** @return list<array{cost_center: array{id: int, name: string}|null, amount: float|int}> */
    private function budgetList(Period $period): array
    {
        $budgets = app(Budgets::class)->of($period);
        $list = [];
        foreach (CostCenter::query()->whereIn('id', array_keys($budgets))->orderBy('sort')->orderBy('name')->get() as $center) {
            $list[] = ['cost_center' => AccountingPresenter::ref($center), 'amount' => AccountingPresenter::amount($budgets[$center->id])];
        }

        return $list;
    }

    /**
     * What closing would do now: per cost centre the result, the allocation and the result after it; the allocations;
     * per fund the value before, the result and after; warnings (cut fixed amounts, income in no key, a fund that
     * becomes negative) and errors that block the closing. Nothing is saved.
     */
    public function closing(string $period): JsonResponse
    {
        $this->scope('finance:read');
        $model = $this->find(Period::class, $period, 'period', true);

        return response()->json(['data' => ['period' => AccountingPresenter::period($model)] + AccountingPresenter::closing(app(Closing::class)->preview($model))]);
    }

    /**
     * Closes the period, as the preview shows: books the allocations, carries every result into its fund, sets income
     * and expenses back to 0 and locks the period. Periods are closed in the order of their dates; with errors in the
     * preview the answer is 422.
     */
    public function close(string $period): JsonResponse
    {
        $this->scope('finance:write');
        $model = $this->find(Period::class, $period, 'period', true);
        $closing = app(Closing::class);
        $preview = $closing->preview($model);
        $closing->close($model);

        $bookings = JournalEntry::query()->where('period_closing_id', $model->id)->orderBy('number')->pluck('number')->all();

        return response()->json(['data' => ['period' => AccountingPresenter::period($model->refresh())] + AccountingPresenter::closing($preview) + ['bookings' => $bookings]]);
    }

    /**
     * Reopens a closed period with a reason. All later closed periods are reopened with it (their opening values
     * change) and their closing bookings are removed. Needs confirm true; without it the answer is 422 with the
     * periods that would be reopened.
     */
    public function reopen(Request $request, string $period): JsonResponse
    {
        $this->scope('finance:write');
        $model = $this->find(Period::class, $period, 'period', true);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500'], 'confirm' => ['sometimes', 'boolean']]);
        abort_unless($model->isClosed(), 422, 'The period is open.');
        $periods = app(Periods::class);
        $affected = $periods->reopening($model)->map(fn (Period $p) => AccountingPresenter::ref($p))->values()->all();
        if (! (bool) ($data['confirm'] ?? false)) {
            return response()->json(['message' => 'Reopening also reopens every later closed period: '.implode(', ', array_column($affected, 'name')).'. Send confirm true to reopen them.', 'periods' => $affected], 422);
        }

        return response()->json(['data' => $periods->reopen($model, $data['reason'])->map(fn (Period $p) => AccountingPresenter::period($p->refresh()))->values()]);
    }

    /** @return array<string, mixed> */
    private function key(Period $period, CostCenter $source): array
    {
        $rules = AllocationRule::query()->where('period_id', $period->id)->where('source_cost_center_id', $source->id)->with('target')->orderBy('id')->get();

        return ['cost_center' => AccountingPresenter::ref($source), 'rules' => $rules->map(fn (AllocationRule $r) => [
            'target' => AccountingPresenter::ref($r->target), 'kind' => $r->kind,
            'amount' => $r->kind === 'fixed' ? AccountingPresenter::amount((int) $r->value) : null, 'percent' => $r->kind === 'percent' ? $r->value / 100 : null,
        ])->values()->all()];
    }
}
