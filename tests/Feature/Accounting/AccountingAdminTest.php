<?php

namespace Tests\Feature\Accounting;

use App\Filament\Pages\AccountingReportsPage;
use App\Filament\Pages\GlobalSettingsPage;
use App\Filament\Resources\CostCenters\Pages\ManageCostCenters;
use App\Filament\Resources\JournalEntries\Pages\ManageJournalEntries;
use App\Filament\Resources\Periods\Pages\ManagePeriods;
use App\Models\Account;
use App\Models\CostCenter;
use App\Models\Fund;
use App\Models\JournalEntry;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Accounting\CostCenters;
use App\Support\Accounting\Journal;
use App\Support\Accounting\Periods;
use App\Support\Install\Defaults;
use App\Support\Settings;
use Filament\Http\Middleware\AuthenticateSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** The admin pages of the accounting (E1): booking, periods, closing, cost centres, switches, reports. */
class AccountingAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(Defaults::class)->install('en');
        app()->setLocale('en');
        $this->withoutMiddleware(AuthenticateSession::class);
        $person = Person::factory()->create(['locale' => 'en']);
        $person->roleAssignments()->create(['role_id' => Role::query()->where('key', 'administrator')->value('id')]);
        $this->actingAs(User::query()->create(['name' => 'Admin', 'email' => 'admin@example.org', 'password' => 'x', 'person_id' => $person->id]));
        $this->withSession(['two_factor_passed' => true]);
    }

    public function test_every_page_opens(): void
    {
        app(Periods::class)->create('2027', '2027-01-01', '2027-12-31');
        Settings::set('use_cost_centers', '1');
        Settings::set('use_funds', '1');
        foreach (['/admin/journal-entries', '/admin/accounts', '/admin/cost-centers', '/admin/funds', '/admin/periods', '/admin/accounting/reports', '/admin/accounting/money'] as $page) {
            $this->get($page)->assertOk();
        }
        foreach (array_keys(__('accounting.reports.kinds')) as $report) {
            Livewire::test(AccountingReportsPage::class)->set('report', $report)->set('account', Account::system('bank')->id)->assertOk();
        }
    }

    public function test_a_split_booking_typed_in_and_reversed(): void
    {
        app(Periods::class)->create('2027', '2027-01-01', '2027-12-31');
        Livewire::test(ManageJournalEntries::class)->callAction('book', [
            'date' => '2027-03-01', 'text' => 'Shop', 'lines' => [
                ['account' => Account::query()->where('name', 'Food')->value('id'), 'debit' => '30.00', 'credit' => null],
                ['account' => Account::query()->where('name', 'Material')->value('id'), 'debit' => '12.50', 'credit' => null],
                ['account' => Account::system('cash')->id, 'debit' => null, 'credit' => '42.50'],
            ],
        ])->assertHasNoActionErrors();
        $entry = JournalEntry::query()->sole();
        $this->assertSame(4250, $entry->totalCents());
        Livewire::test(ManageJournalEntries::class)->callTableAction('reverse', $entry);
        $this->assertSame(2, JournalEntry::query()->count());
    }

    public function test_closing_from_the_page_and_reopening(): void
    {
        $period = app(Periods::class)->create('2027', '2027-01-01', '2027-12-31');
        app(Journal::class)->post(['date' => '2027-05-01', 'text' => 'Donation'], [['account' => 'bank', 'debit' => 10000], ['account' => 'donations', 'credit' => 10000]]);
        Livewire::test(ManagePeriods::class)->callTableAction('close', $period);
        $this->assertTrue($period->refresh()->isClosed());
        $this->assertSame(10000, app(\App\Support\Accounting\Balances::class)->fund(Fund::default(), '2027-12-31'));
        Livewire::test(ManagePeriods::class)->callTableAction('reopen', $period, ['reason' => 'Late receipt']);
        $this->assertFalse($period->refresh()->isClosed());
    }

    public function test_deleting_a_cost_centre_needs_the_sentence_and_only_manual_bookings(): void
    {
        $period = app(Periods::class)->create('2027', '2027-01-01', '2027-12-31');
        $camp = CostCenter::query()->create(['name' => 'Camp']);
        $period->costCenters()->attach($camp->id);
        app(Journal::class)->post(['date' => '2027-05-01', 'text' => 'Gift'], [['account' => 'bank', 'debit' => 500], ['account' => 'donations', 'credit' => 500, 'cost_center' => $camp]]);
        Livewire::test(ManageCostCenters::class)->callTableAction('delete', $camp, ['mode' => 'delete', 'typed' => 'wrong']);
        $this->assertTrue(CostCenter::query()->whereKey($camp->id)->exists());
        Livewire::test(ManageCostCenters::class)->callTableAction('delete', $camp, ['mode' => 'delete', 'typed' => CostCenters::confirmation(1)]);
        $this->assertFalse(CostCenter::query()->whereKey($camp->id)->exists());
        $this->assertSame(0, JournalEntry::query()->count());
    }

    public function test_switches_follow_the_rules(): void
    {
        Livewire::test(GlobalSettingsPage::class)->fillForm(['use_cost_centers' => false, 'use_funds' => true])->call('save')->assertHasFormErrors(['use_funds']);
        Livewire::test(GlobalSettingsPage::class)->fillForm(['use_cost_centers' => true, 'use_funds' => true])->call('save')->assertHasNoFormErrors();
        $this->assertTrue(Settings::bool('use_funds'));
        Fund::query()->create(['name' => 'Tents']);
        Livewire::test(GlobalSettingsPage::class)->fillForm(['use_cost_centers' => true, 'use_funds' => false])->call('save')->assertHasFormErrors(['use_funds']);
        CostCenter::query()->create(['name' => 'Camp']);
        Livewire::test(GlobalSettingsPage::class)->fillForm(['use_cost_centers' => false, 'use_funds' => true])->call('save')->assertHasFormErrors();
    }
}
