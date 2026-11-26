<?php

namespace App\Support\Finance;

use App\Models\Event;
use App\Models\EventInstallment;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Participation;
use App\Models\Payment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Compares issued invoices with what a family has to pay now – after cancellations, changed siblings, new children or
 * prices (SD07, RF03). Drafts are recalculated at once; issued invoices only after a decision: the old invoice is cancelled
 * with a credit note, a new one replaces it, payments move over, and the rest becomes a credit with refund proposal.
 */
class Corrections
{
    /** @var array<int, \Illuminate\Database\Eloquent\Collection<int, Participation>> registrations of an event while its invoices are compared */
    private array $participations = [];

    /** @var array<int, int> registration => family (0 = none), while invoices are compared */
    private array $households = [];

    public function __construct(private readonly Billing $billing, private readonly Invoices $invoices) {}

    private function remember(Event $event): void
    {
        $this->households = [];
        $this->participations = [$event->id => Participation::query()->where('event_id', $event->id)->with('event', 'person.households')->get()];
    }

    /**
     * @return list<array{key: string, invoice: Invoice, old: int, new: int, lines: list<array<string, mixed>>}>
     */
    public function proposals(Event $event): array
    {
        $event->load('roles', 'installments', 'prices');
        $this->remember($event);
        $result = [];
        $invoices = Invoice::query()->where('event_id', $event->id)->where('type', 'invoice')->whereIn('status', ['issued', 'partially_paid', 'paid'])
            ->with('lines', 'payer', 'household')->get();
        foreach ($invoices as $invoice) {
            $installment = $event->installments->firstWhere('key', $invoice->installment_key);
            if ($installment === null) {
                continue;
            }
            $lines = $this->expected($event, $installment, $invoice);
            if ($this->signature($lines) !== $this->signature($invoice->lines->map(fn (InvoiceLine $l) => $l->getAttributes())->all())) {
                $result[] = ['key' => 'invoice:'.$invoice->id, 'invoice' => $invoice, 'old' => $invoice->total_cents, 'new' => array_sum(array_column($lines, 'amount_cents')), 'lines' => $lines];
            }
        }

        $this->participations = [];

        return $result;
    }

    /** Recalculates the drafts of an event; nothing was sent yet. */
    public function refreshDrafts(Event $event): int
    {
        $event->load('roles', 'installments', 'prices');
        $changed = 0;
        foreach (Invoice::query()->where('event_id', $event->id)->where('type', 'invoice')->where('status', 'draft')->with('lines', 'payer')->get() as $invoice) {
            $installment = $event->installments->firstWhere('key', $invoice->installment_key);
            if ($installment === null) {
                continue;
            }
            $lines = $this->expected($event, $installment, $invoice);
            if ($this->signature($lines) === $this->signature($invoice->lines->map(fn (InvoiceLine $l) => $l->getAttributes())->all())) {
                continue;
            }
            DB::transaction(function () use ($invoice, $lines) {
                $invoice->lines()->get()->each(fn (InvoiceLine $l) => $l->delete());
                if ($lines === []) {
                    $invoice->delete();

                    return;
                }
                foreach ($lines as $sort => $line) {
                    $invoice->lines()->create($line + ['sort' => $sort]);
                }
                $invoice->forceFill(['total_cents' => array_sum(array_column($lines, 'amount_cents'))])->save();
            });
            $changed++;
        }

        return $changed;
    }

    /** Carries out a correction; returns the new invoice, or null if nothing is left to pay. */
    public function correct(Event $event, Invoice $invoice, string $reason): ?Invoice
    {
        $proposal = collect($this->proposals($event))->firstWhere('key', 'invoice:'.$invoice->id);
        if ($proposal === null) {
            return null;
        }

        return DB::transaction(function () use ($proposal, $invoice, $reason) {
            /** @var Collection<int, Payment> $payments */
            $payments = $invoice->payments()->orderBy('paid_on')->get();
            $new = null;
            if ($proposal['lines'] !== []) {
                $new = $invoice->replicate(['number', 'reference', 'issued_on', 'due_on', 'paid_cents', 'status', 'total_cents']);
                $new->fill(['status' => 'draft', 'total_cents' => 0, 'paid_cents' => 0])->save();
                foreach ($proposal['lines'] as $sort => $line) {
                    $new->lines()->create($line + ['sort' => $sort]);
                }
                $new->forceFill(['total_cents' => $proposal['new']])->save();
            }
            $this->invoices->cancel($invoice, $reason, $new !== null ? 'corrected' : 'cancelled', $new === null);
            if ($new !== null) {
                $this->invoices->issue($new);
                // A date in the past or very close is not fair for a changed invoice; keep a later original date.
                $original = $invoice->due_on;
                if ($original !== null && $new->due_on !== null && $original->gt($new->due_on)) {
                    $new->forceFill(['due_on' => $original->toDateString()])->save();
                }
                foreach ($payments as $payment) {
                    $payment->refresh();
                    if ($new->refresh()->openCents() > 0) {
                        app(Payments::class)->assign($payment, $new, 'manual', quiet: true, refundReason: $reason);
                    } else {
                        app(Credits::class)->proposeRefund($payment, $reason, $new->payer_id);
                    }
                }
            }

            return $new?->refresh();
        });
    }

    /** @return list<array<string, mixed>> */
    private function expected(Event $event, EventInstallment $installment, Invoice $invoice): array
    {
        $locale = $invoice->payer->locale ?? app()->getLocale();

        return $this->billing->lines($event, $installment, $this->family($event, $invoice), $locale);
    }

    /** @return Collection<int, Participation> */
    private function family(Event $event, Invoice $invoice): Collection
    {
        $billed = $invoice->lines->pluck('participation_id')->filter()->all();
        $persons = $invoice->lines->pluck('person_id')->filter()->all();
        $household = $invoice->household_id;

        // Loaded once per check of an event, not once per invoice.
        $all = $this->participations[$event->id] ?? Participation::query()->where('event_id', $event->id)->with('event', 'person.households')->get();

        // The family of a registration is worked out once per check, not once per invoice.
        $of = function (Participation $p): ?int {
            if (! isset($this->participations[$p->event_id])) {
                return Billing::household($p)?->id;
            }

            return $this->households[$p->id] ??= Billing::household($p)->id ?? 0;
        };

        return $all
            ->filter(fn (Participation $p) => in_array($p->id, $billed, true)
                || ($household !== null ? $of($p) === $household : in_array($p->person_id, $persons, true) && ! $of($p)))->values()->toBase();
    }

    /** @param  array<int, array<string, mixed>>  $lines */
    private function signature(array $lines): string
    {
        $rows = array_map(fn (array $l) => ((int) $l['participation_id']).':'.((int) $l['amount_cents']), $lines);
        sort($rows);

        return implode('|', $rows);
    }
}
