<?php

namespace App\Support\Accounting;

use App\Models\Account;
use App\Models\BankImport;
use App\Models\EventInstallment;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\MembershipType;
use App\Models\Payment;
use App\Models\Period;
use App\Models\Refund;
use App\Support\Settings;
use App\Support\System\SystemNotifications;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Bookings from the documents of the finance part (E1b, E1d.4, E1d.5):
 * - an issued invoice: the payer owes the total (receivables), each line is income with its gross amount, a discount
 *   is booked on "Discounts given" – on the cost centre of the line;
 * - a credit note: the mirror of the invoice it cancels;
 * - a payment: the money goes where it now belongs – the receivables of the payer of its invoice, or the credits of
 *   the family; when it moves later (credit used, invoice cancelled), the move is booked as its own entry;
 * - a refund: the credit is paid back.
 * A document is booked once and from the first period on; bookings are never changed. A failure of the accounting never
 * stops the finance part: it is logged and shown to the administrators, and the backfill books what is missing.
 *
 * @phpstan-type Line array{account: Account|int, debit?: int, credit?: int, cost_center?: int|null, person_id?: int|null, text?: string|null}
 */
class DocumentBookings
{
    private const NOTIFICATION = 'accounting_booking';

    /** @var list<string> documents that could not be booked by the last backfill, with the reason */
    public array $failures = [];

    public function __construct(private readonly Journal $journal) {}

    /** An invoice or credit note was issued; it is booked once the change is saved. */
    public function invoiceIssued(Invoice $invoice): void
    {
        DB::afterCommit(fn () => $this->safely($this->describe($invoice), fn () => $this->bookInvoice($invoice)));
    }

    /** A payment was assigned, became a credit or moved; booked once the change is saved. */
    public function paymentChanged(Payment $payment): void
    {
        DB::afterCommit(fn () => $this->safely($this->describe($payment), fn () => $this->bookPayment($payment)));
    }

    /** A refund was paid out; booked once the change is saved. */
    public function refundPaid(Refund $refund): void
    {
        DB::afterCommit(fn () => $this->safely($this->describe($refund), fn () => $this->bookRefund($refund)));
    }

    /**
     * Books every document from the first period (or from $from) on that has no booking yet – after the start of the
     * accounting, or after a booking failed. Safe to run again.
     *
     * @return int number of bookings made
     */
    public function backfill(?Carbon $from = null): int
    {
        $this->failures = [];
        $first = Period::query()->min('starts_on');
        if ($first === null) {
            return 0;
        }
        $start = Carbon::parse((string) $first)->startOfDay();
        if ($from !== null && $from->copy()->startOfDay()->gt($start)) {
            $start = $from->copy()->startOfDay();
        }
        $made = 0;
        $count = function (?JournalEntry $entry) use (&$made): void {
            $made += $entry !== null && $entry->wasRecentlyCreated ? 1 : 0;
        };
        // Invoices before payments, and an invoice before the credit note that cancels it.
        Invoice::query()->where('status', '!=', 'draft')->whereDate('issued_on', '>=', $start->toDateString())->orderBy('issued_on')->orderBy('id')
            ->lazy(200)->each(fn (Invoice $i) => $count($this->safely($this->describe($i), fn () => $this->bookInvoice($i))));
        Payment::query()->whereNull('parent_id')->whereDate('paid_on', '>=', $start->toDateString())->orderBy('paid_on')->orderBy('id')
            ->lazy(200)->each(fn (Payment $p) => $count($this->safely($this->describe($p), fn () => $this->bookPayment($p))));
        Refund::query()->where('status', 'done')->whereDate('done_on', '>=', $start->toDateString())->orderBy('done_on')->orderBy('id')
            ->lazy(200)->each(fn (Refund $r) => $count($this->safely($this->describe($r), fn () => $this->bookRefund($r))));
        if (! $this->hasFailures()) {
            app(SystemNotifications::class)->resolve(self::NOTIFICATION);
        }

        return $made;
    }

    /** Books an issued invoice or credit note; null if there is nothing to book (draft, before the first period, 0). */
    public function bookInvoice(Invoice $invoice): ?JournalEntry
    {
        $invoice = Invoice::query()->with('lines', 'event.series', 'event.installments')->find($invoice->id);
        $kind = $invoice?->type === 'credit_note' ? 'credit_note' : 'invoice';
        if ($invoice === null || $invoice->status === 'draft' || $invoice->issued_on === null || ! $this->started($invoice->issued_on)) {
            return null;
        }
        if (($booked = $this->booked($invoice, $kind)) !== null) {
            return $booked;
        }
        $lines = $kind === 'credit_note' ? $this->creditNoteLines($invoice) : $this->invoiceLines($invoice);
        if (! $this->hasAmounts($lines)) {
            return null; // only lines of 0, e.g. a free place
        }
        $text = $kind === 'credit_note'
            ? $this->text('credit_note', ['number' => $invoice->number, 'invoice' => Invoice::query()->whereKey((int) $invoice->corrects_id)->value('number') ?? '–', 'payer' => $invoice->payer_name ?? '–'])
            : $this->text('invoice', ['number' => $invoice->number, 'payer' => $invoice->payer_name ?? '–']);

        return $this->journal->post(['date' => $invoice->issued_on, 'text' => $text, 'receipt' => $invoice->number, 'kind' => $kind, 'source' => $invoice], $lines);
    }

    /**
     * Books where the money of a payment – and of the parts split off it – belongs now: to the receivables of the payer
     * of its invoice, to the credits of the family, or nowhere yet (not assigned). The first booking takes in the money
     * on the day it was paid; later moves are booked on the day they happen. Null if nothing changed.
     */
    public function bookPayment(Payment $payment): ?JournalEntry
    {
        $rootId = $payment->parent_id ?? $payment->id;

        // One booking at a time per payment: what is missing is worked out from what is booked.
        return DB::transaction(function () use ($rootId) {
            Payment::query()->whereKey($rootId)->lockForUpdate()->value('id');
            $rows = Payment::query()->where(fn ($q) => $q->whereKey($rootId)->orWhere('parent_id', $rootId))->with('invoice')->get();
            $root = $rows->firstWhere('id', $rootId);
            if (! $root instanceof Payment || ! $this->started($root->paid_on)) {
                return null;
            }
            $booked = $this->paymentLines($root);
            $money = $this->bookedMoney($booked) ?? $this->moneyAccount($root);
            $delta = $this->subtract($this->wanted($rows, $money), $this->balances($booked));
            if ($delta === []) {
                return null;
            }

            return $this->postPayment($root, $rows, $delta, $money, $this->creditPerson($booked, $rows));
        });
    }

    /**
     * The entry for what is missing: money that comes in is booked on the day it was paid, a move between the
     * receivables and the credits on the day it happens.
     *
     * @param  Collection<int, Payment>  $rows
     * @param  array<string, int>  $delta
     */
    private function postPayment(Payment $root, Collection $rows, array $delta, Account $money, ?int $creditPerson): JournalEntry
    {
        $lines = [];
        // Debit before credit, as an entry is read ("bank to receivables").
        foreach ([...array_filter($delta, fn (int $c) => $c > 0), ...array_filter($delta, fn (int $c) => $c < 0)] as $key => $cents) {
            [$account, $person] = explode(':', $key, 2);
            $lines[] = $this->side((int) $account, $cents) + ['person_id' => match (true) {
                $person === 'c' => $creditPerson, $person !== '' => (int) $person, default => null,
            }];
        }
        $receipt = array_key_exists($money->id.':', $delta);
        $payer = $root->payer_name ?? $rows->first(fn (Payment $p) => $p->invoice !== null)->invoice->payer_name ?? '–';
        $what = $this->what($rows);
        $paidOn = $root->paid_on->copy();
        $paidOn->locale($this->locale());
        $text = $receipt ? $this->text('payment', ['payer' => $payer, 'what' => $what]) : $this->text('moved', ['date' => $paidOn->isoFormat('L'), 'payer' => $payer, 'what' => $what]);
        $numbers = $rows->map(fn (Payment $p) => $p->invoice?->number)->filter()->unique()->implode(', ');

        return $this->journal->post([
            'date' => $receipt ? $root->paid_on : Carbon::today()->max($root->paid_on), 'text' => $text, 'receipt' => $numbers !== '' ? mb_substr($numbers, 0, 120) : null,
            'kind' => $receipt ? 'payment' : 'credit', 'source' => $root, 'once' => false,
        ], $lines);
    }

    /** A refund pays a credit back: from the credits to the bank account the money came in on (or the standard bank). */
    public function bookRefund(Refund $refund): ?JournalEntry
    {
        $refund = Refund::query()->with('payment')->find($refund->id);
        if ($refund === null || $refund->status !== 'done' || $refund->done_on === null || ! $this->started($refund->done_on) || $refund->amount_cents <= 0) {
            return null;
        }
        if (($booked = $this->booked($refund, 'refund')) !== null) {
            return $booked;
        }
        $lines = collect();
        $payment = $refund->payment;
        if ($payment !== null) {
            $this->bookPayment($payment); // the credit is booked before it is paid back
            $root = Payment::query()->find($payment->parent_id ?? $payment->id);
            $lines = $root !== null ? $this->paymentLines($root) : collect();
        }
        // Refunds are bank transfers: from the bank account of the payment, else the standard bank.
        $money = $this->bookedMoney($lines);
        $money = $money !== null && $money->money === 'bank' ? $money : Account::system('bank');
        $person = $lines->firstWhere('account_id', Account::system('credits')->id)->person_id ?? $refund->payer_id;

        return $this->journal->post([
            'date' => $refund->done_on, 'text' => $this->text('refund', ['payer' => $refund->payer_name ?? '–']), 'kind' => 'refund', 'source' => $refund,
        ], [
            ['account' => Account::system('credits'), 'debit' => $refund->amount_cents, 'person_id' => $person, 'text' => mb_substr($refund->reason, 0, 250)],
            ['account' => $money, 'credit' => $refund->amount_cents],
        ]);
    }

    /**
     * The payer owes the total; every line is income with its gross amount and the discount is booked on its own
     * account, both on the cost centre of the line.
     *
     * @return list<Line>
     */
    private function invoiceLines(Invoice $invoice): array
    {
        $installment = $invoice->event !== null ? $invoice->installmentAmong($invoice->event->installments) : null;
        $types = MembershipType::query()->whereIn('id', $invoice->lines->pluck('membership_type_id')->filter()->unique()->all())->get()->keyBy('id');
        // A reminder fee belongs to the event of the invoice it is for.
        $feeFor = $invoice->fee_for_id !== null ? Invoice::query()->with('event.series')->find($invoice->fee_for_id) : null;
        $discounts = Account::system('discounts');
        $rows = [$this->side(Account::system('receivables'), $invoice->total_cents) + ['person_id' => $invoice->payer_id, 'text' => $invoice->payer_name]];
        foreach ($invoice->lines as $line) {
            [$account, $center] = $this->revenue($invoice, $line, $installment, $types->get((int) $line->membership_type_id), $feeFor);
            $rows[] = $this->side($account, -$line->grossCents()) + ['cost_center' => $center, 'text' => $line->description];
            if ($line->discount_cents !== 0) {
                $rows[] = $this->side($discounts, $line->discount_cents) + ['cost_center' => $center, 'text' => $line->discount_label ?? $line->description];
            }
        }

        return $rows;
    }

    /**
     * Income account and cost centre of an invoice line. The cost centre may be null: then the account's own or the
     * standard cost centre (E1e.5).
     *
     * @return array{0: Account, 1: int|null}
     */
    private function revenue(Invoice $invoice, InvoiceLine $line, ?EventInstallment $installment, ?MembershipType $type, ?Invoice $feeFor): array
    {
        $event = $invoice->event ?? $feeFor?->event;
        // Membership fees can belong to a project, e.g. as a fixed part of the costs of the camp (E1a, E1d.5).
        $center = $installment->cost_center_id ?? $type->cost_center_id ?? $event->cost_center_id ?? $event?->series?->cost_center_id;
        $configured = $installment->revenue_account_id ?? $type?->revenue_account_id;
        $configured = $configured !== null ? Account::query()->find($configured) : null;
        $account = match (true) {
            $invoice->fee_for_id !== null => Account::system('reminder_fees'),
            $line->kind === 'cancellation_fee' => Account::system('cancellation_fees'),
            $configured !== null => $configured,
            $line->membership_type_id !== null => Account::system('membership_fees'),
            $invoice->event_id !== null => Account::system('event_income'),
            default => Account::system('other_income'),
        };

        return [$account, $center];
    }

    /**
     * The mirror of the cancelled invoice: of its booking, if it has one (so the accounts return exactly to where they
     * were), else of its lines.
     *
     * @return list<Line>
     */
    private function creditNoteLines(Invoice $credit): array
    {
        $original = $credit->corrects_id !== null ? Invoice::query()->with('lines', 'event.series', 'event.installments')->find($credit->corrects_id) : null;
        $entry = $original !== null ? $this->booked($original, 'invoice') : null;
        if ($entry !== null) {
            return array_values($entry->lines->map(fn (JournalLine $l) => [
                'account' => $l->account_id, 'debit' => $l->credit_cents, 'credit' => $l->debit_cents, 'cost_center' => $l->cost_center_id, 'person_id' => $l->person_id, 'text' => $l->text,
            ])->all());
        }
        if ($original === null) {
            return $this->invoiceLines($credit); // its lines are negative: already the mirror
        }

        return array_map(fn (array $l) => ['debit' => $l['credit'] ?? 0, 'credit' => $l['debit'] ?? 0] + $l, $this->invoiceLines($original));
    }

    /**
     * What the family of payments should have booked: per key "account:person" (person only for the receivables, "c"
     * for the credits, which belong to the family) debit minus credit.
     *
     * @param  Collection<int, Payment>  $rows
     * @return array<string, int>
     */
    private function wanted(Collection $rows, Account $money): array
    {
        $receivables = Account::system('receivables')->id;
        $credits = Account::system('credits')->id.':c';
        $wanted = [];
        foreach ($rows as $row) {
            if ($row->invoice_id !== null) {
                $key = $receivables.':'.($row->invoice->payer_id ?? '');
            } elseif (in_array($row->match, ['credit', 'refunded'], true)) {
                $key = $credits; // a refunded credit leaves with the refund
            } else {
                continue; // not assigned yet: not booked (E1, documented)
            }
            $wanted[$key] = ($wanted[$key] ?? 0) - $row->amount_cents;
            $wanted[$money->id.':'] = ($wanted[$money->id.':'] ?? 0) + $row->amount_cents;
        }

        return $wanted;
    }

    /**
     * @param  Collection<int, JournalLine>  $lines
     * @return array<string, int>
     */
    private function balances(Collection $lines): array
    {
        $receivables = Account::system('receivables')->id;
        $credits = Account::system('credits')->id;
        $balances = [];
        foreach ($lines as $line) {
            $key = $line->account_id.':'.match ($line->account_id) {
                $receivables => (string) $line->person_id, $credits => 'c', default => '',
            };
            $balances[$key] = ($balances[$key] ?? 0) + $line->debit_cents - $line->credit_cents;
        }

        return $balances;
    }

    /**
     * @param  array<string, int>  $wanted
     * @param  array<string, int>  $have
     * @return array<string, int> what is missing, without zeros
     */
    private function subtract(array $wanted, array $have): array
    {
        $delta = [];
        foreach (array_unique([...array_keys($wanted), ...array_keys($have)]) as $key) {
            $cents = ($wanted[$key] ?? 0) - ($have[$key] ?? 0);
            if ($cents !== 0) {
                $delta[(string) $key] = $cents;
            }
        }

        return $delta;
    }

    /** @return Collection<int, JournalLine> the lines booked so far for a payment and its parts (reversed ones left out) */
    private function paymentLines(Payment $root): Collection
    {
        return JournalLine::query()->whereHas('entry', fn ($q) => $q->where('source_type', $root->getMorphClass())->where('source_id', $root->id)
            ->whereIn('kind', ['payment', 'credit'])->whereDoesntHave('reversal'))->get();
    }

    /** @param  Collection<int, JournalLine>  $lines */
    private function bookedMoney(Collection $lines): ?Account
    {
        $ids = $lines->pluck('account_id')->unique()->all();

        return $ids === [] ? null : Account::query()->whereIn('id', $ids)->whereNotNull('money')->orderBy('id')->first();
    }

    /**
     * Cash goes to the cash account, everything else to the bank: the bank account of the import if one has its IBAN
     * or name, else the standard bank.
     */
    private function moneyAccount(Payment $payment): Account
    {
        if ($payment->method === 'cash') {
            return Account::system('cash');
        }
        $name = trim((string) ($payment->bank_import_id !== null ? BankImport::query()->whereKey($payment->bank_import_id)->value('account') : ''));
        if ($name !== '') {
            $iban = strtoupper(str_replace(' ', '', $name));
            $match = Account::query()->where('money', 'bank')->where('active', true)->orderBy('sort')->get()
                ->first(fn (Account $a) => ($a->iban !== null && strtoupper(str_replace(' ', '', $a->iban)) === $iban) || $a->name === $name);
            if ($match !== null) {
                return $match;
            }
        }

        return Account::system('bank');
    }

    /**
     * Whose credit it is: who it was booked for, else the payer whose receivables it leaves, else the payer of the refund
     * proposal or of an invoice of the payment.
     *
     * @param  Collection<int, JournalLine>  $booked
     * @param  Collection<int, Payment>  $rows
     */
    private function creditPerson(Collection $booked, Collection $rows): ?int
    {
        return $booked->first(fn (JournalLine $l) => $l->account_id === Account::system('credits')->id && $l->person_id !== null)->person_id
            ?? $booked->first(fn (JournalLine $l) => $l->account_id === Account::system('receivables')->id && $l->person_id !== null)->person_id
            ?? Refund::query()->whereIn('payment_id', $rows->pluck('id')->all())->whereNotNull('payer_id')->orderByDesc('id')->value('payer_id')
            ?? $rows->first(fn (Payment $p) => $p->invoice?->payer_id !== null)?->invoice?->payer_id;
    }

    /** @param  Collection<int, Payment>  $rows */
    private function what(Collection $rows): string
    {
        $numbers = $rows->map(fn (Payment $p) => $p->invoice?->number)->filter()->unique()->implode(', ');
        $parts = array_filter([
            $numbers !== '' ? $this->text('what_invoices', ['numbers' => $numbers]) : null,
            $rows->contains(fn (Payment $p) => $p->invoice_id === null && in_array($p->match, ['credit', 'refunded'], true)) ? $this->text('what_credit') : null,
        ]);

        return $parts === [] ? $this->text('what_nothing') : implode(', ', $parts);
    }

    /**
     * Texts of the journal are in the language of the installation, whoever caused the booking.
     *
     * @param  array<string, string|int|null>  $values
     */
    private function text(string $key, array $values = []): string
    {
        return (string) trans('accounting.bookings.'.$key, $values, $this->locale());
    }

    private function locale(): string
    {
        $locale = Settings::get('default_locale');

        return in_array($locale, ['de', 'en'], true) ? $locale : app()->getLocale();
    }

    /** @return array{account: Account|int, debit?: int, credit?: int} a positive amount on the debit side, a negative one on the credit side */
    private function side(Account|int $account, int $cents): array
    {
        return $cents >= 0 ? ['account' => $account, 'debit' => $cents] : ['account' => $account, 'credit' => -$cents];
    }

    /** @param  list<Line>  $lines */
    private function hasAmounts(array $lines): bool
    {
        foreach ($lines as $line) {
            if (($line['debit'] ?? 0) > 0 || ($line['credit'] ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }

    /** The accounting has started: there is a period on or before the day. Earlier documents belong to the opening. */
    private function started(\DateTimeInterface $date): bool
    {
        $first = Period::query()->min('starts_on');

        return $first !== null && Carbon::parse($date)->toDateString() >= Carbon::parse((string) $first)->toDateString();
    }

    private function hasFailures(): bool
    {
        return $this->failures !== [];
    }

    private function booked(Model $source, string $kind): ?JournalEntry
    {
        return JournalEntry::query()->with('lines')->where('kind', $kind)->where('source_type', $source->getMorphClass())->where('source_id', $source->getKey())
            ->whereDoesntHave('reversal')->first();
    }

    /**
     * Runs a booking; a failure is logged and shown to the administrators instead of stopping the finance part.
     *
     * @param  array{0: string, 1: array<string, string>}  $document  text key of accounting.bookings.document and its values
     * @param  Closure(): ?JournalEntry  $book
     */
    private function safely(array $document, Closure $book): ?JournalEntry
    {
        try {
            return $book();
        } catch (Throwable $e) {
            $this->failed($document, $e);

            return null;
        }
    }

    /** @param  array{0: string, 1: array<string, string>}  $document */
    private function failed(array $document, Throwable $e): void
    {
        $name = fn (string $locale) => (string) trans('accounting.bookings.document.'.$document[0], $document[1], $locale);
        $this->failures[] = $name(app()->getLocale()).': '.$e->getMessage();
        Log::warning('Accounting: '.$name('en').' could not be booked: '.$e->getMessage());
        if (! $e instanceof AccountingException) {
            report($e); // not a rule of the accounting: a bug, with its trace
        }
        try {
            // One notification until the backfill has booked everything; it names the last failure.
            app(SystemNotifications::class)->raise(self::NOTIFICATION, 'warning',
                ['de' => (string) trans('accounting.bookings.failed_title', [], 'de'), 'en' => (string) trans('accounting.bookings.failed_title', [], 'en')],
                ['de' => (string) trans('accounting.bookings.failed', ['document' => $name('de'), 'error' => $e->getMessage()], 'de'),
                    'en' => (string) trans('accounting.bookings.failed', ['document' => $name('en'), 'error' => $e->getMessage()], 'en')]);
        } catch (Throwable $notify) {
            report($notify);
        }
    }

    /** @return array{0: string, 1: array<string, string>} */
    private function describe(Invoice|Payment|Refund $document): array
    {
        return match (true) {
            $document instanceof Invoice => [$document->type === 'credit_note' ? 'credit_note' : 'invoice', ['number' => (string) ($document->number ?? $document->id)]],
            $document instanceof Payment => ['payment', ['date' => $document->paid_on->isoFormat('L'), 'payer' => (string) ($document->payer_name ?? '–')]],
            default => ['refund', ['date' => $document->done_on?->isoFormat('L') ?? '', 'payer' => (string) ($document->payer_name ?? '–')]],
        };
    }
}
