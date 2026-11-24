<?php

namespace Tests\Feature\Reports;

use App\Filament\Resources\People\Pages\ViewPerson;
use App\Models\AuditEvent;
use App\Models\Household;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Install\Defaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ViewAsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sees_the_portal_of_a_guardian_read_only(): void
    {
        app(Defaults::class)->install('en');
        app()->setLocale('en');
        $guardian = Person::factory()->create(['first_name' => 'Robin', 'last_name' => 'Ash']);
        $child = Person::factory()->create(['first_name' => 'Kim', 'last_name' => 'Ash', 'birth_date' => now()->subYears(9)]);
        $child->guardians()->attach($guardian->id);
        $household = Household::query()->create(['name' => 'Ash']);
        $household->members()->attach($guardian->id, ['role' => 'guardian']);
        $household->members()->attach($child->id, ['role' => 'child']);
        $adminPerson = Person::factory()->create();
        $adminPerson->roleAssignments()->create(['role_id' => Role::query()->where('key', 'administrator')->value('id')]);
        $admin = User::query()->create(['name' => 'Admin', 'email' => 'admin@example.org', 'password' => 'x', 'person_id' => $adminPerson->id]);

        $this->actingAs($admin)->withSession(['two_factor_passed' => true]);
        Livewire::test(ViewPerson::class, ['record' => $guardian->id])->callAction('view_as')->assertRedirect(route('portal'));
        $this->assertTrue(AuditEvent::query()->where('action', 'portal.view_as_started')->where('subject_id', $guardian->id)->exists());

        $this->withSession(['two_factor_passed' => true, 'portal_view_as' => $guardian->id]);
        $this->get('/portal')->assertOk()->assertSee('Preview as Robin Ash')->assertSee('Kim Ash');
        $this->get('/portal/children/'.$child->public_id)->assertOk()->assertSee('Kim Ash');
        $this->post('/portal/children', ['first_name' => 'New', 'last_name' => 'Child', 'birth_date' => '2016-01-01'])->assertRedirect();
        $this->assertFalse(Person::query()->where('first_name', 'New')->exists(), 'no changes in the preview');

        $this->post('/portal/view-as/end')->assertRedirect('/admin/people/'.$guardian->id);
        $this->get('/portal')->assertDontSee('Preview as');
    }

    public function test_non_administrators_cannot_use_a_preview_session(): void
    {
        $other = Person::factory()->create(['first_name' => 'Secret']);
        $user = User::query()->create(['name' => 'G', 'email' => 'g@example.org', 'password' => 'x', 'person_id' => Person::factory()->create(['first_name' => 'Me'])->id]);
        $this->actingAs($user)->withSession(['portal_view_as' => $other->id])->get('/portal')->assertOk()->assertDontSee('Secret');
    }
}
