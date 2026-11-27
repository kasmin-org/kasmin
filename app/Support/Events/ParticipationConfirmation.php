<?php

namespace App\Support\Events;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Participation;
use App\Support\Finance\InvoicePdf;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Confirmation of participation as a PDF (PA07): who took part in which event and when, and what was paid for it –
 * for employers, insurers and tax offices that refund a part of the costs.
 */
class ParticipationConfirmation
{
    /** Only after the event, and only for people who were there. */
    public static function available(Participation $participation): bool
    {
        $event = $participation->event;

        return $event !== null && $event->ends_at->isPast()
            && in_array($participation->status, ['attended', 'registered', 'confirmed'], true);
    }

    public function render(Participation $participation, ?string $locale = null): string
    {
        $participation->loadMissing('event', 'person');
        $locale ??= app()->getLocale();
        $previous = app()->getLocale();
        app()->setLocale($locale);
        try {
            return Pdf::loadView('pdf.participation', [
                'participation' => $participation,
                'paid' => self::paidCents($participation),
                'currency' => self::currency($participation),
                'org' => InvoicePdf::organisation(),
                'locale' => $locale,
            ])->setPaper('a4')->output();
        } finally {
            app()->setLocale($previous);
        }
    }

    public function fileName(Participation $participation): string
    {
        return preg_replace('/[^A-Za-z0-9_.-]+/', '-', __('events.confirmation.file').'-'.$participation->person?->fullName().'-'.$participation->event?->title).'.pdf';
    }

    /** What was paid for this place: the lines of this registration on paid invoices. */
    public static function paidCents(Participation $participation): int
    {
        return (int) InvoiceLine::query()->where('participation_id', $participation->id)
            ->whereHas('invoice', fn ($q) => $q->where('status', 'paid')->where('type', 'invoice'))
            ->sum('amount_cents'); // the amount of a line is what is charged after its discount
    }

    private static function currency(Participation $participation): string
    {
        return (string) (Invoice::query()->where('event_id', $participation->event_id)->value('currency') ?? 'EUR');
    }
}
