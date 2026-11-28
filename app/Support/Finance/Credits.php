<?php

namespace App\Support\Finance;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Refund;
use App\Support\Accounting\DocumentBookings;
use App\Support\Audit\Audit;
use App\Support\Mail\Notifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Credits of families (overpayments, cancelled invoices) and refunds (PY05, RF02). A credit is a payment without invoice
 * that belongs to a family; it is used for another invoice or paid back.
 */
class Credits
{
    public function __construct(private readonly DocumentBookings $bookings) {}

    /** Proposes to pay a credit back; the IBAN is the one the payment came from. */
    public function proposeRefund(Payment $payment, string $reason, ?int $payerId = null): ?Refund
    {
        if ($payment->invoice_id !== null || $payment->amount_cents <= 0 || Refund::query()->where('payment_id', $payment->id)->whereIn('status', ['proposed', 'done'])->exists()) {
            return null;
        }
        $refund = Refund::query()->create([
            'household_id' => $payment->household_id, 'payer_id' => $payerId, 'payment_id' => $payment->id, 'amount_cents' => $payment->amount_cents,
            'iban' => $payment->iban, 'payer_name' => $payment->payer_name, 'reason' => mb_substr($reason, 0, 250),
        ]);
        app(Notifier::class)->event('payments', __('finance.notify_refund', ['amount' => Invoice::money($refund->amount_cents, $refund->currency)]), url('/admin/refunds'));

        return $refund;
    }

    /** The bank transfer was made: the credit is used up. */
    public function markRefunded(Refund $refund, Carbon $on): void
    {
        DB::transaction(function () use ($refund, $on) {
            $refund = Refund::query()->whereKey($refund->id)->lockForUpdate()->firstOrFail();
            $payment = $refund->payment()->lockForUpdate()->first();
            if ($refund->status !== 'proposed' || $payment === null || $payment->invoice_id !== null || $payment->amount_cents !== $refund->amount_cents) {
                throw new RuntimeException(__('finance.refund_outdated'));
            }
            $payment->forceFill(['match' => 'refunded'])->save();
            $refund->forceFill(['status' => 'done', 'done_on' => $on->toDateString(), 'decided_by_id' => auth()->id()])->save();
            Audit::record('refund.done', $refund, null, ['amount_cents' => $refund->amount_cents]);
            $this->bookings->refundPaid($refund);
        });
    }

    /** A payment that belongs to no invoice (e.g. paid twice) becomes a credit, with a proposal to pay it back. */
    public function makeCredit(Payment $payment, string $reason): ?Refund
    {
        if ($payment->invoice_id !== null || ! in_array($payment->match, ['credit', 'unmatched', 'suggested'], true)) {
            throw new RuntimeException(__('finance.not_a_credit'));
        }

        return DB::transaction(function () use ($payment, $reason) {
            $payment->forceFill(['match' => 'credit', 'suggested_invoice_id' => null])->save();
            $this->bookings->paymentChanged($payment);

            return $this->proposeRefund($payment, $reason);
        });
    }

    /** The family keeps the credit. */
    public function decline(Refund $refund): void
    {
        if ($refund->status === 'proposed') {
            $refund->forceFill(['status' => 'declined', 'decided_by_id' => auth()->id()])->save();
        }
    }

    /** Uses a credit (or part of it) for an invoice of any family. */
    public function useFor(Payment $credit, Invoice $invoice): void
    {
        if ($credit->match !== 'credit' || $credit->invoice_id !== null) {
            throw new RuntimeException(__('finance.not_a_credit'));
        }
        DB::transaction(function () use ($credit, $invoice) {
            Refund::query()->where('payment_id', $credit->id)->where('status', 'proposed')->update(['status' => 'declined', 'updated_at' => now()]);
            app(Payments::class)->assign($credit, $invoice);
        });
    }
}
