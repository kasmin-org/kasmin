<?php

namespace Tests\Feature\People;

use App\Models\Household;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Demo\DemoData;
use App\Support\Install\Defaults;
use App\Support\People\Overview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\Middleware\AuthenticateSession;
use Tests\TestCase;

/**
 * Person page and family page show everything in one place, each block only with the right for it (AD20, AD22).
 */
class OverviewTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        auth()->forgetUser();
        $person = Person::factory()->create();
        $person->roleAssignments()->create(['role_id' => Role::query()->where('key', $role)->value('id')]);

        return User::query()->create(['name' => $role, 'email' => $role.'@example.org', 'password' => 'x', 'person_id' => $person->id]);
    }

    public function test_blocks_follow_the_rights_of_the_viewer(): void
    {
        app(Defaults::class)->install('en');
        app()->setLocale('en');
        app(DemoData::class)->generate('en', 6, 2);
        $household = Household::query()->whereHas('children')->firstOrFail();
        $child = $household->children()->whereHas('participations')->firstOrFail();
        $admin = $this->user('administrator');
        $treasurer = $this->user('treasurer');
        $safety = $this->user('safety_officer');

        $forAdmin = app(Overview::class)->person($child, $admin);
        $this->assertNotSame([], $forAdmin['guardians']);
        $this->assertNotSame([], $forAdmin['events']);
        $this->assertIsArray($forAdmin['invoices']);
        $this->assertIsArray($forAdmin['consents']);
        $this->assertNull(app(Overview::class)->person($child, $safety)['invoices'], 'no finance without the right');
        $this->assertNull(app(Overview::class)->household($household, $treasurer)['emergency'], 'emergency contacts only with the safety right');
        $this->assertIsArray(app(Overview::class)->household($household, $safety)['emergency']);
        $this->assertIsArray(app(Overview::class)->household($household, $treasurer)['invoices']);

        $this->withoutMiddleware(AuthenticateSession::class);
        $this->actingAs($admin)->withSession(['two_factor_passed' => true])->get('/admin/people/'.$child->id)->assertOk()->assertSee('data-person-overview', false)->assertSee($forAdmin['guardians'][0]['name']);
        $this->actingAs($admin)->withSession(['two_factor_passed' => true])->get('/admin/households/'.$household->id.'/edit')->assertOk()->assertSee('data-household-overview', false)->assertSee('Invoices go to');
    }
}
