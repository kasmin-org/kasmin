<?php

namespace App\Http\Controllers\Api;

use App\Models\Account;
use App\Models\Fund;
use App\Models\JournalLine;
use App\Support\Accounting\Accounting;
use App\Support\Accounting\Balances;
use App\Support\Accounting\Journal;
use App\Support\Api\AccountingPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Funds (Fonds, reserves; pots of the equity, E1e.1, E1g): they collect the results of their cost centres over the
 * years and money moved between them by a decision of the board. Scopes: finance:read, finance:write.
 */
class FundsController extends AccountingController
{
    /** All funds with the balance today, the bank or cash account they lie on (and the difference to it) and their cost centres. */
    public function index(): JsonResponse
    {
        $this->scope('finance:read');

        return response()->json(['data' => Fund::query()->with('account')->orderByDesc('is_default')->orderBy('sort')->orderBy('name')->get()->map(fn (Fund $f) => AccountingPresenter::fund($f))->values()]);
    }

    /** Creates a fund (name; optional the bank or cash account, a note, the order). Needs "Use funds" in the global settings. */
    public function store(Request $request): JsonResponse
    {
        $this->scope('finance:write');
        abort_unless(Accounting::funds(), 422, 'Funds are switched off. Switch on “Use funds” in the global settings first.');
        $fund = Fund::query()->create($this->data($request, null));

        return response()->json(['data' => AccountingPresenter::fund($fund->refresh())], 201);
    }

    /** Changes name, account, note or order; only the fields that are sent change. */
    public function update(Request $request, string $fund): JsonResponse
    {
        $this->scope('finance:write');
        $model = $this->find(Fund::class, $fund, 'fund', true);
        $model->update($this->data($request, $model));

        return response()->json(['data' => AccountingPresenter::fund($model->refresh())]);
    }

    /**
     * Moves money to another fund (to) by a decision of the board: a booking of the kind fund_transfer on the date
     * (default today), with the reason as its text. The answer is the booking.
     */
    public function transfer(Request $request, string $fund): JsonResponse
    {
        $this->scope('finance:write');
        $from = $this->find(Fund::class, $fund, 'fund', true);
        $data = $request->validate([
            'to' => ['required'], 'amount' => ['required', 'numeric', 'min:0.01', 'max:100000000'], 'date' => ['sometimes', 'date'], 'text' => ['required', 'string', 'max:200'],
        ]);
        $to = $this->find(Fund::class, $data['to'], 'to');
        abort_if($to->is($from), 422, 'Choose another fund to move the money to.');
        $amount = $this->cents($data['amount']);
        $entry = app(Journal::class)->post(['date' => $data['date'] ?? now()->toDateString(), 'text' => $data['text'], 'kind' => 'fund_transfer'],
            [['account' => 'funds', 'debit' => $amount, 'fund' => $from], ['account' => 'funds', 'credit' => $amount, 'fund' => $to]]);

        return response()->json(['data' => AccountingPresenter::booking($entry->load(AccountingPresenter::BOOKING_WITH), true)], 201);
    }

    /** Deletes a fund that has no bookings and no cost centre uses; never the general fund. */
    public function destroy(string $fund): JsonResponse
    {
        $this->scope('finance:write');
        $model = $this->find(Fund::class, $fund, 'fund', true);
        abort_if($model->is_default, 422, 'The general fund cannot be deleted.');
        abort_if(app(Balances::class)->fund($model, '9999-12-31') !== 0 || $model->costCenters()->exists() || JournalLine::query()->where('fund_id', $model->id)->exists(), 422,
            'Only a fund without bookings that no cost centre uses can be deleted.');
        $model->delete();

        return response()->json(['data' => null]);
    }

    /** @return array<string, mixed> */
    private function data(Request $request, ?Fund $fund): array
    {
        $data = $request->validate([
            'name' => [$fund === null ? 'required' : 'sometimes', 'string', 'max:120', Rule::unique('funds', 'name')->ignore($fund?->id)],
            'account' => ['sometimes', 'nullable'], 'note' => ['sometimes', 'nullable', 'string', 'max:2000'], 'sort' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
        ]);
        if (array_key_exists('account', $data)) {
            $account = $this->findOptional(Account::class, $data['account'], 'account');
            abort_if($account !== null && $account->money === null, 422, 'A fund can only be assigned to a bank or cash account.');
            $data['account_id'] = $account?->id;
            unset($data['account']);
        }

        return $data;
    }
}
