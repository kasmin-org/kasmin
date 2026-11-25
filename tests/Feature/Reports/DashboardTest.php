<?php

namespace Tests\Feature\Reports;

use App\Models\Invoice;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Demo\DemoData;
use App\Support\Install\Defaults;
use App\Support\Reports\DashboardData;
use Filament\Facades\Filament;
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
        $this->assertIsInt($camp['open_places'], 'roles without a limit (team) do not make the places infinite');
        $this->assertCount(min(3, $camp['registrations']), $camp['latest']);
        $this->assertSame($camp['finance']['paid']['count'] + $camp['finance']['open']['count'] + $camp['finance']['overdue']['count'], Invoice::query()->where('event_id', $camp['id'])->count());

        $this->withoutMiddleware(AuthenticateSession::class);
        $make = function (string $role): User {
            auth()->forgetUser(); // roles are assigned by the system here
            $person = Person::factory()->create();
            $person->roleAssignments()->create(['role_id' => Role::query()->where('key', $role)->value('id')]);

            return User::query()->create(['name' => $role, 'email' => $role.'@example.org', 'password' => 'x', 'person_id' => $person->id]);
        };
        $this->actingAs($make('treasurer'))->withSession(['two_factor_passed' => true])->get('/admin')
            ->assertOk()->assertSee('Summer camp')->assertSee('Latest registrations')->assertSee('Invoices')->assertSee('To do');
        $leader = $make('camp_leader');
        $leader->person?->roleAssignments()->toBase()->update(['event_id' => $camp['id']]);
        $this->actingAs($leader)->withSession(['two_factor_passed' => true])->get('/admin')
            ->assertOk()->assertSee('Summer camp')->assertDontSee('Last bank import')->assertDontSee('To do');
    }

    public function test_todos_count_duplicates_bad_addresses_and_forms_to_review(): void
    {
        app(Defaults::class)->install('en');
        $before = app(DashboardData::class)->todos();
        $this->assertSame([0, 0, 0], [$before['duplicates'], $before['bad_addresses'], $before['submissions']]);

        $a = Person::factory()->create(['first_name' => 'Robin', 'last_name' => 'Ash', 'birth_date' => '2015-04-01']);
        Person::factory()->create(['first_name' => 'Robin', 'last_name' => 'Ash', 'birth_date' => '2015-04-01']);
        $a->contactPoints()->create(['type' => 'email', 'value' => 'robin@example.org', 'is_primary' => true])->forceFill(['bounced_at' => now()])->save();

        $todos = app(DashboardData::class)->todos();
        $this->assertSame([1, 1], [$todos['duplicates'], $todos['bad_addresses']]);
        foreach (array_keys($todos) as $key) {
            $this->assertNotSame('dashboard.todo.'.$key, __('dashboard.todo.'.$key), $key);
        }
    }

    public function test_global_search_finds_people_families_events_and_invoices(): void
    {
        app(Defaults::class)->install('en');
        app(DemoData::class)->generate('en', 3, 1);
        $person = Person::query()->whereHas('contactPoints')->firstOrFail();
        $invoice = Invoice::query()->whereNotNull('number')->firstOrFail();
        $admin = Person::factory()->create();
        $admin->roleAssignments()->create(['role_id' => Role::query()->where('key', 'administrator')->value('id')]);
        $this->actingAs(User::query()->create(['name' => 'a', 'email' => 'admin@example.org', 'password' => 'x', 'person_id' => $admin->id]));

        $search = fn (string $q) => collect(Filament::getPanel('admin')?->getGlobalSearchProvider()?->getResults($q)?->getCategories() ?? [])
            ->flatten(1)->map(fn ($r) => $r->title)->all();
        $this->assertContains($person->fullName(), $search($person->last_name));
        $this->assertContains($person->fullName(), $search((string) $person->contactPoints()->value('value')));
        $this->assertContains((string) $invoice->number, $search((string) $invoice->reference));
        $this->assertNotEmpty($search('Summer'));
    }
}
