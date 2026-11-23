<?php

namespace App\Support\Finance;

use App\Models\BankImport;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\Mail\Composer;
use App\Support\Mail\Notifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Payments (PY01–PY07): manual booking, bank import from CSV/XLSX with saved column mapping, matching by reference.
 * A fully paid invoice triggers the payment confirmation (with the paid invoice attached, configurable).
 */
class Payments
{
    public function record(Invoice $invoice, int $amountCents, Carbon $paidOn, string $method = 'bank', ?string $note = null, string $match = 'manual'): Payment
    {
        $payment = Payment::query()->create([
            'invoice_id' => $invoice->id, 'amount_cents' => $amountCents, 'paid_on' => $paidOn->toDateString(),
            'method' => $method, 'note' => $note, 'match' => $match, 'created_by_id' => auth()->id(),
        ]);
        $this->apply($invoice);

        return $payment;
    }

    public function assign(Payment $payment, Invoice $invoice, string $match = 'manual'): void
    {
        $payment->forceFill(['invoice_id' => $invoice->id, 'match' => $match, 'suggested_invoice_id' => null])->save();
        $this->apply($invoice);
    }

    /** Recalculates the paid amount and status; triggers confirmation and notification when fully paid. */
    public function apply(Invoice $invoice): void
    {
        $wasPaid = $invoice->status === 'paid';
        $paid = (int) $invoice->payments()->sum('amount_cents');
        $status = $paid >= $invoice->total_cents ? 'paid' : ($paid > 0 ? 'partially_paid' : 'issued');
        $invoice->forceFill(['paid_cents' => $paid, 'status' => $status])->save();
        if ($status === 'paid' && ! $wasPaid) {
            $this->confirm($invoice);
        }
    }

    /**
     * Imports a CSV or XLSX bank export. $mapping: column numbers (0-based) for date, amount, reference, name, iban;
     * optional "skip_rows" (header lines), "delimiter", "date_format". Returns [imported, matched, suggested, duplicates].
     *
     * @param  array<string, mixed>  $mapping
     * @return array{imported: int, matched: int, suggested: int, duplicates: int}
     */
    public function import(string $path, string $fileName, array $mapping, ?string $account = null): array
    {
        $import = BankImport::query()->create(['account' => $account, 'file_name' => $fileName, 'mapping' => $mapping, 'created_by_id' => auth()->id()]);
        $result = ['imported' => 0, 'matched' => 0, 'suggested' => 0, 'duplicates' => 0];
        $skip = (int) ($mapping['skip_rows'] ?? 1);
        foreach ($this->rows($path, $fileName, (string) ($mapping['delimiter'] ?? ';')) as $i => $row) {
            if ($i < $skip) {
                continue;
            }
            $amount = $this->amount((string) ($row[(int) $mapping['amount']] ?? ''));
            if ($amount === null || $amount <= 0) {
                continue; // only incoming payments
            }
            $date = $this->date($row[(int) $mapping['date']] ?? null, (string) ($mapping['date_format'] ?? 'd.m.Y'));
            $reference = trim((string) ($row[(int) ($mapping['reference'] ?? -1)] ?? ''));
            $name = trim((string) ($row[(int) ($mapping['name'] ?? -1)] ?? ''));
            $iban = Str::upper(str_replace(' ', '', (string) ($row[(int) ($mapping['iban'] ?? -1)] ?? '')));
            $hash = hash('sha256', implode('|', [$date?->toDateString(), $amount, $reference, $name, $iban]));
            if (Payment::query()->where('hash', $hash)->exists()) {
                $result['duplicates']++;

                continue;
            }
            $payment = Payment::query()->create([
                'amount_cents' => $amount, 'paid_on' => ($date ?? now())->toDateString(), 'method' => 'bank', 'reference' => Str::limit($reference, 250),
                'payer_name' => Str::limit($name, 250), 'iban' => $iban ?: null, 'bank_import_id' => $import->id, 'hash' => $hash, 'match' => 'unmatched',
            ]);
            $result['imported']++;
            [$invoice, $certain] = $this->match($payment);
            if ($invoice !== null && $certain) {
                $this->assign($payment, $invoice, 'auto');
                $result['matched']++;
            } elseif ($invoice !== null) {
                $payment->forceFill(['match' => 'suggested', 'suggested_invoice_id' => $invoice->id])->save();
                $result['suggested']++;
            }
        }
        $import->forceFill(['rows' => $result['imported']])->save();

        return $result;
    }

    /**
     * Finds the invoice of a payment: certain when the reference contains the RF reference or invoice number and
     * the amount fits the open amount; a suggestion when only one of both fits (PY02, PY03).
     *
     * @return array{0: ?Invoice, 1: bool}
     */
    public function match(Payment $payment): array
    {
        $text = Str::upper((string) preg_replace('/\s+/', '', (string) $payment->reference));
        $open = Invoice::query()->where('type', 'invoice')->whereIn('status', ['issued', 'partially_paid'])->get();
        foreach ($open as $invoice) {
            $byRef = ($invoice->reference && str_contains($text, Str::upper($invoice->reference)))
                || ($invoice->number && preg_match('/(^|[^A-Z0-9])'.preg_quote(Str::upper((string) preg_replace('/\s+/', '', $invoice->number)), '/').'([^0-9]|$)/', $text) === 1);
            if ($byRef) {
                return [$invoice, $payment->amount_cents <= $invoice->openCents()];
            }
        }
        $byAmount = $open->filter(fn (Invoice $i) => $i->openCents() === $payment->amount_cents
            && $payment->payer_name && $i->payer_name && Str::contains(Str::lower($payment->payer_name), Str::lower((string) Str::afterLast($i->payer_name, ' '))));

        return [$byAmount->count() === 1 ? $byAmount->first() : null, false];
    }

    private function confirm(Invoice $invoice): void
    {
        $payer = $invoice->payer;
        if ($payer !== null) {
            $mail = app(Composer::class)->queue('payment_confirmation', $payer, ['household' => $invoice->household, 'event' => $invoice->event]);
            if ($mail !== null && (bool) config('kasmin.attach_paid_invoice', true)) {
                $mail->forceFill(['attachments' => [['invoice_id' => $invoice->id, 'paid' => true]]])->save();
            }
        }
        app(Notifier::class)->event('payments', trans('finance.notify_paid', ['number' => $invoice->number, 'amount' => Invoice::money($invoice->total_cents, $invoice->currency)]), url('/admin/invoices/'.$invoice->id));
    }

    /** @return iterable<int, list<mixed>> */
    private function rows(string $path, string $fileName, string $delimiter): iterable
    {
        if (Str::endsWith(Str::lower($fileName), '.xlsx')) {
            $reader = new XlsxReader;
        } else {
            $options = new CsvOptions;
            $options->FIELD_DELIMITER = $delimiter;
            $reader = new CsvReader($options);
        }
        $reader->open($path);
        $i = 0;
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                yield $i++ => array_map(fn ($c) => $c instanceof \DateTimeInterface ? $c->format('d.m.Y') : $c, $row->toArray());
            }
            break;
        }
        $reader->close();
    }

    private function amount(string $value): ?int
    {
        $v = trim(str_replace(['€', 'EUR', ' '], '', $value));
        if ($v === '') {
            return null;
        }
        if (preg_match('/^-?[\d.]+,\d{1,2}$/', $v)) {
            $v = str_replace(['.', ','], ['', '.'], $v); // 1.234,56
        } else {
            $v = str_replace(',', '', $v); // 1,234.56
        }

        return is_numeric($v) ? (int) round(((float) $v) * 100) : null;
    }

    private function date(mixed $value, string $format): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return Carbon::createFromFormat($format, trim((string) $value)) ?: null;
        } catch (\Throwable) {
            try {
                return Carbon::parse((string) $value);
            } catch (\Throwable) {
                return null;
            }
        }
    }
}
