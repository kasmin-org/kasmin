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
        if ($invoice->currency !== 'EUR') {
            return null;
        }

        return self::epc($org['bic'], $org['name'], $org['iban'], $invoice->openCents(), (string) $invoice->reference);
    }

    /**
     * EPC069-12 payload for any transfer in euro to a payee: a structured reference (RF…) or a free text. Null without
     * an IBAN or with an amount the code cannot carry.
     */
    public static function epc(string $bic, string $name, string $iban, int $cents, string $reference = '', string $text = ''): ?string
    {
        if ($iban === '' || $cents <= 0 || $cents > 99999999999) {
            return null;
        }

        return implode("\n", [
            'BCD', '002', '1', 'SCT',
            $bic,
            mb_substr($name, 0, 70),
            Iban::normalise($iban),
            'EUR'.number_format($cents / 100, 2, '.', ''),
            '',
            $reference,
            $reference === '' ? mb_substr($text, 0, 140) : '',
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

        return $payload === null ? null : self::qrImage($payload);
    }

    /** The payment code as data URI (PNG). */
    public static function qrImage(string $payload): string
    {
        return (string) (new QRCode(new QROptions([
            'outputType' => QROutputInterface::GDIMAGE_PNG,
            'eccLevel' => EccLevel::M,
            'scale' => 6,
            'outputBase64' => true,
        ])))->render($payload);
    }
}
