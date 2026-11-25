<?php

namespace Tests\Feature\People;

use App\Filament\Resources\People\Pages\ListPeople;
use App\Filament\Support\PeopleBulkActions;
use App\Models\Group;
use App\Models\Mailing;
use App\Models\MailTemplate;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Install\Defaults;
use Filament\Actions\BulkAction;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Actions on selected people (AD12): groups and a mailing as a draft.
 */
class BulkActionsTest extends TestCase
{
    use RefreshDatabase;

    private function actAs(string $role): User
    {
        auth()->forgetUser();
        $person = Person::factory()->create();
        $person->roleAssignments()->create(['role_id' => Role::query()->where('key', $role)->value('id')]);
        $user = User::query()->create(['name' => $role, 'email' => $role.'@example.org', 'password' => 'x', 'person_id' => $person->id]);
        $this->actingAs($user);

        return $user;
    }

    public function test_selected_people_go_into_a_group_out_of_it_and_into_a_draft_mailing(): void
    {
        app(Defaults::class)->install('en');
        app()->setLocale('en');
        $people = Person::factory()->count(3)->create();
        $group = Group::query()->create(['name' => 'Kitchen team', 'kind' => 'manual']);
        $rules = Group::query()->create(['name' => 'By rules', 'kind' => 'dynamic']);
        $this->actAs('administrator');

        Livewire::test(ListPeople::class)->callTableBulkAction('add_to_group', $people, ['group_id' => $group->id])->assertHasNoTableBulkActionErrors();
        $this->assertSame(3, $group->people()->count());
        $this->assertSame(0, PeopleBulkActions::addToGroup($group->id, $people->modelKeys()), 'nobody twice');

        Livewire::test(ListPeople::class)->callTableBulkAction('remove_from_group', $people->take(1), ['group_id' => $group->id]);
        $this->assertSame(2, $group->people()->count());
        $this->assertSame(0, $people->firstOrFail()->groups()->count());

        $template = MailTemplate::query()->where('key', 'newsletter')->firstOrFail();
        Livewire::test(ListPeople::class)->callTableBulkAction('mailing', $people, ['name' => 'To three', 'mail_template_id' => $template->id, 'routing' => 'person']);
        $mailing = Mailing::query()->where('name', 'To three')->firstOrFail();
        $this->assertSame('draft', $mailing->status);
        $this->assertEqualsCanonicalizing($people->modelKeys(), $mailing->recipients['person_ids'] ?? []);

        $this->expectException(ModelNotFoundException::class);
        PeopleBulkActions::addToGroup($rules->id, $people->modelKeys());
    }

    public function test_roles_without_the_right_do_not_get_the_actions(): void
    {
        app(Defaults::class)->install('en');
        $this->actAs('auditor');
        $this->assertFalse(collect(PeopleBulkActions::make())->contains(fn (BulkAction $action) => $action->isVisible()));
    }
}
