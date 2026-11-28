<?php

namespace App\Support\Finance;

use App\Models\BankImport;
use App\Models\Group;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\Accounting\DocumentBookings;
use App\Support\Api\Presenter;
use App\Support\Audit\Audit;
use App\Support\Mail\Composer;
use App\Support\Mail\Notifier;
use App\Support\People\GroupMembers;
use App\Support\Settings;
use App\Support\Webhooks\Webhooks;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use RuntimeException;

/**
 * Payments (PY01–PY07): manual booking, bank import from CSV/XLSX with saved column mapping, matching by reference.
 * A fully paid invoice triggers the payment confirmation (with the paid invoice attached, configurable).
 */
class Payments
{
    public function __construct(private readonly DocumentBookings $bookings) {}

    public function record(Invoice $invoice, int $amountCents, Carbon $paidOn, string $method = 'bank', ?string $note = null, string $match = 'manual'): Payment
    {
        $payment = Payment::query()->create([
            'amount_cents' => $amountCents, 'paid_on' => $paidOn->toDateString(), 'method' => $method, 'note' => $note, 'match' => 'unmatched', 'created_by_id' => auth()->id(),
        ]);
        $this->assign($payment, $invoice, $match);

        return $payment->refresh();
    }

    /**
     * Assigns a payment to an invoice. More than the open amount is split off as a credit of the family with a refund
     * proposal (PY05).
     */
    public function assign(Payment $payment, Invoice $invoice, string $match = 'manual', bool $quiet = false, ?string $refundReason = null): void
    {
        DB::transaction(function () use ($payment, $invoice, $match, $quiet, $refundReason) {
            $invoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($invoice->type !== 'invoice' || ! in_array($invoice->status, ['issued', 'partially_paid'], true)) {
                throw new RuntimeException(__('finance.not_open'));
            }
            $over = $payment->amount_cents - $invoice->openCents();
            if ($over > 0) {
                $credit = $this->part($payment, $over);
                $credit->forceFill(['match' => 'credit', 'household_id' => $invoice->household_id])->save();
                app(Credits::class)->proposeRefund($credit, $refundReason ?? __('finance.overpaid', ['number' => $invoice->number]), $invoice->payer_id);
            }
            $payment->forceFill(['invoice_id' => $invoice->id, 'household_id' => $invoice->household_id, 'match' => $match, 'suggested_invoice_id' => null])->save();
            $this->apply($invoice, $quiet);
            $this->bookings->paymentChanged($payment); // with the part that became a credit
        });
    }

    /**
     * One transfer for several invoices (PY02): invoice id => cents. What is left stays unassigned.
     *
     * @param  array<int, int>  $amounts
     */
    public function split(Payment $payment, array $amounts, string $match = 'manual'): void
    {
        $amounts = array_filter($amounts, fn (int $cents) => $cents > 0);
        if ($payment->invoice_id !== null || $amounts === [] || array_sum($amounts) > $payment->amount_cents) {
            throw new RuntimeException(__('finance.split_invalid'));
        }
        DB::transaction(function () use ($payment, $amounts, $match) {
            foreach ($amounts as $invoiceId => $cents) {
                $invoice = Invoice::query()->findOrFail($invoiceId);
                $part = $cents === $payment->amount_cents ? $payment : $this->part($payment, $cents);
                $this->assign($part, $invoice, $match);
            }
        });
    }

    /** Takes a part out of a payment as an own payment; the sum stays the same. */
    private function part(Payment $payment, int $cents): Payment
    {
        $part = $payment->replicate(['hash']);
        $part->fill(['amount_cents' => $cents, 'parent_id' => $payment->parent_id ?? $payment->id, 'invoice_id' => null, 'suggested_invoice_id' => null, 'match' => 'unmatched'])->save();
        $payment->forceFill(['amount_cents' => $payment->amount_cents - $cents])->save();

        return $part;
    }

    /** Recalculates the paid amount and status; triggers confirmation and follow-ups when fully paid. */
    public function apply(Invoice $invoice, bool $quiet = false): void
    {
        $wasPaid = $invoice->status === 'paid';
        $paid = (int) $invoice->payments()->sum('amount_cents');
        $status = $paid >= $invoice->total_cents ? 'paid' : ($paid > 0 ? 'partially_paid' : 'issued');
        $invoice->forceFill(['paid_cents' => $paid, 'status' => $status])->save();
        app(Webhooks::class)->emit('payment.received', Presenter::invoice($invoice));
        if ($status === 'paid' && ! $wasPaid) {
            app(Webhooks::class)->emit('invoice.paid', Presenter::invoice($invoice));
            $this->followUps($invoice);
            if (! $quiet) {
                $this->confirm($invoice);
            }
        }
    }

    /** After the payment, as configured in the payment plan: place confirmed, groups changed (PY07, A03). */
    private function followUps(Invoice $invoice): void
    {
        $installment = $invoice->installmentOfPlan();
        if ($installment === null) {
            return;
        }
        foreach ($invoice->lines()->with('participation.person')->get() as $line) {
            $participation = $line->participation;
            $person = $participation?->person;
            if ($participation === null || $person === null || ! in_array($participation->status, Billing::BILLED, true)) {
                continue;
            }
            if (($installment->confirms_place || $participation->offer_mode === 'payment') && in_array($participation->status, ['registered', 'offered'], true)) {
                $participation->fill(['status' => 'confirmed', 'offer_expires_at' => null])->save();
                Audit::record('participation.confirmed_by_payment', $participation, null, ['invoice' => $invoice->id], 'system');
            }
            if ($installment->paid_remove_group_id !== null) {
                $group = Group::query()->find($installment->paid_remove_group_id);
                if ($group !== null) {
                    GroupMembers::leave($group, $person->id);
                }
            }
            if ($installment->paid_add_group_id !== null && ! $person->groups()->whereKey($installment->paid_add_group_id)->exists()) {
                $person->groups()->attach($installment->paid_add_group_id, ['starts_on' => now()->toDateString(), 'source' => 'payment']);
            }
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
            $several = $this->several($payment);
            if ($several !== null) {
                $this->split($payment, $several, 'auto');
                $result['matched']++;

                continue;
            }
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
     * Several references in one transfer, and the amount is exactly their sum: invoice id => cents, otherwise null.
     *
     * @return array<int, int>|null
     */
    private function several(Payment $payment): ?array
    {
        $open = Invoice::query()->where('type', 'invoice')->whereIn('status', ['issued', 'partially_paid'])->get()->filter(fn (Invoice $i) => $this->mentions($payment, $i));
        $amounts = $open->mapWithKeys(fn (Invoice $i) => [$i->id => $i->openCents()])->all();

        return count($amounts) > 1 && array_sum($amounts) === $payment->amount_cents ? $amounts : null;
    }

    private function mentions(Payment $payment, Invoice $invoice): bool
    {
        $text = Str::upper((string) preg_replace('/\s+/', ' ', (string) $payment->reference));
        $compact = str_replace(' ', '', $text);
        foreach (array_filter([$invoice->reference, $invoice->number]) as $value) {
            $value = preg_quote(Str::upper(str_replace(' ', '', $value)), '/');
            foreach ([$text, $compact] as $haystack) {
                if (preg_match('/(^|[^A-Z0-9])'.$value.'($|[^A-Z0-9])/', $haystack) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Finds the invoice of a payment: certain when the reference contains the RF reference or invoice number and
     * the amount fits the open amount; a suggestion when only one of both fits (PY02, PY03).
     *
     * @return array{0: ?Invoice, 1: bool}
     */
    public function match(Payment $payment): array
    {
        $open = Invoice::query()->where('type', 'invoice')->whereIn('status', ['issued', 'partially_paid'])->get();
        foreach ($open as $invoice) {
            $byRef = $this->mentions($payment, $invoice);
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
            app(Composer::class)->queue('payment_confirmation', $payer, ['household' => $invoice->household, 'event' => $invoice->event, 'invoice' => $invoice],
                attachments: Settings::bool('invoice_attach_paid') ? [['invoice_id' => $invoice->id, 'paid' => true]] : null);
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
