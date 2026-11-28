<?php

namespace App\Http\Controllers\Api;

use App\Models\Account;
use App\Models\CostCenter;
use App\Models\Fund;
use App\Models\JournalEntry;
use App\Models\Period;
use App\Support\Accounting\Journal;
use App\Support\Api\AccountingPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The journal (E1): every booking with its lines. Scopes: finance:read, finance:write. Bookings are typed in with any
 * number of lines and are never changed or deleted, only reversed. Numbers run over all years.
 */
class BookingsController extends AccountingController
{
    /**
     * Bookings, newest first, with their lines. Filter by date (from, to), period, account (bookings with a line on
     * it), cost_center, kind and search (in the text). In pages.
     */
    public function index(Request $request): JsonResponse
    {
        $this->scope('finance:read');
        $data = $request->validate([
            'from' => ['sometimes', 'nullable', 'date'], 'to' => ['sometimes', 'nullable', 'date'], 'period' => ['sometimes', 'nullable'], 'account' => ['sometimes', 'nullable'],
            'cost_center' => ['sometimes', 'nullable'], 'kind' => ['sometimes', 'nullable', Rule::in(JournalEntry::KINDS)], 'search' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);
        $period = $this->findOptional(Period::class, $data['period'] ?? null, 'period');
        $account = $this->findOptional(Account::class, $data['account'] ?? null, 'account');
        $center = $this->findOptional(CostCenter::class, $data['cost_center'] ?? null, 'cost_center');
        $page = JournalEntry::query()->with(AccountingPresenter::BOOKING_WITH)
            ->when($data['from'] ?? null, fn (Builder $q, $from) => $q->whereDate('date', '>=', $from))
            ->when($data['to'] ?? null, fn (Builder $q, $to) => $q->whereDate('date', '<=', $to))
            ->when($period, fn (Builder $q, Period $p) => $q->whereBetween('date', [$p->starts_on->toDateString(), $p->ends_on->toDateString()]))
            ->when($account, fn (Builder $q, Account $a) => $q->whereHas('lines', fn ($l) => $l->where('account_id', $a->id)))
            ->when($center, fn (Builder $q, CostCenter $c) => $q->whereHas('lines', fn ($l) => $l->where('cost_center_id', $c->id)))
            ->when($data['kind'] ?? null, fn (Builder $q, $kind) => $q->where('kind', $kind))
            ->when($data['search'] ?? null, fn (Builder $q, $search) => $q->where('text', 'like', '%'.$search.'%'))
            ->orderByDesc('number')->paginate($this->perPage());

        return response()->json(['data' => $page->getCollection()->map(fn (JournalEntry $e) => AccountingPresenter::booking($e))->values(), 'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    /** One booking with its lines, the period of its date and the booking it reverses or that reverses it. */
    public function show(int $booking): JsonResponse
    {
        $this->scope('finance:read');

        return response()->json(['data' => AccountingPresenter::booking(JournalEntry::query()->with(AccountingPresenter::BOOKING_WITH)->findOrFail($booking), true)]);
    }

    /**
     * Books a typed-in booking: date (in an open period), text, receipt number and at least two lines with account
     * and debit or credit (amounts as decimals). Debit and credit must add up to the same amount. Lines on income
     * and expenses get a cost centre (else the standard one of the account, else the standard cost centre; it must
     * be active in the period), lines on the equity of the funds a fund (else the general fund).
     */
    public function store(Request $request): JsonResponse
    {
        $this->scope('finance:write');
        $data = $request->validate([
            'date' => ['required', 'date'], 'text' => ['required', 'string', 'max:250'], 'receipt' => ['sometimes', 'nullable', 'string', 'max:120'],
            'lines' => ['required', 'array', 'min:2', 'max:200'], 'lines.*.account' => ['required'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0', 'max:100000000'], 'lines.*.credit' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'lines.*.cost_center' => ['nullable'], 'lines.*.fund' => ['nullable'], 'lines.*.text' => ['nullable', 'string', 'max:250'],
            'lines.*.person' => ['nullable', 'string', 'max:40'],
        ]);
        $lines = [];
        foreach (array_values($data['lines']) as $i => $line) {
            $lines[] = [
                'account' => $this->find(Account::class, $line['account'], "lines.{$i}.account"),
                'debit' => $this->cents($line['debit'] ?? 0), 'credit' => $this->cents($line['credit'] ?? 0),
                'cost_center' => $this->findOptional(CostCenter::class, $line['cost_center'] ?? null, "lines.{$i}.cost_center"),
                'fund' => $this->findOptional(Fund::class, $line['fund'] ?? null, "lines.{$i}.fund"),
                'person_id' => filled($line['person'] ?? null) ? $this->person((string) $line['person'])->id : null, // the public id, as everywhere in the API
                'text' => $line['text'] ?? null,
            ];
        }
        $entry = app(Journal::class)->post(['date' => $data['date'], 'text' => $data['text'], 'receipt' => $data['receipt'] ?? null], $lines);

        return response()->json(['data' => AccountingPresenter::booking($entry->load(AccountingPresenter::BOOKING_WITH), true)], 201);
    }

    /**
     * Reverses a booking: a counter-booking with the same amounts on the opposite sides, dated on the original date
     * while its period is open, else today (or date). Allocations and closings are not reversed but removed by
     * reopening the period. The answer is the reversal.
     */
    public function reverse(Request $request, int $booking): JsonResponse
    {
        $this->scope('finance:write');
        $entry = JournalEntry::query()->with('lines')->findOrFail($booking);
        $data = $request->validate(['text' => ['sometimes', 'nullable', 'string', 'max:250'], 'date' => ['sometimes', 'nullable', 'date']]);
        $reversal = app(Journal::class)->reverse($entry, $data['text'] ?? null, $data['date'] ?? null);

        return response()->json(['data' => AccountingPresenter::booking($reversal->load(AccountingPresenter::BOOKING_WITH), true)], 201);
    }
}
