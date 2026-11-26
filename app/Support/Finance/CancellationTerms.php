<?php

namespace App\Support\Finance;

use App\Models\Invoice;
use App\Models\Participation;

/**
 * What a cancellation costs today, shown before confirming (PA03, RF01).
 */
class CancellationTerms
{
    /** @return list<string> */
    public static function for(Participation $participation): array
    {
        $event = $participation->event?->loadMissing('installments', 'roles', 'prices');
        if ($event === null) {
            return [];
        }
        $terms = [];
        $text = $event->cancellation_text;
        if (is_string($text) && trim($text) !== '') {
            $terms[] = $text;
        }
        if (! in_array($participation->status, Billing::BILLED, true)) {
            return $terms;
        }
        $billing = app(Billing::class);
        foreach ($event->installments as $installment) {
            $terms[] = match (true) {
                ! $installment->refundable => __('portal.terms_not_refundable', ['name' => $installment->labelText()]),
                ! $billing->refunded($event, $installment, $participation) => __('portal.terms_too_late', ['name' => $installment->labelText()]),
                $installment->refund_until_days !== null => __('portal.terms_refundable_until', ['name' => $installment->labelText(), 'date' => $event->starts_at->copy()->subDays($installment->refund_until_days)->isoFormat('LL')]),
                default => __('portal.terms_refundable', ['name' => $installment->labelText()]),
            };
        }
        if ($event->cancellation_fee_cents > 0) {
            $terms[] = __('portal.terms_fee', ['amount' => Invoice::money($event->cancellation_fee_cents, $event->currency)]);
        }

        return $terms;
    }
}
