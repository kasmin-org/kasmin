<?php

namespace Tests\Feature\Accounting;

use App\Models\Account;
use App\Models\ApiToken;
use App\Models\ExpenseClaim;
use App\Models\Fund;
use App\Models\JournalEntry;
use App\Models\Period;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Accounting\AccountingException;
use App\Support\Accounting\Closing;
use App\Support\Accounting\ExpenseClaims;
use App\Support\Accounting\Journal;
use App\Support\Accounting\Money;
use App\Support\Accounting\Periods;
use App\Support\Accounting\Reports;
use App\Support\Install\Defaults;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Rules that keep the books right: periods without gaps in the bookings, numbers given once, no double payouts. */
class SafeguardsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        app(Defaults::class)->install('en');
        app()->setLocale('en');
        $person = Person::factory()->create(['locale' => 'en']);
        $person->roleAssignments()->create(['role_id' => Role::query()->where('key', 'administrator')->value('id')]);
        $this->user = User::query()->create(['name' => 'Owner', 'email' => 'owner@example.org', 'password' => 'x', 'person_id' => $person->id]);
    }

    /** @return array<string, int> */
    private function bank(int $cents, string $date, string $other = 'event_income'): array
    {
        return ['entry' => app(Journal::class)->post(['date' => $date, 'text' => 'Fees'], [
            ['account' => Account::system('bank')->id, 'debit' => $cents], ['account' => Account::system($other)->id, 'credit' => $cents],
        ])->number];
    }

    public function test_no_booking_is_left_without_a_period_and_none_comes_before_a_closed_one(): void
    {
        $periods = app(Periods::class);
        $year = $periods->create('2027', '2027-01-01', '2027-12-31');
        $this->bank(1000, '2027-01-10');
        $this->bank(1000, '2027-12-20');
        foreach ([['2027-02-01', '2027-12-31'], ['2027-01-01', '2027-11-30']] as [$from, $to]) {
            try {
                $periods->move($year, $from, $to);
                $this->fail('a booking would be in no period');
            } catch (AccountingException $e) {
                $this->assertSame(__('accounting.errors.period_orphans'), $e->getMessage());
            }
        }
        $periods->move($year, '2027-01-05', '2027-12-31'); // still around every booking

        app(Closing::class)->close($year->refresh());
        $this->expectExceptionMessage(__('accounting.errors.before_closed', ['period' => '2027']));
        $periods->create('2026', '2026-01-01', '2027-01-04');
    }

    public function test_numbers_are_never_given_twice_also_after_reopening(): void
    {
        $year = app(Periods::class)->create('2027', '2027-01-01', '2027-12-31');
        $this->bank(5000, '2027-03-01');
        app(Closing::class)->close($year);
        $highest = (int) JournalEntry::query()->where('kind', 'closing')->max('number');
        $this->assertGreaterThan(0, $highest);
        app(Periods::class)->reopen($year->refresh(), 'Late receipt');
        $next = $this->bank(100, '2027-03-02')['entry'];
        $this->assertGreaterThan($highest, $next);
    }

    public function test_a_typed_booking_is_reversed_also_when_its_account_was_switched_off_since(): void
    {
        app(Periods::class)->create('2027', '2027-01-01', '2027-12-31');
        $account = Account::query()->create(['name' => 'Old bank', 'type' => 'asset', 'money' => 'bank', 'active' => true]);
        $entry = app(Journal::class)->post(['date' => '2027-02-01', 'text' => 'Moved'], [
            ['account' => $account->id, 'debit' => 700], ['account' => Account::system('bank')->id, 'credit' => 700],
        ]);
        $account->update(['active' => false]);
        $reversal = app(Journal::class)->reverse($entry);
        $this->assertSame($entry->id, $reversal->reverses_id);
        $this->expectExceptionMessage(__('accounting.errors.reversed'));
        app(Journal::class)->reverse($entry->refresh());
    }

    public function test_the_balance_sheet_adds_up_with_an_own_equity_account(): void
    {
        app(Periods::class)->create('2027', '2027-01-01', '2027-12-31');
        $carried = Account::query()->create(['name' => 'Carried forward', 'type' => 'equity', 'active' => true]);
        app(Journal::class)->post(['date' => '2027-01-01', 'text' => 'Opening', 'kind' => 'opening'], [
            ['account' => Account::system('bank')->id, 'debit' => 100000], ['account' => $carried->id, 'credit' => 100000],
        ]);
        $rows = collect(app(Reports::class)->balanceSheet(Carbon::parse('2027-06-30'))->rows);
        $this->assertNotNull($rows->first(fn (array $row) => str_contains($row[0], 'Carried forward')));
        $this->assertSame(Money::format(0), $rows->last()[1] ?? null, 'the check row is 0');
    }

    public function test_a_claim_is_paid_once_also_when_two_people_pay_it_at_the_same_time(): void
    {
        app(Periods::class)->create('2027', '2026-10-01', '2027-09-30');
        $person = Person::factory()->create();
        $claims = app(ExpenseClaims::class);
        $claim = $claims->submit($person, ['date' => now()->subDay(), 'amount_cents' => 1990, 'purpose' => 'Glue']);
        $claims->approve($claim, $this->user);
        $stale = ExpenseClaim::query()->findOrFail($claim->id); // what a second browser still shows: approved
        $claims->pay([$claim], Account::system('cash'), today(), false, $this->user);
        try {
            $claims->pay([$stale], Account::system('cash'), today(), false, $this->user);
            $this->fail('paid twice');
        } catch (AccountingException) {
        }
        $this->assertSame(1, JournalEntry::query()->where('kind', 'expense_payout')->count());
    }

    public function test_rest_books_missing_documents_takes_a_person_on_a_line_and_keeps_funds_with_bookings(): void
    {
        Settings::set('use_cost_centers', '1');
        Settings::set('use_funds', '1');
        app(Periods::class)->create('2027', '2027-01-01', '2027-12-31');
        $token = ApiToken::issue($this->user, 'test', ['finance:read', 'finance:write'])['plain'];
        $this->withToken($token)->postJson('/api/v1/accounting/book-documents')->assertOk()->assertJsonPath('data.booked', 0)->assertJsonPath('data.failures', []);

        $family = Person::factory()->create();
        $this->withToken($token)->postJson('/api/v1/accounting/bookings', ['date' => '2027-01-01', 'text' => 'Opening: open invoice', 'lines' => [
            ['account' => Account::system('receivables')->id, 'debit' => 120, 'person' => $family->public_id],
            ['account' => Account::system('funds')->id, 'credit' => 120],
        ]])->assertCreated();
        $this->assertSame($family->id, JournalEntry::query()->latest('id')->firstOrFail()->lines->firstWhere('debit_cents', 12000)?->person_id);
        // An unknown person, or a person on an account that is not kept per person, is refused on the field.
        foreach ([[Account::system('receivables')->id, 'nobody'], [Account::system('bank')->id, $family->public_id]] as [$account, $ref]) {
            $this->withToken($token)->postJson('/api/v1/accounting/bookings', ['date' => '2027-01-02', 'text' => 'Wrong', 'lines' => [
                ['account' => $account, 'debit' => 1, 'person' => $ref], ['account' => Account::system('funds')->id, 'credit' => 1],
            ]])->assertUnprocessable()->assertJsonValidationErrors('lines.0.person');
        }

        $pot = Fund::query()->create(['name' => 'Tents']);
        foreach ([[Fund::default()->name, $pot->name], [$pot->name, Fund::default()->name]] as [$from, $to]) {
            $this->withToken($token)->postJson('/api/v1/accounting/funds/'.rawurlencode($from).'/transfers', ['to' => $to, 'amount' => 50, 'date' => '2027-02-01', 'text' => 'Board'])->assertSuccessful();
        }
        $this->withToken($token)->deleteJson('/api/v1/accounting/funds/'.$pot->id)->assertUnprocessable();
        $this->assertTrue(Fund::query()->whereKey($pot->id)->exists());
        $this->assertTrue(Period::query()->exists());
    }
}
