<?php

namespace App\Http\Controllers\Api;

use App\Models\Invoice;
use App\Support\Api\Presenter;
use App\Support\Finance\InvoicePdf;
use App\Support\Finance\Payments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Invoices and payments. Scopes: finance:read, finance:write.
 */
class FinanceController extends ApiController
{
    /** Invoices; filter by status (issued, partially_paid, paid, overdue …) and event. */
    public function index(Request $request): JsonResponse
    {
        $this->scope('finance:read');
        $page = Invoice::query()->with('event')
            ->when($request->query('status'), fn ($q, $s) => $s === 'overdue'
                ? $q->whereIn('status', ['issued', 'partially_paid'])->whereDate('due_on', '<', now()->toDateString())
                : $q->where('status', $s))
            ->when($request->query('event'), fn ($q, $e) => $q->whereHas('event', fn ($q) => $q->where('public_id', $e)))
            ->orderByDesc('id')->paginate($this->perPage());

        return response()->json(['data' => $page->getCollection()->map(fn (Invoice $i) => Presenter::invoice($i))->values(), 'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    public function show(int $invoice): JsonResponse
    {
        $this->scope('finance:read');

        return response()->json(['data' => Presenter::invoice(Invoice::query()->with('event', 'lines', 'payments')->findOrFail($invoice), true)]);
    }

    /** The invoice as PDF. */
    public function pdf(int $invoice): Response
    {
        $this->scope('finance:read');
        $model = Invoice::query()->findOrFail($invoice);
        $service = app(InvoicePdf::class);

        return response($service->render($model), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$service->fileName($model).'"']);
    }

    /** Record a payment; a fully paid invoice sends the payment confirmation. */
    public function pay(Request $request, int $invoice): JsonResponse
    {
        $this->scope('finance:write');
        $model = Invoice::query()->whereIn('status', ['issued', 'partially_paid'])->findOrFail($invoice);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'], 'paid_on' => ['required', 'date'],
            'method' => ['nullable', Rule::in(['bank', 'cash', 'other'])], 'note' => ['nullable', 'string', 'max:250'],
        ]);
        app(Payments::class)->record($model, (int) round(((float) $data['amount']) * 100), Carbon::parse($data['paid_on']), $data['method'] ?? 'bank', $data['note'] ?? null);

        return response()->json(['data' => Presenter::invoice($model->refresh()->load('event', 'lines', 'payments'), true)], 201);
    }
}
