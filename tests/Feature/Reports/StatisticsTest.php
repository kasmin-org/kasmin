<?php

namespace Tests\Feature\Reports;

use App\Models\Event;
use App\Models\Participation;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Demo\DemoData;
use App\Support\Install\Defaults;
use App\Support\Reports\Statistics;
use Filament\Http\Middleware\AuthenticateSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatisticsTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        app(Defaults::class)->install('en');
        app()->setLocale('en');
        $this->event = Event::query()->create(['title' => 'Camp', 'starts_at' => '2027-08-01', 'ends_at' => '2027-08-08', 'status' => 'open']);
        $this->event->roles()->create(['role_key' => 'child', 'capacity' => 50]);
        foreach ([8, 8, 9, 10, 10, 11, 12, 13, 13, 13] as $i => $age) {
            $p = Person::factory()->create(['birth_date' => '2027-01-'.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT)]);
            $p->forceFill(['birth_date' => now()->setDate(2027 - $age, 1, $i + 1)->toDateString()])->save();
            Participation::query()->create(['event_id' => $this->event->id, 'person_id' => $p->id, 'role_key' => 'child', 'status' => 'registered', 'registered_at' => now()]);
        }
    }

    public function test_age_distribution_and_equal_groups(): void
    {
        $stats = app(Statistics::class);
        $this->assertSame([8 => 2, 9 => 1, 10 => 2, 11 => 1, 12 => 1, 13 => 3], $stats->ageDistribution($this->event));
        $groups = $stats->ageGroups($this->event, 3);
        $this->assertSame([4, 3, 3], array_column($groups, 'size'));
        $this->assertSame([8, 10], [$groups[0]['min_age'], $groups[0]['max_age']]);
        $this->assertSame(13, $groups[2]['max_age']);
    }

    public function test_costs_years_and_page(): void
    {
        $this->event->costs()->create(['label' => 'House', 'category' => 'accommodation', 'amount_cents' => 300000]);
        $costs = app(Statistics::class)->costs($this->event);
        $this->assertSame([0, 300000, -300000, 10, 30000], [$costs['income'], $costs['costs'], $costs['result'], $costs['participants'], $costs['per_participant']]);

        app(DemoData::class)->generate('en', 4, 1);
        $this->assertNotEmpty(app(Statistics::class)->years());

        $this->withoutMiddleware(AuthenticateSession::class);
        $person = Person::factory()->create();
        $person->roleAssignments()->create(['role_id' => Role::query()->where('key', 'treasurer')->value('id')]);
        $user = User::query()->create(['name' => 't', 'email' => 't@example.org', 'password' => 'x', 'person_id' => $person->id]);
        $this->actingAs($user)->withSession(['two_factor_passed' => true])->get('/admin/reports')->assertOk()->assertSee('Age distribution')->assertSee('Costs and income');
    }
}
