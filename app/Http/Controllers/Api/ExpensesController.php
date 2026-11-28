<?php

namespace App\Http\Controllers\Api;

use App\Models\Account;
use App\Models\Advance;
use App\Models\ExpenseClaim;
use App\Models\User;
use App\Support\Accounting\AccountingException;
use App\Support\Accounting\Advances;
use App\Support\Accounting\ExpenseClaims;
use App\Support\Api\ApiContext;
use App\Support\Finance\Iban;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Expense claims and advances (E1d.3). Scopes: finance:read, finance:write. IBANs are shown masked; the SEPA file of a
 * payout comes back via REST only, never to an AI assistant.
 */
class ExpensesController extends ApiController
{
    /** Expense claims, newest first; filter by status and person. In pages. */
    public function index(Request $request): JsonResponse
    {
        $this->scope('finance:read');
        $data = $request->validate(['status' => ['sometimes', 'nullable', Rule::in(ExpenseClaim::STATUSES)], 'person' => ['sometimes', 'nullable', 'string', 'max:40']]);
        $page = ExpenseClaim::query()->with('person', 'account', 'costCenter', 'event')
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($data['person'] ?? null, fn ($q, $p) => $q->whereHas('person', fn ($q) => $q->where('public_id', $p)))
            ->orderByDesc('date')->orderByDesc('id')->paginate($this->perPage());

        return response()->json(['data' => $page->getCollection()->map(fn (ExpenseClaim $c) => self::claim($c))->values(), 'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    /**
     * Enters a claim for a person, as the treasurer does in the admin interface: amount and purpose, or a flat rate
     * (with quantity). Without a receipt file. An IBAN given is stored for the refunds of the person.
     */
    public function store(Request $request): JsonResponse
    {
        $this->scope('finance:write');
        $data = $request->validate([
            'person' => ['required', 'string', 'max:40'], 'date' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required_without:flat_rate', 'nullable', 'numeric', 'min:0.01', 'max:100000'], 'purpose' => ['required_without:flat_rate', 'nullable', 'string', 'max:250'],
            'flat_rate' => ['nullable', 'string', 'max:80'], 'quantity' => ['nullable', 'numeric', 'min:0.01', 'max:100000'],
            'account' => ['nullable', 'integer'], 'cost_center' => ['nullable', 'integer'], 'event' => ['nullable', 'string', 'max:40'], 'advance' => ['nullable', 'integer'],
            'waived' => ['sometimes', 'boolean'], 'iban' => ['nullable', 'string', 'max:42'],
        ]);
        $person = $this->person((string) $data['person']);
        $event = isset($data['event']) ? $this->event((string) $data['event']) : null;
        $claims = app(ExpenseClaims::class);
        try {
            if (filled($data['iban'] ?? null)) {
                $claims->setIban($person, (string) $data['iban']);
            }
            $claim = $claims->submit($person, [
                'date' => (string) $data['date'], 'amount_cents' => isset($data['amount']) ? (int) round(((float) $data['amount']) * 100) : null,
                'purpose' => $data['purpose'] ?? null, 'flat_rate' => $data['flat_rate'] ?? null, 'quantity' => $data['quantity'] ?? null,
                'account_id' => $data['account'] ?? null, 'cost_center_id' => $data['cost_center'] ?? null, 'event_id' => $event?->id,
                'advance_id' => $data['advance'] ?? null, 'waived' => (bool) ($data['waived'] ?? false),
            ], $this->user());
        } catch (AccountingException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => self::claim($claim->refresh())], 201);
    }

    /** Approves a submitted claim; account and cost centre (ids) can be corrected. With the four-eyes setting not by the one who entered it. */
    public function approve(Request $request, int $claim): JsonResponse
    {
        $this->scope('finance:write');
        $model = ExpenseClaim::query()->findOrFail($claim);
        $data = $request->validate(['account' => ['nullable', 'integer'], 'cost_center' => ['nullable', 'integer']]);

        return $this->attempt(fn () => app(ExpenseClaims::class)->approve($model, $this->user(), $data['account'] ?? null, $data['cost_center'] ?? null));
    }

    /** Rejects a submitted claim; the reason is mailed to the person. */
    public function reject(Request $request, int $claim): JsonResponse
    {
        $this->scope('finance:write');
        $model = ExpenseClaim::query()->findOrFail($claim);
        $reason = (string) $request->validate(['reason' => ['required', 'string', 'max:1000']])['reason'];

        return $this->attempt(fn () => app(ExpenseClaims::class)->reject($model, $this->user(), $reason));
    }

    /** The person waives the refund of an approved claim: booked as a donation. */
    public function donate(int $claim): JsonResponse
    {
        $this->scope('finance:write');
        $model = ExpenseClaim::query()->findOrFail($claim);

        return $this->attempt(fn () => app(ExpenseClaims::class)->donate($model, $this->user()));
    }

    /**
     * Pays approved claims out of a bank or cash account (id, or "bank"/"cash" for the standard ones) on a day. From a
     * bank account the answer has the SEPA file (pain.001) as text in sepa_xml – not via MCP. No money is moved here;
     * the file is uploaded in online banking by a human.
     */
    public function pay(Request $request): JsonResponse
    {
        $this->scope('finance:write');
        $data = $request->validate([
            'claims' => ['required', 'array', 'min:1', 'max:500'], 'claims.*' => ['integer'], 'account' => ['required'],
            'date' => ['required', 'date'], 'per_person' => ['sometimes', 'boolean'],
        ]);
        $account = $this->moneyAccount($data['account']);
        $claims = ExpenseClaim::query()->whereIn('id', $data['claims'])->get();
        abort_unless($claims->count() === count(array_unique($data['claims'])), 404, 'Unknown expense claim.');
        try {
            $result = app(ExpenseClaims::class)->pay($claims, $account, (string) $data['date'], (bool) ($data['per_person'] ?? false), $this->user());
        } catch (AccountingException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $answer = ['data' => array_map(fn (ExpenseClaim $c) => self::claim($c), $result['claims'])];
        if ($result['sepa'] !== null && ! ApiContext::viaMcp()) {
            $answer['sepa_xml'] = $result['sepa'];
        }

        return response()->json($answer);
    }

    /** Advances, newest first; settled true/false and person. In pages. */
    public function advances(Request $request): JsonResponse
    {
        $this->scope('finance:read');
        $data = $request->validate(['settled' => ['sometimes', 'boolean'], 'person' => ['sometimes', 'nullable', 'string', 'max:40']]);
        $page = Advance::query()->with('person', 'account')
            ->when(array_key_exists('settled', $data), fn ($q) => $data['settled'] ? $q->whereNotNull('settled_on') : $q->whereNull('settled_on'))
            ->when($data['person'] ?? null, fn ($q, $p) => $q->whereHas('person', fn ($q) => $q->where('public_id', $p)))
            ->orderByDesc('paid_on')->orderByDesc('id')->paginate($this->perPage());

        return response()->json(['data' => $page->getCollection()->map(fn (Advance $a) => self::advance($a))->values(), 'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    /** Hands out an advance from a bank or cash account (booked at once). */
    public function storeAdvance(Request $request): JsonResponse
    {
        $this->scope('finance:write');
        $data = $request->validate([
            'person' => ['required', 'string', 'max:40'], 'amount' => ['required', 'numeric', 'min:0.01', 'max:100000'], 'account' => ['required'],
            'date' => ['required', 'date'], 'purpose' => ['nullable', 'string', 'max:250'],
        ]);
        $person = $this->person((string) $data['person']);
        $account = $this->moneyAccount($data['account']);
        try {
            $advance = app(Advances::class)->handOut($person, (int) round(((float) $data['amount']) * 100), $account, (string) $data['date'], $data['purpose'] ?? null, $this->user());
        } catch (AccountingException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => self::advance($advance)], 201);
    }

    /** Settles an advance: returned is exactly what is still open (0 if the receipts cover it), account where it goes. */
    public function settleAdvance(Request $request, int $advance): JsonResponse
    {
        $this->scope('finance:write');
        $model = Advance::query()->findOrFail($advance);
        $data = $request->validate(['returned' => ['required', 'numeric', 'min:0', 'max:100000'], 'account' => ['nullable'], 'date' => ['required', 'date']]);
        $into = isset($data['account']) ? $this->moneyAccount($data['account']) : null;
        try {
            app(Advances::class)->settle($model, (int) round(((float) $data['returned']) * 100), $into, (string) $data['date'], $this->user());
        } catch (AccountingException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => self::advance($model->refresh())]);
    }

    private function attempt(callable $change): JsonResponse
    {
        try {
            $claim = $change();
        } catch (AccountingException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => self::claim($claim->refresh())]);
    }

    private function moneyAccount(mixed $value): Account
    {
        $account = is_numeric($value) ? Account::query()->find((int) $value) : Account::query()->where('system_key', (string) $value)->first();
        abort_if($account === null || $account->money === null, 422, 'Choose a bank or cash account (id, or "bank" or "cash").');

        return $account;
    }

    private function user(): User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : abort(401);
    }

    /** @return array<string, mixed> */
    public static function claim(ExpenseClaim $c): array
    {
        return [
            'id' => $c->id, 'person' => $c->person->public_id, 'name' => $c->person->fullName(), 'date' => $c->date->toDateString(), 'amount' => $c->amount_cents / 100,
            'purpose' => $c->purpose, 'status' => $c->status, 'flat_rate' => $c->flat_rate, 'account' => $c->account_id, 'account_name' => $c->account?->name,
            'cost_center' => $c->cost_center_id, 'cost_center_name' => $c->costCenter?->name, 'event' => $c->event?->public_id, 'advance' => $c->advance_id,
            'waived' => $c->waived, 'receipt' => $c->receipt_path !== null, 'entered_centrally' => $c->enteredCentrally(), 'reason' => $c->reason,
            'decided_at' => $c->decided_at?->toIso8601String(), 'paid_on' => $c->paid_on?->toDateString(), 'paid_from' => $c->paid_from_account_id,
            'iban' => Iban::mask($c->iban ?? $c->person->payout_iban),
        ];
    }

    /** @return array<string, mixed> */
    public static function advance(Advance $a): array
    {
        return [
            'id' => $a->id, 'person' => $a->person->public_id, 'name' => $a->person->fullName(), 'amount' => $a->amount_cents / 100, 'paid_on' => $a->paid_on->toDateString(),
            'account' => $a->account_id, 'kind' => $a->account?->money, 'purpose' => $a->purpose, 'open' => $a->openCents() / 100, 'returned' => $a->returned_cents / 100,
            'settled_on' => $a->settled_on?->toDateString(),
        ];
    }
}
