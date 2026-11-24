<?php

namespace Tests\Feature\Reports;

use App\Models\Invoice;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Demo\DemoData;
use App\Support\Install\Defaults;
use App\Support\Reports\DashboardData;
use Filament\Http\Middleware\AuthenticateSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_shows_series_blocks_and_hides_finance_without_permission(): void
    {
        app(Defaults::class)->install('en');
        app()->setLocale('en');
        app(DemoData::class)->generate('en', 6, 2);
        $blocks = app(DashboardData::class)->series(true);
        $camp = collect($blocks)->firstWhere('series', 'Summer camp');
        $this->assertNotNull($camp);
        $this->assertGreaterThan(0, $camp['registrations']);
        $this->assertCount(min(3, $camp['registrations']), $camp['latest']);
        $this->assertSame($camp['finance']['paid']['count'] + $camp['finance']['open']['count'] + $camp['finance']['overdue']['count'], Invoice::query()->where('event_id', $camp['id'])->count());

        $this->withoutMiddleware(AuthenticateSession::class);
        $make = function (string $role): User {
            $person = Person::factory()->create();
            $person->roleAssignments()->create(['role_id' => Role::query()->where('key', $role)->value('id')]);

            return User::query()->create(['name' => $role, 'email' => $role.'@example.org', 'password' => 'x', 'person_id' => $person->id]);
        };
        $this->actingAs($make('treasurer'))->withSession(['two_factor_passed' => true])->get('/admin')
            ->assertOk()->assertSee('Summer camp')->assertSee('Latest registrations')->assertSee('Invoices')->assertSee('To do');
        $this->actingAs($make('camp_leader'))->withSession(['two_factor_passed' => true])->get('/admin')
            ->assertOk()->assertSee('Summer camp')->assertDontSee('Last bank import');
    }
}
