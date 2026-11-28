<?php

namespace App\Http\Controllers\Api;

use App\Models\CostCenter;
use App\Models\Fund;
use App\Models\Period;
use App\Support\Accounting\Accounting;
use App\Support\Accounting\CostCenters;
use App\Support\Api\AccountingPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Cost centres (E1a, E1e, E1f, E1j): what a booking was for. Scopes: finance:read, finance:write. One over all years,
 * active or not per period; it carries its result into a fund or is allocated to others. The rules are those of the
 * admin interface (CostCenters).
 */
class CostCentersController extends AccountingController
{
    /**
     * All cost centres with kind (carries, allocated), fund, the periods they are active in, the number of their
     * bookings and why they cannot be deleted.
     */
    public function index(): JsonResponse
    {
        $this->scope('finance:read');

        return response()->json(['data' => CostCenter::query()->with('fund')->orderBy('sort')->orderBy('name')->get()->map(fn (CostCenter $c) => AccountingPresenter::costCenter($c))->values()]);
    }

    /**
     * Creates a cost centre (kind carries or allocated, default carries; fund only for carries, default the general
     * fund). It is active in all open periods. Needs "Use cost centres" in the global settings.
     */
    public function store(Request $request): JsonResponse
    {
        $this->scope('finance:write');
        abort_unless(Accounting::costCenters(), 422, 'Cost centres are switched off. Switch on “Use cost centres” in the global settings first.');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', 'unique:cost_centers,name'], 'kind' => ['sometimes', Rule::in(['carries', 'allocated'])],
            'fund' => ['sometimes', 'nullable'], 'sort' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
        ]);
        $carries = ($data['kind'] ?? 'carries') === 'carries';
        $fund = $this->fund($data, $carries);
        $center = DB::transaction(function () use ($data, $carries, $fund) {
            $center = CostCenter::query()->create(['name' => $data['name'], 'carries' => $carries, 'fund_id' => $fund?->id, 'sort' => $data['sort'] ?? 0]);
            $center->periods()->syncWithoutDetaching(Period::query()->where('status', 'open')->pluck('id')->all());

            return $center;
        });

        return response()->json(['data' => AccountingPresenter::costCenter($center->refresh())], 201);
    }

    /** Changes name, fund (only of a cost centre that carries its result; empty = general fund) or order. */
    public function update(Request $request, string $center): JsonResponse
    {
        $this->scope('finance:write');
        $model = $this->find(CostCenter::class, $center, 'cost_center', true);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120', Rule::unique('cost_centers', 'name')->ignore($model->id)],
            'fund' => ['sometimes', 'nullable'], 'sort' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
        ]);
        if (array_key_exists('fund', $data)) {
            $data['fund_id'] = $this->fund($data, $model->carries)?->id;
        }
        $model->update(array_diff_key($data, ['fund' => true]));

        return response()->json(['data' => AccountingPresenter::costCenter($model->refresh())]);
    }

    /**
     * Changes the kind: carries (its result goes into a fund; its keys in open periods are removed) or allocated (no
     * fund; not while a key of an open period gives it an allocation, and at least one cost centre must carry).
     */
    public function kind(Request $request, string $center): JsonResponse
    {
        $this->scope('finance:write');
        $model = $this->find(CostCenter::class, $center, 'cost_center', true);
        $data = $request->validate(['kind' => ['required', Rule::in(['carries', 'allocated'])], 'fund' => ['sometimes', 'nullable']]);
        $carries = $data['kind'] === 'carries';
        app(CostCenters::class)->setCarries($model, $carries, $this->fund($data, $carries));

        return response()->json(['data' => AccountingPresenter::costCenter($model->refresh())]);
    }

    /**
     * Activates (active true) or deactivates the cost centre in an open period. Deactivating answers with warnings:
     * money left in its fund, an allocation it receives in the key of the period. The standard one is always active.
     */
    public function active(Request $request, string $center, string $period): JsonResponse
    {
        $this->scope('finance:write');
        $model = $this->find(CostCenter::class, $center, 'cost_center', true);
        $in = $this->find(Period::class, $period, 'period', true);
        $active = (bool) $request->validate(['active' => ['required', 'boolean']])['active'];
        $warnings = app(CostCenters::class)->setActive($model, $in, $active);

        return response()->json(['data' => AccountingPresenter::costCenter($model->refresh()), 'warnings' => $warnings]);
    }

    /** Makes the cost centre the standard one (used where a booking names none). */
    public function makeDefault(string $center): JsonResponse
    {
        $this->scope('finance:write');
        $model = $this->find(CostCenter::class, $center, 'cost_center', true);
        app(CostCenters::class)->makeDefault($model);

        return response()->json(['data' => AccountingPresenter::costCenter($model->refresh())]);
    }

    /**
     * Deletes a cost centre. Its bookings move to move_to, or – only typed-in bookings in open periods – are deleted
     * for good with delete_bookings true and confirm_count, the number of these bookings (instead of the sentence
     * typed in the admin interface). Not possible for the standard one, while a key of an open period gives it an
     * allocation, or with bookings in closed periods. A backup is made before.
     */
    public function destroy(Request $request, string $center): JsonResponse
    {
        $this->scope('finance:write');
        $model = $this->find(CostCenter::class, $center, 'cost_center', true);
        $data = $request->validate([
            'move_to' => ['sometimes', 'nullable'], 'delete_bookings' => ['sometimes', 'boolean'],
            'confirm_count' => ['required_if_accepted:delete_bookings', 'integer', 'min:0'],
        ]);
        $target = $this->findOptional(CostCenter::class, $data['move_to'] ?? null, 'move_to');
        $delete = (bool) ($data['delete_bookings'] ?? false);
        abort_if($delete && $target !== null, 422, 'Either move_to or delete_bookings, not both.');
        $count = app(CostCenters::class)->deletable($model)['count'];
        app(CostCenters::class)->delete($model, $target, $delete, $delete ? CostCenters::confirmation((int) $data['confirm_count']) : null);

        return response()->json(['data' => null, 'bookings' => $count, 'moved_to' => AccountingPresenter::ref($target), 'deleted_bookings' => $delete ? $count : 0]);
    }

    /**
     * The fund of a cost centre: only one that carries its result has one; none means the general fund.
     *
     * @param  array<string, mixed>  $data
     */
    private function fund(array $data, bool $carries): ?Fund
    {
        $fund = $this->findOptional(Fund::class, $data['fund'] ?? null, 'fund');
        abort_if($fund !== null && ! $carries, 422, 'A cost centre that is allocated has no fund.');

        return $fund;
    }
}
