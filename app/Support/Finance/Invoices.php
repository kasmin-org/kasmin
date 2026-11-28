<?php

namespace App\Support\Finance;

use App\Models\Event;
use App\Models\EventInstallment;
use App\Models\Household;
use App\Models\Invoice;
use App\Models\Participation;
use App\Models\Person;
use App\Support\Accounting\DocumentBookings;
use App\Support\Api\Presenter;
use App\Support\Mail\Composer;
use App\Support\Settings;
use App\Support\Webhooks\Webhooks;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Invoice runs per family and instalment (IN01, IN02), issuing with number and RF reference (IN03, IN05),
 * cancellation by credit note (IN10, RF02).
 */
class Invoices
{
    public function __construct(private readonly Billing $billing, private readonly DocumentBookings $bookings) {}

    /**
     * Creates draft invoices for all families with occupying participations that have no invoice for this
     * instalment yet. Returns the number of invoices created.
     */
    public function draftRun(Event $event, EventInstallment $installment): int
    {
        $created = 0;
        foreach ($this->pendingGroups($event, $installment) as $group) {
            $this->draftFor($event, $installment, $group, self::household($group));
            $created++;
        }

        return $created;
    }

    /**
     * Families (participations grouped by household) with occupying participations and no invoice for this
     * instalment yet.
     *
     * @return Collection<string, Collection<int, Participation>>
     */
    public function pendingGroups(Event $event, EventInstallment $installment): Collection
    {
        $event->loadMissing('roles', 'installments');
        $participations = Participation::query()->where('event_id', $event->id)
            ->whereIn('status', Billing::BILLED)->with(['event', 'person.guardians', 'person.households'])->get();

        /** @var Collection<string, Collection<int, Participation>> $groups */
        $groups = collect($participations->groupBy(fn (Participation $p) => (string) (Billing::household($p)->id ?? 'p'.$p->person_id))->all());

        return $groups->filter(function (Collection $group) use ($event, $installment) {
            $household = self::household($group);
            if (array_sum(array_column($this->billing->lines($event, $installment, $group, 'en'), 'amount_cents')) <= 0) {
                return false; // nobody of the family has to pay anything: no invoice over 0
            }

            return ! Invoice::query()->where('event_id', $event->id)->where('installment_key', $installment->key)->where('type', 'invoice')
                ->whereNotIn('status', ['cancelled', 'corrected'])
                ->where(fn ($q) => $household ? $q->where('household_id', $household->id) : $q->whereHas('lines', fn ($l) => $l->whereIn('person_id', $group->pluck('person_id'))))
                ->exists();
        });
    }

    /**
     * @param  Collection<int, Participation>  $participations
     */
    public function draftFor(Event $event, EventInstallment $installment, Collection $participations, ?Household $household): Invoice
    {
        $payer = $this->payer($household, $participations);

        return DB::transaction(function () use ($event, $installment, $participations, $household, $payer) {
            $invoice = Invoice::query()->create([
                'household_id' => $household?->id, 'payer_id' => $payer?->id, 'event_id' => $event->id,
                'installment_key' => $installment->key, 'currency' => $event->currency, 'created_by_id' => auth()->id(),
                'payer_name' => $payer?->fullName(), 'payer_address' => $household ? trim(implode("\n", array_filter([$household->getAttribute('street'), trim($household->getAttribute('postal_code').' '.$household->getAttribute('city'))]))) : null,
            ]);
            $total = 0;
            $sort = 0;
            $locale = $payer->locale ?? app()->getLocale();
            foreach ($this->billing->lines($event, $installment, $participations, $locale) as $line) {
                $invoice->lines()->create($line + ['sort' => $sort++]);
                $total += $line['amount_cents'];
            }
            $invoice->forceFill(['total_cents' => $total])->save();

            return $invoice;
        });
    }

    /**
     * A draft for one person, independent of events (AD24).
     *
     * @param  list<array{description: string, amount_cents: int}>  $lines
     */
    public function single(Person $payer, array $lines): Invoice
    {
        $household = $payer->households()->wherePivot('role', 'guardian')->first();

        return DB::transaction(function () use ($payer, $household, $lines) {
            $invoice = Invoice::query()->create([
                'household_id' => $household?->id, 'payer_id' => $payer->id, 'payer_name' => $payer->fullName(), 'created_by_id' => auth()->id(), 'total_cents' => array_sum(array_column($lines, 'amount_cents')),
                'payer_address' => $household ? trim(implode("\n", array_filter([$household->getAttribute('street'), trim($household->getAttribute('postal_code').' '.$household->getAttribute('city'))]))) : null,
            ]);
            foreach ($lines as $sort => $line) {
                $invoice->lines()->create($line + ['discount_cents' => 0, 'sort' => $sort]);
            }

            return $invoice;
        });
    }

    /** Assigns number, reference, dates; status issued. */
    public function issue(Invoice $invoice): Invoice
    {
        if ($invoice->status !== 'draft') {
            throw new RuntimeException('Only drafts can be issued.');
        }

        return DB::transaction(function () use ($invoice) {
            // One number range, or one per year (IN03); the row is locked, so numbers have no gaps.
            $range = Settings::bool('invoice_number_per_year') ? 'invoice_next_number_'.now()->format('Y') : 'invoice_next_number';
            DB::table('system_meta')->insertOrIgnore(['key' => $range, 'value' => $range === 'invoice_next_number' ? Settings::get($range) : '1', 'created_at' => now(), 'updated_at' => now()]);
            $next = max(1, (int) DB::table('system_meta')->where('key', $range)->lockForUpdate()->value('value'));
            Settings::set($range, (string) ($next + 1));
            $number = str_replace(['{number}', '{year}'], [(string) $next, now()->format('Y')], (string) Settings::get('invoice_number_format'));

            $installment = $invoice->event?->installments()->where('key', $invoice->installment_key)->first();
            $due = $installment->due_date ?? now()->addDays($installment->due_days ?? Settings::int('invoice_due_days'));
            $invoice->forceFill([
                'number' => $number, 'reference' => self::creditorReference($number), 'status' => $invoice->total_cents > 0 ? 'issued' : 'paid',
                'issued_on' => now()->toDateString(), 'due_on' => $due->toDateString() < now()->toDateString() ? now()->addDays(14)->toDateString() : $due->toDateString(),
            ])->save();
            app(Webhooks::class)->emit('invoice.issued', Presenter::invoice($invoice));
            $this->bookings->invoiceIssued($invoice);

            return $invoice;
        });
    }

    /** Queues the invoice mail with the PDF to the payer (IN06). */
    public function send(Invoice $invoice): bool
    {
        if ($invoice->payer === null || $invoice->status === 'draft') {
            return false;
        }

        return app(Composer::class)->queue('invoice', $invoice->payer, [
            'household' => $invoice->household, 'event' => $invoice->event, 'invoice' => $invoice,
        ], attachments: [['invoice_id' => $invoice->id, 'paid' => false]]) !== null;
    }

    /** Cancels an issued invoice with a credit note (IN10). */
    public function cancel(Invoice $invoice, string $reason, string $status = 'cancelled', bool $proposeRefund = true): Invoice
    {
        if ($invoice->type !== 'invoice' || ! in_array($invoice->status, ['issued', 'partially_paid', 'paid'], true)) {
            throw new RuntimeException('Only issued invoices can be cancelled.');
        }

        return DB::transaction(function () use ($invoice, $reason, $status, $proposeRefund) {
            $credit = $invoice->replicate(['number', 'reference', 'issued_on', 'due_on', 'paid_cents']);
            $credit->fill(['type' => 'credit_note', 'status' => 'draft', 'total_cents' => -$invoice->total_cents, 'corrects_id' => $invoice->id])->save();
            foreach ($invoice->lines as $line) {
                $credit->lines()->create(['person_id' => $line->person_id, 'participation_id' => $line->participation_id, 'description' => $line->description.' ('.$reason.')', 'amount_cents' => -$line->amount_cents, 'sort' => $line->getAttribute('sort')]);
            }
            $this->issue($credit);
            $invoice->forceFill(['status' => $status])->save();
            $this->release($invoice, $proposeRefund ? $reason : null);

            return $credit;
        });
    }

    /** Payments of an invoice that is no longer valid become a credit of the family, with a refund proposal (RF02). */
    private function release(Invoice $invoice, ?string $refundReason): void
    {
        foreach ($invoice->payments()->get() as $payment) {
            $payment->forceFill(['invoice_id' => null, 'household_id' => $invoice->household_id, 'match' => 'credit'])->save();
            $this->bookings->paymentChanged($payment);
            if ($refundReason !== null) {
                app(Credits::class)->proposeRefund($payment, $refundReason, $invoice->payer_id);
            }
        }
    }

    /**
     * Structured creditor reference (ISO 11649), e.g. "RF18 R123" → "RF18R123", used in the EPC QR code.
     */
    public static function creditorReference(string $number): string
    {
        $ref = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $number));
        $numeric = '';
        foreach (str_split($ref.'RF00') as $char) {
            $numeric .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }
        $check = 98 - (int) bcmod($numeric, '97');

        return 'RF'.str_pad((string) $check, 2, '0', STR_PAD_LEFT).$ref;
    }

    /** @param Collection<int, Participation> $group */
    public static function household(Collection $group): ?Household
    {
        $first = $group->first();

        return $first !== null ? Billing::household($first) : null;
    }

    /** @param Collection<int, Participation> $participations */
    private function payer(?Household $household, Collection $participations): ?Person
    {
        if ($household !== null) {
            $recipient = $household->invoiceRecipient;
            if ($recipient !== null) {
                return $recipient;
            }
            $guardian = $household->guardians()->first();
            if ($guardian !== null) {
                return $guardian;
            }
        }
        $first = $participations->first();
        $person = $first?->person;
        $itself = $first !== null && ($first->isOwn() || ($person?->ageAt($first->event?->starts_at) ?? 0) >= Settings::int('guardian_until_age'));

        return $itself ? $person : ($person?->guardians->first() ?? $person);
    }
}
