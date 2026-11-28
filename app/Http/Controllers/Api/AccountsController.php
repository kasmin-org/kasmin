<?php

namespace App\Http\Controllers\Api;

use App\Models\Account;
use App\Models\CostCenter;
use App\Support\Api\AccountingPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The chart of accounts (E1): what is booked. Scopes: finance:read, finance:write. As in the admin interface, system
 * accounts (bank, receivables, funds …) keep their type, the type of an account with bookings cannot change, and only
 * own accounts without bookings can be deleted.
 */
class AccountsController extends AccountingController
{
    /**
     * All accounts in the order of the chart with type, money account (bank, cash), standard cost centre and the
     * balance today; bank and cash accounts also with the funds on them. Filter by type, money (bank, cash, any) and
     * active.
     */
    public function index(Request $request): JsonResponse
    {
        $this->scope('finance:read');
        $data = $request->validate([
            'type' => ['sometimes', 'nullable', Rule::in(Account::TYPES)], 'money' => ['sometimes', 'nullable', Rule::in(['bank', 'cash', 'any'])],
            'active' => ['sometimes', 'nullable', Rule::in(['0', '1', 'true', 'false'])],
        ]);
        $accounts = Account::query()->with('defaultCostCenter')
            ->when($data['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($data['money'] ?? null, fn ($q, $money) => $money === 'any' ? $q->whereNotNull('money') : $q->where('money', $money))
            ->when(isset($data['active']), fn ($q) => $q->where('active', in_array($data['active'], ['1', 'true'], true)))
            ->orderBy('sort')->orderBy('id')->get();

        return response()->json(['data' => $accounts->map(fn (Account $a) => AccountingPresenter::account($a))->values()]);
    }

    /** Creates an account: name and type are required. */
    public function store(Request $request): JsonResponse
    {
        $this->scope('finance:write');
        $account = Account::query()->create($this->data($request, null));

        return response()->json(['data' => AccountingPresenter::account($account->refresh())], 201);
    }

    /** Changes an account; only the fields that are sent change. */
    public function update(Request $request, string $account): JsonResponse
    {
        $this->scope('finance:write');
        $model = $this->find(Account::class, $account, 'account', true);
        $model->update($this->data($request, $model));

        return response()->json(['data' => AccountingPresenter::account($model->refresh())]);
    }

    /** Deletes an own account without bookings (422 for system accounts and accounts with bookings: switch them off instead). */
    public function destroy(string $account): JsonResponse
    {
        $this->scope('finance:write');
        $model = $this->find(Account::class, $account, 'account', true);
        abort_if($model->system_key !== null || $model->lines()->exists(), 422, 'System accounts and accounts with bookings cannot be deleted. Switch the account off (active false) instead.');
        $model->delete();

        return response()->json(['data' => null]);
    }

    /** @return array<string, mixed> */
    private function data(Request $request, ?Account $account): array
    {
        $need = $account === null ? 'required' : 'sometimes';
        $data = $request->validate([
            'number' => ['sometimes', 'nullable', 'string', 'max:20'],
            'name' => [$need, 'string', 'max:120', Rule::unique('accounts', 'name')->ignore($account?->id)],
            'type' => [$need, Rule::in(Account::TYPES)],
            'money' => ['sometimes', 'nullable', Rule::in(['bank', 'cash'])],
            'iban' => ['sometimes', 'nullable', 'string', 'max:42'],
            'default_cost_center' => ['sometimes', 'nullable'],
            'active' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
        ]);
        $type = (string) ($data['type'] ?? $account?->type);
        if ($account !== null && $type !== $account->type && ($account->system_key !== null || $account->lines()->exists())) {
            throw ValidationException::withMessages(['type' => [(string) __('accounting.type_locked')]]);
        }
        // Only assets are money accounts, only bank accounts have an IBAN, only income and expenses a standard cost centre.
        $money = array_key_exists('money', $data) ? $data['money'] : $account?->money;
        if ($type !== 'asset' && $money !== null) {
            abort_if(($data['money'] ?? null) !== null, 422, 'Only asset accounts can be bank or cash accounts.');
            $data['money'] = $money = null;
        }
        if (array_key_exists('iban', $data)) {
            $data['iban'] = $data['iban'] !== null ? strtoupper(str_replace(' ', '', (string) $data['iban'])) : null;
            abort_if($data['iban'] !== null && $money !== 'bank', 422, 'Only bank accounts have an IBAN.');
        }
        if (array_key_exists('default_cost_center', $data)) {
            $center = $this->findOptional(CostCenter::class, $data['default_cost_center'], 'default_cost_center');
            abort_if($center !== null && ! in_array($type, ['income', 'expense'], true), 422, 'Only income and expense accounts have a standard cost centre.');
            $data['default_cost_center_id'] = $center?->id;
            unset($data['default_cost_center']);
        } elseif (! in_array($type, ['income', 'expense'], true) && $account?->default_cost_center_id !== null) {
            $data['default_cost_center_id'] = null;
        }

        return $data;
    }
}
