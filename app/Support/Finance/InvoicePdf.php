<?php

namespace App\Support\Finance;

use App\Models\Invoice;
use App\Support\Mail\Placeholders;
use App\Support\Settings;
use Barryvdh\DomPDF\Facade\Pdf;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * Invoice PDF (IN04) with an EPC QR code ("GiroCode", SEPA credit transfer) for open invoices in euro, and a
 * "paid" stamp for the payment confirmation (PY05).
 */
class InvoicePdf
{
    public function render(Invoice $invoice, bool $paid = false): string
    {
        $invoice->loadMissing('lines', 'event', 'payer');
        $locale = $invoice->payer->locale ?? app()->getLocale();
        $previous = app()->getLocale();
        app()->setLocale($locale);
        try {
            return Pdf::loadView('pdf.invoice', [
                'invoice' => $invoice,
                'paid' => $paid || $invoice->status === 'paid',
                'qr' => $paid ? null : $this->qr($invoice),
                'org' => self::organisation(),
                'locale' => $locale,
            ])->setPaper('a4')->output();
        } finally {
            app()->setLocale($previous);
        }
    }

    public function fileName(Invoice $invoice, bool $paid = false): string
    {
        $prefix = $invoice->type === 'credit_note' ? __('finance.credit_note') : __('finance.invoice');

        return preg_replace('/[^A-Za-z0-9_.-]+/', '-', $prefix.'-'.($invoice->number ?? 'draft').($paid ? '-'.__('finance.paid') : '')).'.pdf';
    }

    /**
     * EPC069-12 payload (version 002, SCT). Null when no IBAN is configured, the currency is not EUR or nothing is open.
     */
    public static function epcPayload(Invoice $invoice): ?string
    {
        $org = self::organisation();
        $open = $invoice->openCents();
        if ($org['iban'] === '' || $invoice->currency !== 'EUR' || $open <= 0 || $open > 99999999999) {
            return null;
        }

        return implode("\n", [
            'BCD', '002', '1', 'SCT',
            $org['bic'],
            mb_substr($org['name'], 0, 70),
            $org['iban'],
            'EUR'.number_format($open / 100, 2, '.', ''),
            '',
            (string) $invoice->reference,
            '',
        ]);
    }

    /** @return array{name: string, address: string, email: string, website: string, iban: string, bic: string, bank: string, footer: string} */
    public static function organisation(): array
    {
        return [
            'name' => Placeholders::organisationName(),
            'address' => (string) Settings::get('organisation_address'),
            'email' => (string) Settings::get('organisation_email'),
            'website' => (string) Settings::get('organisation_website'),
            'iban' => strtoupper(str_replace(' ', '', (string) Settings::get('organisation_iban'))),
            'bic' => strtoupper(str_replace(' ', '', (string) Settings::get('organisation_bic'))),
            'bank' => (string) Settings::get('organisation_bank'),
            'footer' => (string) Settings::get('organisation_legal_footer'),
        ];
    }

    /** Data URI of the payment code, e.g. for the portal (GP12). */
    public function qrCode(Invoice $invoice): ?string
    {
        return $this->qr($invoice);
    }

    private function qr(Invoice $invoice): ?string
    {
        $payload = self::epcPayload($invoice);
        if ($payload === null) {
            return null;
        }

        return (new QRCode(new QROptions([
            'outputType' => QROutputInterface::GDIMAGE_PNG,
            'eccLevel' => EccLevel::M,
            'scale' => 6,
            'outputBase64' => true,
        ])))->render($payload);
    }
}
