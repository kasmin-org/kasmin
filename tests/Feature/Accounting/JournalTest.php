<?php

namespace Tests\Feature\Accounting;

use App\Models\Account;
use App\Models\AllocationRule;
use App\Models\CostCenter;
use App\Models\Fund;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Period;
use App\Support\Accounting\AccountingException;
use App\Support\Accounting\Balances;
use App\Support\Accounting\Closing;
use App\Support\Accounting\Journal;
use App\Support\Accounting\Periods;
use App\Support\Install\Defaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The core of the accounting (E1): periods as date ranges, balanced bookings, closing into the funds. */
class JournalTest extends TestCase
{
    use RefreshDatabase;

    private Journal $journal;

    private Periods $periods;

    protected function setUp(): void
    {
        parent::setUp();
        app(Defaults::class)->install('en');
        app()->setLocale('en');
        $this->journal = app(Journal::class);
        $this->periods = app(Periods::class);
    }

    /** @param  array<string, int>  $lines  account key => +debit / −credit */
    private function book(string $date, array $lines, ?CostCenter $center = null): JournalEntry
    {
        $rows = [];
        foreach ($lines as $key => $amount) {
            $account = Account::query()->where('system_key', $key)->first() ?? Account::query()->where('name', $key)->firstOrFail();
            $rows[] = ['account' => $account, $amount > 0 ? 'debit' : 'credit' => abs($amount), 'cost_center' => $center];
        }

        return $this->journal->post(['date' => $date, 'text' => 'Test'], $rows);
    }

    public function test_the_standard_chart_cost_centre_and_fund_exist(): void
    {
        $this->assertSame('Bank', Account::system('bank')->name);
        $this->assertSame('General', CostCenter::default()->name);
        $this->assertSame('General fund', Fund::default()->name);
        $this->assertTrue(CostCenter::default()->carries);
    }

    public function test_bookings_must_balance_and_need_an_open_period(): void
    {
        try {
            $this->book('2027-01-10', ['bank' => 1000, 'donations' => -1000]);
            $this->fail('no period');
        } catch (AccountingException) {
        }
        $this->periods->create('2027', '2026-10-19', '2027-10-18');
        $entry = $this->book('2027-01-10', ['bank' => 1000, 'donations' => -1000]);
        $this->assertSame(1, $entry->number);
        $this->assertSame(CostCenter::default()->id, $entry->lines->firstWhere('account_id', Account::system('donations')->id)?->cost_center_id);
        $this->assertNull($entry->lines->firstWhere('account_id', Account::system('bank')->id)?->cost_center_id);
        $this->expectException(AccountingException::class);
        $this->book('2027-01-11', ['bank' => 1000, 'donations' => -900]);
    }

    public function test_numbers_run_over_the_years_and_reversals_mirror(): void
    {
        $this->periods->create('2027', '2027-01-01', '2027-12-31');
        $first = $this->book('2027-12-30', ['bank' => 500, 'donations' => -500]);
        // Typed-in bookings need an existing period; documents make the next one (but not years ahead).
        $this->assertNull(Period::of('2028-01-02'));
        $this->assertNotNull($this->periods->ensureFor('2028-01-02'));
        $this->assertSame('2028', Period::query()->whereDate('starts_on', '2028-01-01')->value('name'));
        $this->assertNull($this->periods->ensureFor('2035-01-01'));
        $second = $this->book('2028-01-02', ['bank' => 700, 'donations' => -700]);
        $this->assertSame([1, 2], [$first->number, $second->number]);
        $reversal = $this->journal->reverse($first);
        $this->assertSame($first->id, $reversal->reverses_id);
        $this->assertSame(0, app(Balances::class)->account(Account::system('bank'), '2027-12-31'));
        $this->expectException(AccountingException::class);
        $this->journal->reverse($first);
    }

    public function test_moving_the_end_of_a_period_moves_its_bookings_and_the_next_period(): void
    {
        $a = $this->periods->create('2026', '2025-10-19', '2026-10-18');
        $b = $this->periods->create('2027', '2026-10-19', '2027-10-18');
        $this->book('2026-10-10', ['bank' => 100, 'donations' => -100]);
        $this->assertSame(['moved' => 1], $this->periods->move($a, '2025-10-19', '2026-09-30'));
        $this->assertSame('2026-10-01', $b->refresh()->starts_on->toDateString());
        $this->assertTrue(Period::of('2026-10-10')?->is($b));
    }

    public function test_closing_allocates_and_carries_the_results_into_the_funds_over_years(): void
    {
        $period = $this->periods->create('2026', '2025-10-19', '2026-10-18');
        $camp = CostCenter::query()->create(['name' => 'Summer camp', 'carries' => true, 'fund_id' => Fund::query()->create(['name' => 'Summer camp fund'])->id]);
        $weekend = CostCenter::query()->create(['name' => 'Weekend', 'carries' => true, 'fund_id' => Fund::query()->create(['name' => 'Weekend fund'])->id]);
        $admin = CostCenter::default();
        $admin->update(['carries' => false]); // "General" is the administration here and is allocated
        $period->costCenters()->syncWithoutDetaching([$camp->id, $weekend->id]);
        AllocationRule::query()->create(['period_id' => $period->id, 'source_cost_center_id' => $admin->id, 'target_cost_center_id' => $camp->id, 'kind' => 'percent', 'value' => 10000]);
        AllocationRule::query()->create(['period_id' => $period->id, 'source_cost_center_id' => $admin->id, 'target_cost_center_id' => $weekend->id, 'kind' => 'percent', 'value' => 0]);

        $this->book('2026-06-01', ['bank' => 1664000, 'event_income' => -1664000], $camp);
        $this->book('2026-07-01', ['Accommodation' => 1462522, 'bank' => -1462522], $camp);
        $this->book('2026-09-01', ['bank' => 228000, 'event_income' => -228000], $weekend);
        $this->book('2026-09-02', ['Food' => 170907, 'bank' => -170907], $weekend);
        $this->book('2026-03-01', ['Software and website' => 61000, 'bank' => -61000], $admin);

        $preview = app(Closing::class)->preview($period);
        $this->assertSame([], $preview['errors']);
        $after = collect($preview['rows'])->mapWithKeys(fn ($r) => [$r['cost_center']->name => $r['after']])->all();
        // Camp +2,014.78 − 610 administration = 1,404.78; weekend +570.93; administration 0.
        $this->assertSame(['General' => 0, 'Summer camp' => 140478, 'Weekend' => 57093], $after);

        app(Closing::class)->close($period);
        $this->assertTrue($period->refresh()->isClosed());
        $this->assertSame(140478, app(Balances::class)->fund(Fund::query()->where('name', 'Summer camp fund')->sole(), '2026-10-18'));
        $this->assertSame(57093, app(Balances::class)->fund(Fund::query()->where('name', 'Weekend fund')->sole(), '2026-10-18'));
        $result = JournalLine::query()->whereHas('account', fn ($q) => $q->whereIn('type', ['income', 'expense']))->get();
        $this->assertSame(0, (int) $result->sum('debit_cents') - (int) $result->sum('credit_cents'), 'income and expenses start again at 0');

        // Locked; reopening removes the closing and opens later periods too.
        try {
            $this->book('2026-05-05', ['bank' => 1, 'donations' => -1], $camp);
            $this->fail('closed period');
        } catch (AccountingException) {
        }
        $next = $this->periods->create('2027', '2026-10-19', '2027-10-18');
        $this->assertSame([$admin->id, $camp->id, $weekend->id], $next->costCenters()->orderBy('cost_centers.id')->pluck('cost_centers.id')->all());
        $this->assertSame(2, $next->allocationRules()->count(), 'the key is taken over');
        $this->assertCount(1, $this->periods->reopen($period, 'correction'));
        $this->assertSame(0, app(Balances::class)->fund(Fund::query()->where('name', 'Weekend fund')->sole(), '2026-10-18'));
    }
}
