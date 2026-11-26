<?php

namespace App\Http\Controllers\Api;

use App\Models\ArchivedInvoice;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Refund;
use App\Support\Api\Presenter;
use App\Support\Finance\Credits;
use App\Support\Finance\InvoicePdf;
use App\Support\Finance\Invoices;
use App\Support\Finance\Payments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Invoices, payments, refunds and the invoice archive. Scopes: finance:read, finance:write.
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

    /**
     * Issues a draft: it gets its number, reference and due date. As "Issue and send" in the admin interface, the
     * invoice is then sent to the payer by mail; with send false it is only issued. Only drafts can be issued (422).
     */
    public function issue(Request $request, int $invoice): JsonResponse
    {
        $this->scope('finance:write');
        $model = Invoice::query()->findOrFail($invoice);
        $send = (bool) ($request->validate(['send' => ['sometimes', 'boolean']])['send'] ?? true);
        $service = app(Invoices::class);
        try {
            $service->issue($model);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $sent = $send && $service->send($model);

        return response()->json(['data' => Presenter::invoice($model->refresh()->load('event', 'lines', 'payments'), true), 'sent' => $sent]);
    }

    /** Sends an issued invoice (again) to the payer by mail, with the PDF. Not for drafts (422). */
    public function send(int $invoice): JsonResponse
    {
        $this->scope('finance:write');
        $model = Invoice::query()->findOrFail($invoice);
        abort_if($model->status === 'draft', 422, 'A draft cannot be sent. Issue it first.');
        abort_unless(app(Invoices::class)->send($model), 422, 'The invoice has no payer with an e-mail address.');

        return response()->json(['data' => Presenter::invoice($model->load('event')), 'sent' => true], 202);
    }

    /**
     * Cancels an issued invoice with a credit note; the reason is required and printed on the credit note. Payments
     * of the invoice become a credit of the family with a refund proposal. The answer is the credit note.
     */
    public function cancel(Request $request, int $invoice): JsonResponse
    {
        $this->scope('finance:write');
        $model = Invoice::query()->findOrFail($invoice);
        $data = $request->validate(['reason' => ['required', 'string', 'max:120']]);
        try {
            $credit = app(Invoices::class)->cancel($model, $data['reason']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => Presenter::invoice($credit->refresh()->load('event', 'lines', 'payments'), true), 'cancelled' => Presenter::invoice($model->refresh()->load('event'))], 201);
    }

    /** Payments, newest first; filter by match (auto, manual, suggested, unmatched, credit, refunded). In pages. */
    public function payments(Request $request): JsonResponse
    {
        $this->scope('finance:read');
        $data = $request->validate(['match' => ['sometimes', 'nullable', 'string', 'max:20']]);
        $page = Payment::query()->with('invoice', 'suggestedInvoice', 'household')->when($data['match'] ?? null, fn ($q, $m) => $q->where('match', $m))
            ->orderByDesc('paid_on')->orderByDesc('id')->paginate($this->perPage());

        return response()->json(['data' => $page->getCollection()->map(fn (Payment $p) => $this->presentPayment($p))->values(), 'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    /**
     * Assigns a payment that is not assigned yet (match suggested or unmatched) to an open invoice. Without
     * "invoice" the suggested invoice is confirmed. More than the open amount becomes a credit of the family with
     * a refund proposal; a fully paid invoice sends the payment confirmation.
     */
    public function assignPayment(Request $request, int $payment): JsonResponse
    {
        $this->scope('finance:write');
        $model = Payment::query()->with('suggestedInvoice')->findOrFail($payment);
        $data = $request->validate(['invoice' => ['sometimes', 'nullable', 'integer']]);
        abort_unless(in_array($model->match, ['suggested', 'unmatched'], true), 422, 'This payment is already assigned.');
        $invoice = isset($data['invoice']) ? Invoice::query()->find((int) $data['invoice']) : ($model->match === 'suggested' ? $model->suggestedInvoice : null);
        abort_if($invoice === null, 422, isset($data['invoice']) ? 'Unknown invoice.' : 'The payment has no suggested invoice; send "invoice".');
        try {
            app(Payments::class)->assign($model, $invoice);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->presentPayment($model->refresh()->load('invoice', 'suggestedInvoice', 'household'))]);
    }

    /** Refund proposals, newest first; filter by status (proposed, done, declined). In pages. */
    public function refunds(Request $request): JsonResponse
    {
        $this->scope('finance:read');
        $data = $request->validate(['status' => ['sometimes', 'nullable', Rule::in(['proposed', 'done', 'declined'])]]);
        $page = Refund::query()->with('household')->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))->orderByDesc('id')->paginate($this->perPage());

        return response()->json(['data' => $page->getCollection()->map(fn (Refund $r) => $this->presentRefund($r))->values(), 'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    /** The bank transfer of a proposed refund was made on the given day (date, not in the future): the credit is used up. */
    public function refundDone(Request $request, int $refund): JsonResponse
    {
        $this->scope('finance:write');
        $model = Refund::query()->findOrFail($refund);
        $data = $request->validate(['date' => ['required', 'date', 'before_or_equal:today']]);
        abort_unless($model->status === 'proposed', 422, 'Only a proposed refund can be marked as done.');
        try {
            app(Credits::class)->markRefunded($model, Carbon::parse((string) $data['date']));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->presentRefund($model->refresh()->load('household'))]);
    }

    /** The family keeps the credit: the proposed refund is declined. */
    public function refundKeep(int $refund): JsonResponse
    {
        $this->scope('finance:write');
        $model = Refund::query()->findOrFail($refund);
        abort_unless($model->status === 'proposed', 422, 'Only a proposed refund can be declined.');
        app(Credits::class)->decline($model);

        return response()->json(['data' => $this->presentRefund($model->refresh()->load('household'))]);
    }

    /** Invoices from the system used before, newest first; search looks in number and payer. Read-only. In pages. */
    public function archive(Request $request): JsonResponse
    {
        $this->scope('finance:read');
        $data = $request->validate(['search' => ['sometimes', 'nullable', 'string', 'max:100'], 'status' => ['sometimes', 'nullable', Rule::in(ArchivedInvoice::STATUSES)]]);
        $page = ArchivedInvoice::query()->with('person')
            ->when($data['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('number', 'like', "%{$s}%")->orWhere('payer_name', 'like', "%{$s}%")))
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('issued_on')->orderByDesc('id')->paginate($this->perPage());

        return response()->json(['data' => $page->getCollection()->map(fn (ArchivedInvoice $i) => [
            'id' => $i->id, 'number' => $i->number, 'issued_on' => $i->issued_on?->toDateString(), 'payer' => $i->payer_name, 'person' => $i->person?->public_id,
            'total' => $i->total_cents / 100, 'currency' => $i->currency, 'status' => $i->status, 'paid_on' => $i->paid_on?->toDateString(), 'note' => $i->note, 'has_pdf' => $i->pdf_path !== null,
        ])->values(), 'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    /** @return array<string, mixed> */
    private function presentPayment(Payment $p): array
    {
        return [
            'id' => $p->id, 'amount' => $p->amount_cents / 100, 'paid_on' => $p->paid_on->toDateString(), 'method' => $p->method, 'payer' => $p->payer_name,
            'reference' => $p->reference, 'note' => $p->note, 'match' => $p->match, 'invoice' => $p->invoice_id, 'invoice_number' => $p->invoice?->number,
            'suggested_invoice' => $p->suggested_invoice_id, 'suggested_invoice_number' => $p->suggestedInvoice?->number, 'family' => $p->household?->public_id,
        ];
    }

    /** @return array<string, mixed> */
    private function presentRefund(Refund $r): array
    {
        return [
            'id' => $r->id, 'amount' => $r->amount_cents / 100, 'currency' => $r->currency, 'status' => $r->status, 'reason' => $r->reason, 'payer' => $r->payer_name,
            'iban' => $r->iban, 'family' => $r->household?->public_id, 'payment' => $r->payment_id, 'proposed_on' => $r->getAttribute('created_at')?->toDateString(), 'done_on' => $r->done_on?->toDateString(),
        ];
    }
}
