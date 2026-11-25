<?php

namespace Tests\Feature\People;

use App\Filament\Resources\People\Pages\EditPerson;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Install\Defaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The address a person logs in with cannot be removed in the person form (it would lock the person out).
 */
class LoginAddressTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_login_address_stays_and_the_settings_page_has_a_search(): void
    {
        app(Defaults::class)->install('en');
        app()->setLocale('en');
        $admin = Person::factory()->create();
        $admin->roleAssignments()->create(['role_id' => Role::query()->where('key', 'administrator')->value('id')]);
        $this->actingAs(User::query()->create(['name' => 'Admin', 'email' => 'admin@example.org', 'password' => 'x', 'person_id' => $admin->id]));

        $person = Person::factory()->create(['locale' => 'en']);
        $person->contactPoints()->create(['type' => 'email', 'value' => 'kim@example.org', 'is_primary' => true]);
        User::query()->create(['name' => 'Kim', 'email' => 'kim@example.org', 'password' => 'x', 'person_id' => $person->id]);

        Livewire::test(EditPerson::class, ['record' => $person->getRouteKey()])
            ->set('data.contactPoints', [])
            ->call('save')
            ->assertHasFormErrors(['contactPoints']);
        $this->assertSame(1, $person->contactPoints()->count());

        $this->withSession(['two_factor_passed' => true])->get('/admin/settings/organisation')->assertOk()->assertSee('data-settings-search', false);
    }
}
