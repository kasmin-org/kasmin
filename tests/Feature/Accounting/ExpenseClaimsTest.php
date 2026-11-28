<?php

namespace Tests\Feature\Accounting;

use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\CostCenter;
use App\Models\Event;
use App\Models\EventSeries;
use App\Models\ExpenseClaim;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\NotificationEvent;
use App\Models\OutgoingMail;
use App\Models\Period;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Accounting\AccountingException;
use App\Support\Accounting\Advances;
use App\Support\Accounting\Balances;
use App\Support\Accounting\ExpenseClaims;
use App\Support\Accounting\Periods;
use App\Support\Accounting\SepaCreditTransfer;
use App\Support\Install\Defaults;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Expense claims and advances (E1d.3): submitted in the portal or entered centrally, approved (expense to "expenses to
 * pay back"), paid out by bank (SEPA file) or in cash, or waived as a donation; advances handed out, settled with
 * receipts and the rest returned. The numbers are those of the walkthrough in docs/admin/expenses.md.
 */
class ExpenseClaimsTest extends TestCase
{
    use RefreshDatabase;

    private ExpenseClaims $claims;

    private Person $kim;

    private User $treasurer;

    protected function setUp(): void
    {
        parent::setUp();
        app(Defaults::class)->install('en');
        app()->setLocale('en');
        Settings::set('organisation_name', 'Example camp');
        Settings::set('organisation_iban', 'AT611904300234573201');
        Settings::set('organisation_bic', 'BKAUATWW');
        app(Periods::class)->create('This year', now()->subMonths(6)->toDateString(), now()->addMonths(6)->toDateString());
        $this->claims = app(ExpenseClaims::class);
        $this->kim = $this->person('Kim', 'Kale', 'kim@example.org');
        $this->kim->roleAssignments()->create(['role_id' => Role::query()->where('key', 'counsellor')->value('id')]);
        $this->treasurer = $this->user('treasurer', 'Toni', 'Teller');
    }

    private function person(string $first, string $last, ?string $email = null): Person
    {
        $person = Person::factory()->create(['first_name' => $first, 'last_name' => $last, 'locale' => 'en', 'birth_date' => '1990-01-01']);
        if ($email !== null) {
            $person->contactPoints()->create(['type' => 'email', 'value' => $email, 'is_primary' => true]);
        }

        return $person;
    }

    private function user(string $role, string $first, string $last): User
    {
        $person = $this->person($first, $last, strtolower($first).'@example.org');
        $person->roleAssignments()->create(['role_id' => Role::query()->where('key', $role)->value('id')]);

        // An administrative role with an address may already have made the login.
        return User::query()->where('person_id', $person->id)->first()
            ?? User::query()->create(['name' => $first, 'email' => strtolower($first).'@example.org', 'password' => 'x', 'person_id' => $person->id]);
    }

    private function account(string $name): Account
    {
        return Account::query()->where('name', $name)->firstOrFail();
    }

    private function balance(string $key): int
    {
        return app(Balances::class)->account(Account::system($key), now()->addMonth());
    }

    /**
     * The values at an XPath of a SEPA file (namespace prefix p).
     *
     * @return \Closure(string): list<string>
     */
    private function sepa(string $xml): \Closure
    {
        $doc = simplexml_load_string($xml);
        $this->assertNotFalse($doc, 'the SEPA file is well-formed XML');
        $doc->registerXPathNamespace('p', SepaCreditTransfer::NAMESPACE);

        return fn (string $path): array => array_map('strval', array_values($doc->xpath($path) ?: []));
    }

    /** @param  array<string, mixed>  $data */
    private function claim(array $data = [], ?Person $person = null, ?User $by = null): ExpenseClaim
    {
        return $this->claims->submit($person ?? $this->kim, $data + ['date' => now()->subDays(3)->toDateString(), 'amount_cents' => 6170, 'purpose' => 'Craft material'], $by);
    }

    public function test_a_claim_is_approved_into_expenses_to_pay_back_with_the_cost_centre_of_the_event(): void
    {
        $camp = CostCenter::query()->create(['name' => 'Summer camp', 'carries' => true]);
        $series = EventSeries::query()->create(['name' => 'Summer camp']);
        $series->forceFill(['cost_center_id' => $camp->id])->save();
        $event = Event::query()->create(['title' => 'Camp 2027', 'event_series_id' => $series->id, 'starts_at' => now()->addMonth(), 'ends_at' => now()->addMonth()->addWeek(), 'status' => 'open']);
        Storage::fake('local');

        $claim = $this->claim(['event_id' => $event->id, 'account_id' => $this->account('Material')->id, 'receipt' => UploadedFile::fake()->image('receipt.jpg')], by: User::query()->create(['name' => 'Kim', 'email' => 'kim@example.org', 'password' => 'x', 'person_id' => $this->kim->id]));
        $this->assertSame('submitted', $claim->status);
        $this->assertSame($camp->id, $claim->cost_center_id, 'the cost centre comes from the series of the event');
        Storage::disk('local')->assertExists((string) $claim->receipt_path);
        $this->assertStringStartsWith(ExpenseClaims::RECEIPTS.'/', (string) $claim->receipt_path);
        $this->assertSame(1, NotificationEvent::query()->where('topic', 'expense_claims')->count());
        $this->assertFalse($claim->enteredCentrally());

        $this->claims->approve($claim, $this->treasurer);
        $this->assertSame('approved', $claim->refresh()->status);
        $entry = JournalEntry::query()->where('kind', 'expense')->sole();
        $expense = $entry->lines->firstWhere('debit_cents', 6170);
        $this->assertNotNull($expense);
        $this->assertSame($this->account('Material')->id, $expense->account_id);
        $this->assertSame($camp->id, $expense->cost_center_id);
        $this->assertSame($this->kim->id, $entry->lines->firstWhere('credit_cents', 6170)?->person_id);
        $this->assertSame(-6170, $this->balance('open_expenses'), 'the organisation owes Kim 61.70');
        $this->assertSame($claim->receipt_path, $entry->receipt_path);
        $mail = OutgoingMail::query()->where('to_address', 'kim@example.org')->sole();
        $this->assertStringContainsString('approved', $mail->subject);
        $this->assertStringContainsString('61,70', (string) $mail->html);

        // Approved only once.
        $this->expectException(AccountingException::class);
        $this->claims->approve($claim, $this->treasurer);
    }

    public function test_a_claim_entered_centrally_needs_no_receipt_and_the_standard_account_and_cost_centre(): void
    {
        $claim = $this->claim(by: $this->treasurer);
        $this->assertNull($claim->receipt_path);
        $this->assertTrue($claim->enteredCentrally());
        $this->assertSame('Other expenses', $claim->account?->name);
        $this->assertSame(CostCenter::default()->id, $claim->cost_center_id);
    }

    public function test_four_eyes_the_one_who_entered_and_the_person_itself_do_not_approve(): void
    {
        Settings::set('expense_four_eyes', '1');
        $claim = $this->claim(by: $this->treasurer);
        $this->assertFalse($this->claims->mayApprove($claim, $this->treasurer));
        $own = $this->claim(person: $this->treasurer->person);
        $this->assertFalse($this->claims->mayApprove($own, $this->treasurer));
        try {
            $this->claims->approve($claim, $this->treasurer);
            $this->fail('the one who entered it approved it');
        } catch (AccountingException) {
        }
        $this->assertSame(0, JournalEntry::query()->count());

        $second = $this->user('board', 'Bo', 'Board');
        $this->assertTrue($this->claims->mayApprove($claim, $second));
        $this->claims->approve($claim, $second);
        $this->assertSame('approved', $claim->refresh()->status);
        $this->assertSame($second->id, $claim->decided_by_id);

        Settings::set('expense_four_eyes', '0');
        $this->assertTrue($this->claims->mayApprove($own, $this->treasurer));
        $this->assertFalse($this->claims->mayApprove($own, User::query()->create(['name' => 'Kim', 'email' => 'k2@example.org', 'password' => 'x', 'person_id' => $this->kim->id])), 'only finance roles approve');
    }

    public function test_a_rejection_mails_the_reason_and_books_nothing(): void
    {
        $claim = $this->claim();
        $this->claims->reject($claim, $this->treasurer, 'Private purchase');
        $this->assertSame('rejected', $claim->refresh()->status);
        $this->assertSame(0, JournalEntry::query()->count());
        $mail = OutgoingMail::query()->where('to_address', 'kim@example.org')->sole();
        $this->assertStringContainsString('not been approved', $mail->subject);
        $this->assertStringContainsString('Private purchase', (string) $mail->html);
    }

    public function test_a_bank_payout_books_from_the_bank_and_gives_a_sepa_file_per_claim_or_per_person(): void
    {
        $this->claims->setIban($this->kim, 'AT48 3200 0000 1234 5864');
        $robin = $this->person('Robin', 'Röhrich', 'robin@example.org');
        $this->claims->setIban($robin, 'DE89370400440532013000');
        $a = $this->claim();
        $b = $this->claim(['amount_cents' => 2500, 'purpose' => 'Train ticket']);
        $c = $this->claim(['amount_cents' => 1830, 'purpose' => 'Postage & stamps'], $robin);
        foreach ([$a, $b, $c] as $claim) {
            $this->claims->approve($claim, $this->treasurer);
        }
        $result = $this->claims->pay([$a, $b, $c], Account::system('bank'), now()->toDateString(), false, $this->treasurer);

        $sepa = $this->sepa((string) $result['sepa']);
        $this->assertSame(['3'], $sepa('//p:GrpHdr/p:NbOfTxs'));
        $this->assertSame(['105.00'], $sepa('//p:GrpHdr/p:CtrlSum'));
        $this->assertSame(['105.00'], $sepa('//p:PmtInf/p:CtrlSum'));
        $this->assertSame(['AT611904300234573201'], $sepa('//p:DbtrAcct/p:Id/p:IBAN'));
        $this->assertSame(['BKAUATWW'], $sepa('//p:DbtrAgt/p:FinInstnId/p:BICFI'));
        $this->assertSame(['Example camp'], $sepa('//p:Dbtr/p:Nm'));
        $this->assertCount(3, $sepa('//p:CdtTrfTxInf'));
        $this->assertSame(['AT483200000012345864', 'AT483200000012345864', 'DE89370400440532013000'], $sepa('//p:CdtTrfTxInf/p:CdtrAcct/p:Id/p:IBAN'));
        $this->assertSame(['61.70', '25.00', '18.30'], $sepa('//p:CdtTrfTxInf/p:Amt/p:InstdAmt'));
        $this->assertSame('Robin Roehrich', $sepa('//p:CdtTrfTxInf/p:Cdtr/p:Nm')[2], 'SEPA character set');
        $this->assertSame('Refund of expenses: Postage + stamps', $sepa('//p:CdtTrfTxInf/p:RmtInf/p:Ustrd')[2]);
        $this->assertSame(['EUR', 'EUR', 'EUR'], $sepa('//p:InstdAmt/@Ccy'));

        $this->assertSame(0, $this->balance('open_expenses'));
        $this->assertSame(-10500, $this->balance('bank'));
        $this->assertSame(['paid'], ExpenseClaim::query()->distinct()->pluck('status')->all());
        $this->assertSame('AT483200000012345864', $a->refresh()->iban, 'the IBAN is copied into the claim');
        $this->assertSame(Account::system('bank')->id, $a->paid_from_account_id);
        $this->assertSame(3, OutgoingMail::query()->where('subject', 'like', '%paid back%')->count());
        $this->assertStringNotContainsString('3200', (string) OutgoingMail::query()->get()->pluck('html')->implode(' '), 'no IBAN in mails');

        // Summed per person: one transfer for Kim's two claims.
        $d = $this->claim(['amount_cents' => 1000, 'purpose' => 'Glue']);
        $e = $this->claim(['amount_cents' => 500, 'purpose' => 'Tape']);
        $this->claims->approve($d, $this->treasurer);
        $this->claims->approve($e, $this->treasurer);
        $sepa = $this->sepa((string) $this->claims->pay([$d, $e], Account::system('bank'), now()->toDateString(), true)['sepa']);
        $this->assertSame(['1'], $sepa('//p:GrpHdr/p:NbOfTxs'));
        $this->assertSame(['15.00'], $sepa('//p:CdtTrfTxInf/p:Amt/p:InstdAmt'));
        $this->assertSame(['Refund of expenses: Glue, Tape'], $sepa('//p:Ustrd'));
        $this->assertSame(2, JournalEntry::query()->where('kind', 'expense_payout')->whereIn('source_id', [$d->id, $e->id])->count(), 'one booking per claim');
    }

    public function test_a_bank_payout_needs_the_iban_of_every_person_and_the_payment_code_has_it(): void
    {
        $claim = $this->claim();
        $this->claims->approve($claim, $this->treasurer);
        $this->assertNull($this->claims->qrCode($claim));
        try {
            $this->claims->pay([$claim], Account::system('bank'), now());
            $this->fail('paid without IBAN');
        } catch (AccountingException $e) {
            $this->assertStringContainsString('Kim Kale', $e->getMessage());
        }
        $this->assertSame('approved', $claim->refresh()->status);
        $this->claims->setIban($this->kim, 'AT483200000012345864');
        $this->assertStringStartsWith('data:image/png;base64,', (string) $this->claims->qrCode($claim->refresh()));
    }

    public function test_a_cash_payout_books_from_the_cash_and_needs_no_iban(): void
    {
        $claim = $this->claim();
        $this->claims->approve($claim, $this->treasurer);
        $result = $this->claims->pay([$claim], Account::system('cash'), now()->toDateString());
        $this->assertNull($result['sepa']);
        $this->assertSame(-6170, $this->balance('cash'));
        $this->assertSame(0, $this->balance('open_expenses'));
        $this->assertNull($claim->refresh()->iban);
        $this->assertStringContainsString('in cash', (string) OutgoingMail::query()->where('subject', 'like', '%paid back%')->sole()->html);
        // Paid only once.
        $this->expectException(AccountingException::class);
        $this->claims->pay([$claim], Account::system('cash'), now()->toDateString());
    }

    public function test_a_waived_refund_is_a_donation_in_the_cost_centre_of_the_expense(): void
    {
        $camp = CostCenter::query()->create(['name' => 'Summer camp', 'carries' => true]);
        $claim = $this->claim(['cost_center_id' => $camp->id]);
        $this->claims->approve($claim, $this->treasurer);
        $this->claims->donate($claim, $this->treasurer);
        $this->assertSame('donated', $claim->refresh()->status);
        $this->assertSame(0, $this->balance('open_expenses'));
        $donation = JournalLine::query()->where('account_id', Account::system('donations')->id)->sole();
        $this->assertSame(6170, $donation->credit_cents);
        $this->assertSame($camp->id, $donation->cost_center_id);
        $this->assertSame(0, app(Balances::class)->results(Period::query()->sole())[$camp->id] ?? 0, 'expense and donation even out in the cost centre');

        // Waived already when submitting: approved and donated in one go.
        $waived = $this->claim(['waived' => true, 'amount_cents' => 900, 'purpose' => 'Snacks']);
        $this->claims->approve($waived, $this->treasurer);
        $this->assertSame('donated', $waived->refresh()->status);
        $this->assertSame(0, $this->balance('open_expenses'));
        $this->assertSame(1, OutgoingMail::query()->where('subject', 'Thank you for your donation')->where('html', 'like', '%Snacks%')->count());
    }

    public function test_an_advance_is_handed_out_settled_with_receipts_and_the_rest_comes_back(): void
    {
        $advances = app(Advances::class);
        $advance = $advances->handOut($this->kim, 30000, Account::system('cash'), now()->subDays(10), 'Camp cash', $this->treasurer);
        $this->assertSame(30000, $this->balance('advances'));
        $this->assertSame(-30000, $this->balance('cash'));

        $food = $this->claim(['amount_cents' => 18450, 'purpose' => 'Food', 'advance_id' => $advance->id, 'account_id' => $this->account('Food')->id]);
        $bus = $this->claim(['amount_cents' => 4300, 'purpose' => 'Bus tickets', 'advance_id' => $advance->id, 'account_id' => $this->account('Travel')->id]);
        try {
            $advances->settle($advance, 7250, Account::system('cash'), now());
            $this->fail('settled with undecided receipts');
        } catch (AccountingException) {
        }
        $this->claims->approve($food, $this->treasurer);
        $this->claims->approve($bus, $this->treasurer);
        $this->assertSame('settled', $food->refresh()->status);
        $this->assertSame(0, $this->balance('open_expenses'), 'receipts against an advance are not owed to the person');
        $this->assertSame(7250, $this->balance('advances'));
        $this->assertSame(7250, $advance->refresh()->openCents());

        try {
            $advances->settle($advance, 5000, Account::system('cash'), now());
            $this->fail('settled with a part of the rest');
        } catch (AccountingException $e) {
            $this->assertStringContainsString('72,50', $e->getMessage());
        }
        $advances->settle($advance, 7250, Account::system('cash'), now(), $this->treasurer);
        $this->assertTrue($advance->refresh()->isSettled());
        $this->assertSame(0, $this->balance('advances'));
        $this->assertSame(-22750, $this->balance('cash'), '300.00 out, 72.50 back');
        $this->assertSame(0, $advance->openCents());
        $this->assertSame(1, JournalEntry::query()->where('kind', 'advance_return')->count());
    }

    public function test_receipts_above_the_advance_become_an_open_expense_to_pay_out(): void
    {
        $advances = app(Advances::class);
        $advance = $advances->handOut($this->kim, 10000, Account::system('bank'), now()->subDays(10));
        $claim = $this->claim(['amount_cents' => 12500, 'purpose' => 'Food', 'advance_id' => $advance->id]);
        $this->claims->approve($claim, $this->treasurer);
        $this->assertSame(-2500, $advance->refresh()->openCents());
        $advances->settle($advance, 0, null, now());

        $rest = ExpenseClaim::query()->where('advance_rest', true)->sole();
        $this->assertSame(['approved', 2500, $this->kim->id], [$rest->status, $rest->amount_cents, $rest->person_id]);
        $this->assertSame(0, $this->balance('advances'));
        $this->assertSame(-2500, $this->balance('open_expenses'));
        $this->assertSame(0, $advance->refresh()->openCents());
        $this->claims->pay([$rest], Account::system('cash'), now());
        $this->assertSame(0, $this->balance('open_expenses'));
        $this->assertSame(-10000 - 2500, $this->balance('bank') + $this->balance('cash'));
    }

    public function test_flat_rates_from_the_setting_need_no_receipt_and_are_claimed_once_per_event(): void
    {
        Settings::set('expense_flat_rates', "Travel allowance per event: 90\nMileage: 0,42");
        $this->assertSame([['name' => 'Travel allowance per event', 'cents' => 9000], ['name' => 'Mileage', 'cents' => 42]], ExpenseClaims::flatRates());
        $event = Event::query()->create(['title' => 'Camp', 'starts_at' => now()->subDays(20), 'ends_at' => now()->subDays(13), 'status' => 'open']);
        $claim = $this->claim(['flat_rate' => 'Travel allowance per event', 'amount_cents' => null, 'purpose' => '', 'event_id' => $event->id]);
        $this->assertSame([9000, 'Travel allowance per event', true], [$claim->amount_cents, $claim->purpose, $claim->flat]);
        $miles = $this->claim(['flat_rate' => 'Mileage', 'quantity' => '120', 'purpose' => 'Vienna – camp', 'event_id' => $event->id]);
        $this->assertSame(5040, $miles->amount_cents);
        $this->assertSame('Mileage (120 × 0,42 €): Vienna – camp', $miles->purpose);
        $this->expectException(AccountingException::class);
        $this->claim(['flat_rate' => 'Travel allowance per event', 'event_id' => $event->id]);
    }

    /** The table "Bookings, step by step" of docs/admin/expenses.md. */
    public function test_the_walkthrough_of_the_documentation(): void
    {
        $camp = CostCenter::query()->create(['name' => 'Summer camp', 'carries' => true]);
        $robin = $this->person('Robin', 'Rowe', 'robin@example.org');
        $sam = $this->person('Sam', 'Stone', 'sam@example.org');
        $this->claims->setIban($this->kim, 'AT483200000012345864');
        $kims = $this->claim(['cost_center_id' => $camp->id, 'account_id' => $this->account('Material')->id]);
        $this->claims->approve($kims, $this->treasurer);
        $this->claims->pay([$kims], Account::system('bank'), now());
        $robins = $this->claim(['amount_cents' => 1830, 'purpose' => 'Postage', 'cost_center_id' => $camp->id, 'account_id' => $this->account('Postage')->id], $robin);
        $this->claims->approve($robins, $this->treasurer);
        $this->claims->donate($robins, $this->treasurer);
        $advance = app(Advances::class)->handOut($sam, 30000, Account::system('cash'), now()->subDays(5), 'Camp shopping');
        foreach ([[18450, 'Food', 'Food'], [4300, 'Bus tickets', 'Travel']] as [$cents, $purpose, $account]) {
            $receipt = $this->claim(['amount_cents' => $cents, 'purpose' => $purpose, 'advance_id' => $advance->id, 'cost_center_id' => $camp->id, 'account_id' => $this->account($account)->id], $sam);
            $this->claims->approve($receipt, $this->treasurer);
        }
        app(Advances::class)->settle($advance, 7250, Account::system('cash'), now());

        $this->assertSame([0, 0, -6170, -22750], [$this->balance('open_expenses'), $this->balance('advances'), $this->balance('bank'), $this->balance('cash')]);
        $this->assertSame(-28920, app(Balances::class)->results(Period::query()->sole())[$camp->id]);
        $this->assertSame(8, JournalEntry::query()->count());
    }

    public function test_the_iban_is_stored_encrypted_checked_and_never_in_the_audit_log(): void
    {
        $this->claims->setIban($this->kim, 'at48 3200 0000 1234 5864');
        $this->assertSame('AT483200000012345864', $this->kim->refresh()->payout_iban);
        $raw = (string) DB::table('people')->where('id', $this->kim->id)->value('payout_iban');
        $this->assertStringNotContainsString('3200', $raw);
        $this->assertSame('AT483200000012345864', decrypt($raw, false));
        $this->assertArrayNotHasKey('payout_iban', $this->kim->toArray());
        $this->assertStringNotContainsString('AT48', (string) AuditEvent::query()->get()->toJson());
        try {
            $this->claims->setIban($this->kim, 'AT48 3200 0000 1234 5865');
            $this->fail('wrong check digits');
        } catch (AccountingException) {
        }
        $this->claims->setIban($this->kim, '');
        $this->assertNull($this->kim->refresh()->payout_iban);
    }
}
