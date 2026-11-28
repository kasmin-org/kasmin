<?php

namespace Tests\Feature\Accounting;

use App\Filament\Resources\Events\Pages\EditEvent;
use App\Filament\Resources\EventSeries\Pages\EditEventSeries;
use App\Filament\Resources\MembershipTypes\Pages\ManageMembershipTypes;
use App\Models\Account;
use App\Models\ApiToken;
use App\Models\BankImport;
use App\Models\CostCenter;
use App\Models\Event;
use App\Models\EventSeries;
use App\Models\Household;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\MembershipType;
use App\Models\Participation;
use App\Models\Payment;
use App\Models\Period;
use App\Models\Person;
use App\Models\Refund;
use App\Models\Role;
use App\Models\SystemNotification;
use App\Models\User;
use App\Support\Accounting\Balances;
use App\Support\Accounting\DocumentBookings;
use App\Support\Accounting\Periods;
use App\Support\Events\Registrations;
use App\Support\Finance\Corrections;
use App\Support\Finance\Credits;
use App\Support\Finance\Invoices;
use App\Support\Finance\MembershipFees;
use App\Support\Finance\Payments;
use App\Support\Install\Defaults;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Bookings from invoices, credit notes, payments, credits and refunds (E1). Price 450, deposit 100, balance 350 with a
 * sibling discount of 20 per position, as in the finance tests.
 */
class DocumentBookingsTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    private Household $household;

    private Person $guardian;

    private CostCenter $camp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2027-01-15 12:00'));
        app(Defaults::class)->install('en');
        app()->setLocale('en');
        $this->camp = CostCenter::query()->create(['name' => 'Camp', 'carries' => true]);
        $this->event = Event::query()->create(['title' => 'Camp', 'starts_at' => '2027-08-01 09:00', 'ends_at' => '2027-08-08 16:00', 'status' => 'open',
            'sibling_mode' => 'staggered', 'sibling_amount_cents' => 2000, 'sibling_cap_position' => 3, 'cost_center_id' => $this->camp->id]);
        $this->event->roles()->create(['role_key' => 'child', 'capacity' => 40, 'price_cents' => 45000]);
        $this->event->installments()->create(['key' => 'deposit', 'label' => ['en' => 'Deposit'], 'kind' => 'fixed', 'amount_cents' => 10000, 'due_days' => 14, 'refundable' => false, 'discounts_apply' => false, 'sort' => 1]);
        $this->event->installments()->create(['key' => 'balance', 'label' => ['en' => 'Balance'], 'kind' => 'rest', 'due_date' => '2027-06-01', 'refundable' => true, 'refund_until_days' => 30, 'discounts_apply' => true, 'sort' => 2]);
        $this->household = Household::query()->create(['name' => 'Ash']);
        $this->guardian = Person::factory()->create(['first_name' => 'Alex', 'last_name' => 'Ash', 'birth_date' => '1985-01-01', 'locale' => 'en']);
        $this->household->members()->attach($this->guardian->id, ['role' => 'guardian']);
    }

    private function start(string $from = '2027-01-01', string $to = '2027-12-31'): Period
    {
        return app(Periods::class)->create('2027', $from, $to);
    }

    private function child(string $name, string $birth): Participation
    {
        $child = Person::factory()->create(['first_name' => $name, 'last_name' => 'Ash', 'birth_date' => $birth]);
        $child->guardians()->attach($this->guardian->id);
        $this->household->members()->attach($child->id, ['role' => 'child']);

        return Participation::query()->create(['event_id' => $this->event->id, 'person_id' => $child->id, 'role_key' => 'child', 'status' => 'registered', 'registered_at' => now()]);
    }

    private function invoice(string $installment): Invoice
    {
        $event = Event::query()->findOrFail($this->event->id);
        app(Invoices::class)->draftRun($event, $event->installments()->where('key', $installment)->firstOrFail());

        return app(Invoices::class)->issue(Invoice::query()->where('installment_key', $installment)->where('status', 'draft')->firstOrFail());
    }

    private function entry(Invoice|Payment|Refund $source, string $kind): JournalEntry
    {
        return JournalEntry::query()->where('source_type', $source->getMorphClass())->where('source_id', $source->id)->where('kind', $kind)->with('lines')->sole();
    }

    /**
     * The lines of an entry as "account: +debit/−credit [cost centre]".
     *
     * @return list<string>
     */
    private function lines(JournalEntry $entry): array
    {
        return array_values($entry->lines->map(function (JournalLine $l) {
            $account = Account::query()->findOrFail($l->account_id);
            $center = $l->cost_center_id !== null ? ' ['.CostCenter::query()->whereKey($l->cost_center_id)->value('name').']' : '';

            return ($account->system_key ?? $account->name).': '.($l->debit_cents > 0 ? '+'.$l->debit_cents : '-'.$l->credit_cents).$center;
        })->all());
    }

    private function balance(string $key, ?int $costCenter = null): int
    {
        return app(Balances::class)->account(Account::system($key), '2099-12-31', $costCenter);
    }

    public function test_an_invoice_books_the_receivable_the_gross_income_and_the_sibling_discount_on_the_cost_centre_of_the_event(): void
    {
        $this->start();
        $this->child('Ann', '2014-01-01');
        $this->child('Ben', '2016-01-01');
        $balance = $this->invoice('balance');
        $this->assertSame(68000, $balance->total_cents, '350 + 350 − 20');

        $entry = $this->entry($balance, 'invoice');
        $this->assertSame('2027-01-15', $entry->date->toDateString());
        $this->assertSame($balance->number, $entry->receipt);
        $this->assertSame(['receivables: +68000', 'event_income: -35000 [Camp]', 'event_income: -35000 [Camp]', 'discounts: +2000 [Camp]'], $this->lines($entry));
        $this->assertSame($this->guardian->id, $entry->lines->first()?->person_id, 'the receivable is the payer’s');
        $this->assertSame(68000, $this->balance('receivables'));
        $this->assertSame(-70000, $this->balance('event_income', $this->camp->id));
        $this->assertSame(2000, $this->balance('discounts', $this->camp->id));
    }

    /** The example of docs/admin/invoices.md, "Bookings" (E1i: every example of the documentation is a test). */
    public function test_the_example_of_the_documentation(): void
    {
        $this->start();
        $this->child('Ann', '2014-01-01');
        $this->child('Ben', '2016-01-01');
        $balance = $this->invoice('balance');
        $this->assertSame(['receivables: +68000', 'event_income: -35000 [Camp]', 'event_income: -35000 [Camp]', 'discounts: +2000 [Camp]'], $this->lines($this->entry($balance, 'invoice')));
        app(Payments::class)->record($balance, 70000, Carbon::now());
        $this->assertSame(['bank: +70000', 'receivables: -68000', 'credits: -2000'], $this->lines($this->entry(Payment::query()->whereNull('parent_id')->sole(), 'payment')));
        $refund = Refund::query()->sole();
        app(Credits::class)->markRefunded($refund, Carbon::now());
        $this->assertSame(['credits: +2000', 'bank: -2000'], $this->lines($this->entry($refund, 'refund')));
        $this->assertSame([0, 0, 68000, -70000, 2000], [$this->balance('receivables'), $this->balance('credits'), $this->balance('bank'),
            $this->balance('event_income', $this->camp->id), $this->balance('discounts', $this->camp->id)]);
    }

    public function test_income_account_and_cost_centre_come_from_the_instalment_the_membership_type_the_event_or_the_series(): void
    {
        $this->start();
        $fees = Account::query()->create(['name' => 'Camp contributions', 'type' => 'income']);
        $own = CostCenter::query()->create(['name' => 'Deposits']);
        $this->event->installments()->where('key', 'deposit')->update(['revenue_account_id' => $fees->id, 'cost_center_id' => $own->id]);
        // The membership fee as an instalment: booked as membership fee, on the cost centre of its type (the camp).
        $type = MembershipType::query()->where('key', 'child')->firstOrFail();
        $type->forceFill(['fee_cents' => 8000, 'cost_center_id' => $this->camp->id])->save();
        $this->event->installments()->create(['key' => 'fee', 'label' => ['en' => 'Fee'], 'kind' => 'member', 'membership_type_id' => $type->id, 'sort' => 0, 'discounts_apply' => false]);
        $this->event->update(['cost_center_id' => null]);
        $this->child('Ann', '2014-01-01');

        $this->assertSame(['receivables: +10000', 'Camp contributions: -10000 [Deposits]'], $this->lines($this->entry($this->invoice('deposit'), 'invoice')));
        $this->assertSame(['receivables: +8000', 'membership_fees: -8000 [Camp]'], $this->lines($this->entry($this->invoice('fee'), 'invoice')));
        // Without a cost centre of the event, the one of the series; without that, the standard one.
        $series = EventSeries::query()->create(['name' => 'Camps', 'cost_center_id' => $own->id]);
        $this->event->update(['event_series_id' => $series->id]);
        $this->assertSame(['receivables: +27000', 'event_income: -27000 [Deposits]'], $this->lines($this->entry($this->invoice('balance'), 'invoice')), '450 − 100 − 80');
        $series->update(['cost_center_id' => null]);
        $single = app(Invoices::class)->issue(app(Invoices::class)->single($this->guardian, [['description' => 'T-shirt', 'amount_cents' => 1500]]));
        $this->assertSame(['receivables: +1500', 'other_income: -1500 [General]'], $this->lines($this->entry($single, 'invoice')));
    }

    public function test_membership_fee_invoices_reminder_fees_and_cancellation_fees_have_their_own_accounts(): void
    {
        $this->start();
        $type = MembershipType::query()->where('key', 'child')->firstOrFail();
        $type->forceFill(['fee_cents' => 5000, 'cost_center_id' => $this->camp->id])->save();
        $ann = $this->child('Ann', '2014-01-01');
        Person::query()->findOrFail($ann->person_id)->memberships()->create(['membership_type_id' => $type->id, 'starts_on' => '2027-01-01']);
        $fees = app(MembershipFees::class);
        $membership = app(Invoices::class)->issue($fees->draft(2027, $fees->pending(2027)->first() ?? collect()));
        $this->assertSame(['receivables: +5000', 'membership_fees: -5000 [Camp]'], $this->lines($this->entry($membership, 'invoice')));

        $fee = Invoice::query()->create(['payer_id' => $this->guardian->id, 'payer_name' => 'Alex Ash', 'total_cents' => 500, 'fee_for_id' => $membership->id]);
        $fee->lines()->create(['description' => 'Reminder fee', 'amount_cents' => 500, 'discount_cents' => 0]);
        $this->assertSame(['receivables: +500', 'reminder_fees: -500 [General]'], $this->lines($this->entry(app(Invoices::class)->issue($fee), 'invoice')), 'a fee invoice has no event');

        $cancelled = Invoice::query()->create(['event_id' => $this->event->id, 'installment_key' => 'balance', 'payer_id' => $this->guardian->id, 'payer_name' => 'Alex Ash', 'total_cents' => 2500]);
        $cancelled->lines()->create(['participation_id' => $ann->id, 'person_id' => $ann->person_id, 'description' => 'Cancellation fee – Ann Ash', 'amount_cents' => 2500, 'discount_cents' => 0, 'kind' => 'cancellation_fee']);
        $this->assertSame(['receivables: +2500', 'cancellation_fees: -2500 [Camp]'], $this->lines($this->entry(app(Invoices::class)->issue($cancelled), 'invoice')));
    }

    public function test_a_credit_note_mirrors_the_invoice_and_a_payment_on_it_becomes_a_credit(): void
    {
        $this->start();
        $this->child('Ann', '2014-01-01');
        $this->child('Ben', '2016-01-01');
        $balance = $this->invoice('balance');
        app(Payments::class)->record($balance, 30000, Carbon::now());
        $this->travelTo(Carbon::parse('2027-02-01 10:00'));
        $credit = app(Invoices::class)->cancel($balance, 'Moved away');

        $entry = $this->entry($credit, 'credit_note');
        $this->assertSame('2027-02-01', $entry->date->toDateString());
        $this->assertSame(['receivables: -68000', 'event_income: +35000 [Camp]', 'event_income: +35000 [Camp]', 'discounts: -2000 [Camp]'], $this->lines($entry));
        // What was paid leaves the receivables and becomes a credit of the family, on the day of the cancellation.
        $payment = Payment::query()->sole();
        $move = $this->entry($payment, 'credit');
        $this->assertSame(['2027-02-01', ['receivables: +30000', 'credits: -30000']], [$move->date->toDateString(), $this->lines($move)]);
        $this->assertSame([0, 0, 0, -30000, 30000], [$this->balance('receivables'), $this->balance('event_income'), $this->balance('discounts'), $this->balance('credits'), $this->balance('bank')]);
    }

    public function test_payments_go_to_the_bank_cash_to_the_cash_account_and_an_import_to_its_bank_account(): void
    {
        $this->start();
        $this->child('Ann', '2014-01-01');
        $deposit = $this->invoice('deposit');
        $balance = $this->invoice('balance');
        app(Payments::class)->record($deposit, 4000, Carbon::parse('2027-01-20'));
        app(Payments::class)->record($deposit, 6000, Carbon::parse('2027-01-21'), 'cash');
        $bank = Payment::query()->where('method', 'bank')->sole();
        $entry = $this->entry($bank, 'payment');
        $this->assertSame(['2027-01-20', ['bank: +4000', 'receivables: -4000']], [$entry->date->toDateString(), $this->lines($entry)]);
        $this->assertSame($this->guardian->id, $entry->lines->firstWhere('credit_cents', 4000)?->person_id);
        $this->assertSame(['cash: +6000', 'receivables: -6000'], $this->lines($this->entry(Payment::query()->where('method', 'cash')->sole(), 'payment')));

        $savings = Account::query()->create(['name' => 'Savings', 'type' => 'asset', 'money' => 'bank', 'iban' => 'AT61 1904 3002 3457 3201']);
        $import = BankImport::query()->create(['account' => 'AT611904300234573201', 'file_name' => 'x.csv', 'mapping' => []]);
        $imported = Payment::query()->create(['amount_cents' => 35000, 'paid_on' => '2027-01-22', 'method' => 'bank', 'match' => 'unmatched', 'bank_import_id' => $import->id]);
        $this->assertSame(0, JournalEntry::query()->where('source_id', $imported->id)->where('source_type', $imported->getMorphClass())->count(), 'not assigned: not booked');
        app(Payments::class)->assign($imported, $balance);
        $this->assertSame(['Savings: +35000', 'receivables: -35000'], $this->lines($this->entry($imported, 'payment')));
        $this->assertSame(0, $this->balance('receivables'), 'invoice and payment: nothing owed');
        $this->assertSame([4000, 6000, 35000], [$this->balance('bank'), $this->balance('cash'), app(Balances::class)->account($savings, '2027-12-31')]);
    }

    public function test_an_overpayment_becomes_a_credit_that_is_used_later_or_paid_back(): void
    {
        $this->start();
        $this->child('Ann', '2016-01-01');
        $deposit = $this->invoice('deposit');
        app(Payments::class)->record($deposit, 13000, Carbon::now());
        $root = Payment::query()->whereNull('parent_id')->sole();
        $this->assertSame(['bank: +13000', 'receivables: -10000', 'credits: -3000'], $this->lines($this->entry($root, 'payment')));
        $this->assertSame($this->guardian->id, JournalLine::query()->where('account_id', Account::system('credits')->id)->value('person_id'));

        // Used for the next invoice: from the credits to the receivables, the bank does not move.
        $balance = $this->invoice('balance');
        app(Credits::class)->useFor(Payment::query()->where('match', 'credit')->sole(), $balance);
        $this->assertSame(['credits: +3000', 'receivables: -3000'], $this->lines($this->entry($root, 'credit')));
        $this->assertSame([0, 13000, 32000], [$this->balance('credits'), $this->balance('bank'), $this->balance('receivables')], '350 − 30 still open');

        // Paid twice: the second transfer becomes a credit and is paid back.
        $twice = Payment::query()->create(['amount_cents' => 2000, 'paid_on' => '2027-01-16', 'method' => 'bank', 'match' => 'unmatched', 'payer_name' => 'Alex Ash']);
        $refund = app(Credits::class)->makeCredit($twice, 'Paid twice') ?? $this->fail('no refund proposal');
        $this->assertSame(['bank: +2000', 'credits: -2000'], $this->lines($this->entry($twice, 'payment')));
        app(Credits::class)->markRefunded($refund, Carbon::parse('2027-01-18'));
        $entry = $this->entry($refund, 'refund');
        $this->assertSame(['2027-01-18', ['credits: +2000', 'bank: -2000']], [$entry->date->toDateString(), $this->lines($entry)]);
        $this->assertSame([0, 13000], [$this->balance('credits'), $this->balance('bank')]);
    }

    public function test_a_correction_moves_the_payment_and_the_refund_pays_the_difference_back(): void
    {
        $this->start();
        $this->child('Ann', '2014-01-01');
        $ben = $this->child('Ben', '2016-01-01');
        $balance = $this->invoice('balance');
        app(Payments::class)->record($balance, 68000, Carbon::now());
        app(Registrations::class)->cancel($ben->load('event'), 'ill');
        $new = app(Corrections::class)->correct(Event::query()->findOrFail($this->event->id), $balance, 'changed') ?? $this->fail('no new invoice');
        $this->assertSame(35000, $new->total_cents);

        $root = Payment::query()->whereNull('parent_id')->sole();
        $this->assertSame(['receivables: +33000', 'credits: -33000'], $this->lines($this->entry($root, 'credit')), 'only the difference becomes a credit');
        app(Credits::class)->markRefunded(Refund::query()->sole(), Carbon::now());
        $this->assertSame([0, 0, 35000, -35000], [$this->balance('receivables'), $this->balance('credits'), $this->balance('bank'), $this->balance('event_income') + $this->balance('discounts')]);
    }

    public function test_every_document_is_booked_once(): void
    {
        $this->start();
        $this->child('Ann', '2014-01-01');
        $deposit = $this->invoice('deposit');
        app(Payments::class)->record($deposit, 10000, Carbon::now());
        $bookings = app(DocumentBookings::class);
        $bookings->bookInvoice($deposit);
        $bookings->bookPayment(Payment::query()->sole());
        $bookings->paymentChanged(Payment::query()->sole());
        $this->assertSame(0, $bookings->backfill());
        $this->assertSame(2, JournalEntry::query()->count());
        $this->assertSame([1, 2], JournalEntry::query()->orderBy('number')->pluck('number')->all());
        $this->expectException(\RuntimeException::class);
        app(Invoices::class)->issue($deposit); // issuing twice is refused anyway
    }

    public function test_nothing_is_booked_before_the_first_period_and_the_backfill_books_what_is_missing(): void
    {
        $this->child('Ann', '2014-01-01');
        $this->child('Ben', '2016-01-01');
        $deposit = $this->invoice('deposit');
        app(Payments::class)->record($deposit, 25000, Carbon::now());
        $balance = $this->invoice('balance');
        $this->assertSame(0, JournalEntry::query()->count(), 'the accounting has not started');

        // A period that starts later: the documents before it belong to the opening.
        $period = $this->start('2027-02-01', '2028-01-31');
        $this->assertSame(0, app(DocumentBookings::class)->backfill());
        $this->assertSame(0, JournalEntry::query()->count());

        app(Periods::class)->move($period, '2027-01-01', '2027-12-31');
        $this->artisan('kasmin:book-documents')->expectsOutputToContain('3 bookings made.')->assertSuccessful(); // @phpstan-ignore method.nonObject
        $this->assertSame(['invoice', 'invoice', 'payment'], JournalEntry::query()->orderBy('number')->pluck('kind')->all());
        $root = Payment::query()->whereNull('parent_id')->sole();
        $this->assertSame(['bank: +25000', 'receivables: -20000', 'credits: -5000'], $this->lines($this->entry($root, 'payment')));
        $this->assertSame($balance->total_cents, $this->balance('receivables'), 'deposit paid, balance open, the credit is not used');
        $this->assertSame(0, app(DocumentBookings::class)->backfill(), 'nothing twice');
        $this->assertSame(0, app(DocumentBookings::class)->backfill(Carbon::parse('2027-06-01')));
    }

    public function test_a_failure_of_the_accounting_never_stops_issuing_or_paying(): void
    {
        $period = $this->start();
        $period->forceFill(['status' => 'closed'])->save();
        $this->child('Ann', '2014-01-01');
        $deposit = $this->invoice('deposit');
        app(Payments::class)->record($deposit, 10000, Carbon::now());

        $this->assertSame(['paid', 0], [$deposit->refresh()->status, JournalEntry::query()->count()]);
        $notice = SystemNotification::query()->where('key', 'accounting_booking')->whereNull('resolved_at')->sole();
        $this->assertStringContainsString('is closed', $notice->text('message', 'en'));
        $this->assertStringContainsString('Buchhaltung', $notice->text('message', 'de'));

        $period->forceFill(['status' => 'open'])->save();
        $this->assertSame(2, app(DocumentBookings::class)->backfill());
        $this->assertNotNull($notice->refresh()->resolved_at, 'solved once everything is booked');
    }

    public function test_rest_and_mcp_set_the_account_and_cost_centre_of_events_series_instalments_and_membership_types(): void
    {
        Settings::set('ai_processing', 'non_confidential');
        $this->guardian->roleAssignments()->create(['role_id' => Role::query()->where('key', 'administrator')->value('id')]);
        $user = User::query()->create(['name' => 'Alex', 'email' => 'alex@example.org', 'password' => 'x', 'person_id' => $this->guardian->id]);
        $token = ApiToken::issue($user, 'test', ['events:read', 'events:write', 'people:read', 'people:write'])['plain'];
        $fees = Account::query()->create(['name' => 'Camp contributions', 'type' => 'income']);
        $id = $this->event->public_id;

        $this->withToken($token)->patchJson("/api/v1/events/$id", ['cost_center' => 'Nowhere'])->assertUnprocessable()->assertJsonValidationErrors('cost_center');
        $this->withToken($token)->patchJson("/api/v1/events/$id", ['installments' => [['key' => 'deposit', 'revenue_account' => 'Bank']]])
            ->assertUnprocessable()->assertJsonValidationErrors('installments.0.revenue_account'); // not an income account
        $this->withToken($token)->patchJson("/api/v1/events/$id", ['cost_center' => null, 'installments' => [['key' => 'deposit', 'revenue_account' => 'Camp contributions', 'cost_center' => (string) $this->camp->id], ['key' => 'balance']]])
            ->assertOk()->assertJsonPath('data.cost_center', null)->assertJsonPath('data.installments.0.revenue_account', 'Camp contributions')
            ->assertJsonPath('data.installments.0.revenue_account_id', $fees->id)->assertJsonPath('data.installments.0.cost_center', 'Camp')->assertJsonPath('data.installments.1.cost_center', null);
        $this->assertSame([null, $fees->id, $this->camp->id], [$this->event->refresh()->cost_center_id, $this->event->installments()->where('key', 'deposit')->value('revenue_account_id'), $this->event->installments()->where('key', 'deposit')->value('cost_center_id')]);

        $series = $this->withToken($token)->postJson('/api/v1/event-series', ['name' => 'Camps', 'cost_center' => 'Camp'])->assertCreated()->assertJsonPath('data.cost_center', 'Camp')->json('data.id');
        $this->withToken($token)->patchJson("/api/v1/event-series/$series", ['cost_center' => $this->camp->id])->assertOk()->assertJsonPath('data.cost_center_id', $this->camp->id);
        $this->withToken($token)->patchJson('/api/v1/membership-types/child', ['revenue_account' => $fees->id, 'cost_center' => 'Camp'])->assertOk()
            ->assertJsonPath('data.revenue_account', 'Camp contributions')->assertJsonPath('data.cost_center', 'Camp');
        $this->withToken($token)->patchJson('/api/v1/membership-types/child', ['cost_center' => 'Nowhere'])->assertUnprocessable()->assertJsonValidationErrors('cost_center');

        // The same through MCP.
        $mcp = ApiToken::issue($user, 'assistant', ['events:read', 'events:write', 'people:read', 'people:write'], true)['plain'];
        $tool = fn (string $name, array $arguments) => (array) json_decode((string) $this->withToken($mcp)->withHeaders(['Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => '2025-06-18'])
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $arguments]])->assertOk()->json('result.content.0.text'), true);
        $this->assertSame('Camp', $tool('update_event', ['id' => $id, 'cost_center' => 'Camp'])['data']['cost_center'] ?? null);
        $this->assertSame($this->camp->id, $this->event->refresh()->cost_center_id);
        $tool('update_event', ['id' => $id, 'clear' => ['cost_center']]);
        $this->assertNull($this->event->refresh()->cost_center_id);
        $this->assertSame('Camp', $tool('update_event_series', ['id' => $series, 'cost_center' => 'Camp'])['data']['cost_center'] ?? null);
        $this->assertSame('General', $tool('update_membership_type', ['key' => 'child', 'cost_center' => 'General'])['data']['cost_center'] ?? null);
    }

    public function test_the_forms_offer_the_income_account_and_the_cost_centre_only_while_cost_centres_are_used(): void
    {
        $this->withoutMiddleware(AuthenticateSession::class);
        $this->guardian->roleAssignments()->create(['role_id' => Role::query()->where('key', 'administrator')->value('id')]);
        $this->actingAs(User::query()->create(['name' => 'Alex', 'email' => 'alex@example.org', 'password' => 'x', 'person_id' => $this->guardian->id]));
        $fees = Account::query()->create(['name' => 'Camp contributions', 'type' => 'income']);
        $type = MembershipType::query()->where('key', 'child')->firstOrFail();
        $series = EventSeries::query()->create(['name' => 'Camps']);

        Livewire::test(EditEvent::class, ['record' => $this->event->getRouteKey()])->assertFormFieldHidden('cost_center_id');
        Livewire::test(EditEventSeries::class, ['record' => $series->getRouteKey()])->assertFormFieldHidden('cost_center_id');
        Livewire::test(ManageMembershipTypes::class)->callTableAction('edit', $type, data: ['name' => 'Child', 'pays_fee' => true, 'fee_cents' => '80', 'revenue_account_id' => $fees->id])
            ->assertHasNoTableActionErrors();
        $this->assertSame([$fees->id, null], [$type->refresh()->revenue_account_id, $type->cost_center_id]);

        Settings::set('use_cost_centers', '1');
        Livewire::test(EditEvent::class, ['record' => $this->event->getRouteKey()])->assertFormFieldVisible('cost_center_id')
            ->fillForm(['cost_center_id' => null])->call('save')->assertHasNoFormErrors();
        $this->assertNull($this->event->refresh()->cost_center_id);
        Livewire::test(EditEventSeries::class, ['record' => $series->getRouteKey()])->fillForm(['cost_center_id' => $this->camp->id])->call('save')->assertHasNoFormErrors();
        $this->assertSame($this->camp->id, $series->refresh()->cost_center_id);
        Livewire::test(ManageMembershipTypes::class)->callTableAction('edit', $type, data: ['name' => 'Child', 'pays_fee' => true, 'fee_cents' => '80', 'cost_center_id' => $this->camp->id])
            ->assertHasNoTableActionErrors();
        $this->assertSame($this->camp->id, $type->refresh()->cost_center_id);
    }
}
