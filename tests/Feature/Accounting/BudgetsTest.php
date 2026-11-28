<?php

namespace Tests\Feature\Accounting;

use App\Filament\Resources\Periods\Pages\ManagePeriods;
use App\Models\Account;
use App\Models\ApiToken;
use App\Models\Budget;
use App\Models\CostCenter;
use App\Models\Period;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Accounting\Budgets;
use App\Support\Accounting\Closing;
use App\Support\Accounting\Journal;
use App\Support\Accounting\Money;
use App\Support\Accounting\Periods;
use App\Support\Accounting\Reports;
use App\Support\Install\Defaults;
use App\Support\Settings;
use Filament\Http\Middleware\AuthenticateSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** The budget of a period: the planned result per cost centre, compared in the cost centre report. */
class BudgetsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        app(Defaults::class)->install('en');
        app()->setLocale('en');
        Settings::set('use_cost_centers', '1');
        $this->withoutMiddleware(AuthenticateSession::class);
        $person = Person::factory()->create(['locale' => 'en']);
        $person->roleAssignments()->create(['role_id' => Role::query()->where('key', 'administrator')->value('id')]);
        $this->user = User::query()->create(['name' => 'Admin', 'email' => 'admin@example.org', 'password' => 'x', 'person_id' => $person->id]);
    }

    public function test_the_budget_is_entered_per_cost_centre_and_compared_in_the_report(): void
    {
        $this->actingAs($this->user);
        $this->withSession(['two_factor_passed' => true]);
        $camp = CostCenter::query()->create(['name' => 'Camp', 'carries' => true]);
        $period = app(Periods::class)->create('2027', '2027-01-01', '2027-12-31');
        $general = CostCenter::default();

        Livewire::test(ManagePeriods::class)->callTableAction('budget', $period, ['c'.$camp->id => '1500.00', 'c'.$general->id => '-300.50'])->assertHasNoTableActionErrors();
        $this->assertEquals([$camp->id => 150000, $general->id => -30050], app(Budgets::class)->of($period));

        // Income of 2,000.00 on the camp: result 2,000.00, budget 1,500.00, difference +500.00.
        app(Journal::class)->post(['date' => '2027-07-01', 'text' => 'Fees'], [
            ['account' => Account::system('bank')->id, 'debit' => 200000],
            ['account' => Account::system('event_income')->id, 'credit' => 200000, 'cost_center' => $camp->id],
        ]);
        $row = collect(app(Reports::class)->costCenters($period)->rows)->first(fn (array $row) => $row[0] === 'Camp');
        $this->assertSame(['Camp', Money::format(200000), Money::format(0), '', Money::format(200000), Money::format(150000), Money::format(50000)], $row);

        // Empty removes the budget; a closed period can still be planned (a budget books nothing).
        app(Closing::class)->close($period);
        Livewire::test(ManagePeriods::class)->callTableAction('budget', $period->refresh(), ['c'.$camp->id => '', 'c'.$general->id => '-300.50'])->assertHasNoTableActionErrors();
        $this->assertSame([$general->id => -30050], app(Budgets::class)->of($period));
    }

    public function test_budgets_via_rest(): void
    {
        $token = ApiToken::issue($this->user, 'test', ['finance:read', 'finance:write'])['plain'];
        $read = ApiToken::issue($this->user, 'read', ['finance:read'])['plain'];
        $camp = CostCenter::query()->create(['name' => 'Camp', 'carries' => true]);
        app(Periods::class)->create('2027', '2027-01-01', '2027-12-31');

        $this->withToken($read)->putJson('/api/v1/accounting/periods/2027/budgets', ['budgets' => [['cost_center' => 'Camp', 'amount' => 10]]])->assertForbidden();
        $this->withToken($token)->putJson('/api/v1/accounting/periods/2027/budgets', ['budgets' => [['cost_center' => 'Nowhere', 'amount' => 10]]])->assertUnprocessable();
        $this->withToken($token)->putJson('/api/v1/accounting/periods/2027/budgets', ['budgets' => [['cost_center' => 'Camp', 'amount' => 1500.5], ['cost_center' => CostCenter::default()->id, 'amount' => -300]]])
            ->assertOk()->assertJsonPath('data.0.cost_center.name', 'Camp')->assertJsonPath('data.0.amount', 1500.5);
        $this->withToken($read)->getJson('/api/v1/accounting/periods/2027/budgets')->assertOk()->assertJsonCount(2, 'data');

        // Only cost centres active in the period, as in the admin interface.
        $old = CostCenter::query()->create(['name' => 'Old project', 'carries' => true]);
        Period::query()->where('name', '2027')->firstOrFail()->costCenters()->detach($old->id);
        $this->withToken($token)->putJson('/api/v1/accounting/periods/2027/budgets', ['budgets' => [['cost_center' => 'Old project', 'amount' => 10]]])
            ->assertUnprocessable()->assertJsonValidationErrors('budgets.0.cost_center');

        // Amount 0 removes one, the others stay.
        $this->withToken($token)->putJson('/api/v1/accounting/periods/2027/budgets', ['budgets' => [['cost_center' => $camp->id, 'amount' => 0]]])->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame(1, Budget::query()->count());
    }
}
