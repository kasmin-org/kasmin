<?php

namespace Tests\Feature\Reports;

use App\Models\AuditEvent;
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

    private function user(string $role): User
    {
        $person = Person::factory()->create();
        $person->roleAssignments()->create(['role_id' => Role::query()->where('key', $role)->value('id')]);

        return User::query()->create(['name' => $role, 'email' => $role.'@example.org', 'password' => 'x', 'person_id' => $person->id]);
    }

    public function test_the_members_of_the_age_groups_are_a_list_with_the_chosen_fields(): void
    {
        Person::query()->orderBy('id')->firstOrFail()->update(['first_name' => 'Kim', 'last_name' => 'Ash', 'gender' => 'female']);
        $stats = app(Statistics::class);
        $all = $stats->ageGroupList($this->event, 3);
        $this->assertSame(['Group', 'First name', 'Last name', 'Age at the start', 'Date of birth', 'Gender', 'Role'], $all->headers);
        $this->assertCount(10, $all->rows);
        $this->assertSame(['Group 1', 'Group 1', 'Group 1', 'Group 1', 'Group 2', 'Group 2', 'Group 2', 'Group 3', 'Group 3', 'Group 3'], array_column($all->rows, 0));
        $this->assertContains(['Group 1', 'Kim', 'Ash', '8', '01/01/2019', 'female', 'Child'], $all->rows);
        $this->assertFalse($all->confidential);

        $some = $stats->ageGroupList($this->event, 2, [], ['birth_date', 'last_name', 'shoe_size']);
        $this->assertSame(['Last name', 'Date of birth'], $some->headers, 'the order of the list, unknown fields ignored');
        $this->assertCount(2, $some->rows[0]);
        $this->assertSame([], $stats->ageGroupList($this->event, 3, ['staff'])->rows);
    }

    public function test_the_export_is_offered_on_the_page_and_downloads_excel_csv_and_pdf(): void
    {
        $this->withoutMiddleware(AuthenticateSession::class);
        $this->actingAs($this->user('board'))->withSession(['two_factor_passed' => true]);
        $this->get('/admin/reports')->assertOk()->assertSee('data-age-group-export', false)->assertSee('Fields per person')->assertSee('Date of birth')
            ->assertSee('data-export-format="xlsx"', false)->assertSee('data-export-format="csv"', false)->assertSee('data-export-format="pdf"', false)
            // The explanation of "returning" is a note shown on hover and focus, neither a link nor a button.
            ->assertSee('data-hint="returning"', false)->assertSee('in any year, not only the year before');

        $url = fn (string $format, array $query = []) => '/admin/reports/events/'.$this->event->id.'/age-groups.'.$format.'?'.http_build_query($query + ['groups' => 3]);
        $csv = $this->get($url('csv', ['fields' => ['group', 'last_name', 'age']]))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('Group', (string) $csv->getContent());
        $this->assertStringContainsString('Age at the start', (string) $csv->getContent());
        $this->assertStringNotContainsString('Date of birth', (string) $csv->getContent());
        $this->assertSame(11, count(array_filter(explode("\n", trim((string) $csv->getContent())))));
        $this->assertStringStartsWith('PK', (string) $this->get($url('xlsx'))->assertOk()->getContent());
        $this->assertStringStartsWith('%PDF', (string) $this->get($url('pdf'))->assertOk()->getContent());
        $this->get($url('docx'))->assertNotFound();
        $this->get($url('csv', ['fields' => ['health']]))->assertStatus(302); // not a field that can be exported
        $logged = AuditEvent::query()->where('action', 'statistics.age_groups_exported')->orderBy('id')->get();
        $this->assertCount(3, $logged);
        $this->assertSame(['csv', 10], [$logged[0]->meta['format'] ?? null, $logged[0]->meta['rows'] ?? null]);

        // Events without people: no names leave the system.
        auth()->forgetUser();
        $this->actingAs($this->user('auditor'))->withSession(['two_factor_passed' => true]);
        $this->get($url('csv'))->assertForbidden();
        $this->get('/admin/reports')->assertOk()->assertDontSee('data-age-group-export', false);
    }
}
