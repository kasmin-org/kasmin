<?php

namespace Tests\Feature\Lists;

use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\People\Pages\ListPeople;
use App\Models\AuditEvent;
use App\Models\Group;
use App\Models\Person;
use App\Models\Role;
use App\Models\SavedView;
use App\Models\User;
use App\Support\Install\Defaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class ListToolsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2027-06-01 12:00'));
        app(Defaults::class)->install('en');
        app()->setLocale('en');
    }

    private function actAs(string $role, string $mail): User
    {
        $person = Person::factory()->create(['first_name' => 'Role', 'last_name' => 'Holder']);
        $person->roleAssignments()->create(['role_id' => Role::query()->where('key', $role)->value('id')]);
        $user = User::query()->create(['name' => $role, 'email' => $mail, 'password' => 'x', 'person_id' => $person->id]);
        $this->actingAs($user);

        return $user;
    }

    public function test_export_contains_the_filtered_rows_and_is_logged(): void
    {
        $this->actAs('administrator', 'a@example.org');
        Person::factory()->create(['first_name' => 'Ann', 'last_name' => 'Export', 'birth_date' => '2013-01-01']);
        Person::factory()->create(['first_name' => 'Ben', 'last_name' => 'Hidden', 'birth_date' => '1990-01-01']);
        foreach (['csv', 'xlsx', 'pdf'] as $format) {
            $component = Livewire::test(ListPeople::class)->filterTable('age', ['min' => 14, 'max' => 14])->callAction('export_'.$format);
            $component->assertFileDownloaded();
            if ($format === 'csv') {
                $content = base64_decode((string) ($component->effects['download']['content'] ?? ''));
                $this->assertStringContainsString('Export', $content);
                $this->assertStringNotContainsString('Hidden', $content);
            }
        }
        $this->assertSame(3, AuditEvent::query()->where('action', 'table.exported')->count());
    }

    public function test_age_filter_counts_the_age_at_the_given_date(): void
    {
        $this->actAs('administrator', 'a@example.org');
        $birthday = Person::factory()->create(['last_name' => 'Birthday', 'birth_date' => '2013-08-01']);
        Livewire::test(ListPeople::class)->filterTable('age', ['min' => 14, 'max' => 14, 'at' => '2027-07-31'])->assertCanNotSeeTableRecords([$birthday]);
        Livewire::test(ListPeople::class)->filterTable('age', ['min' => 14, 'max' => 14, 'at' => '2027-08-01'])->assertCanSeeTableRecords([$birthday]);
    }

    public function test_query_builder_combines_with_or_and_not(): void
    {
        $this->actAs('administrator', 'a@example.org');
        $group = Group::query()->create(['name' => 'Choir', 'kind' => 'manual']);
        $ann = Person::factory()->create(['last_name' => 'Ash']);
        $ben = Person::factory()->create(['last_name' => 'Birch']);
        $cem = Person::factory()->create(['last_name' => 'Cedar']);
        $ann->groups()->attach($group->id, ['source' => 'manual']);
        $rule = fn (string $operator, array $settings, string $type) => ['type' => $type, 'data' => ['operator' => $operator, 'settings' => $settings]];
        Livewire::test(ListPeople::class)->set('tableFilters.queryBuilder.rules', ['r1' => $rule('equals.inverse', ['text' => 'Birch'], 'last_name')])
            ->assertCanSeeTableRecords([$ann, $cem])->assertCanNotSeeTableRecords([$ben]);
        Livewire::test(ListPeople::class)->set('tableFilters.queryBuilder.rules', ['o' => ['type' => 'or', 'data' => ['groups' => [
            'a' => ['rules' => ['r1' => $rule('isRelatedTo', ['values' => [$group->id]], 'groups')]],
            'b' => ['rules' => ['r2' => $rule('equals', ['text' => 'Cedar'], 'last_name')]],
        ]]]])->assertCanSeeTableRecords([$ann, $cem])->assertCanNotSeeTableRecords([$ben]);
    }

    public function test_saved_views_are_private_unless_shared(): void
    {
        $owner = $this->actAs('administrator', 'a@example.org');
        Livewire::test(ListPeople::class)->searchTable('Ash')->filterTable('children', true)->callAction('save_view', data: ['name' => 'Mine', 'shared' => false])->assertHasNoActionErrors();
        Livewire::test(ListPeople::class)->callAction('save_view', data: ['name' => 'For all', 'shared' => true]);
        $mine = SavedView::query()->where('name', 'Mine')->sole();
        $shared = SavedView::query()->where('name', 'For all')->sole();

        $page = Livewire::test(ListPeople::class)->callAction('view_'.$mine->id);
        $this->assertSame('Ash', $page->get('tableSearch'));
        $this->assertTrue((bool) $page->get('tableFilters.children.isActive'));

        $this->actAs('board', 'b@example.org');
        Livewire::test(ListPeople::class)->assertActionExists('view_'.$shared->id)->assertActionDoesNotExist('view_'.$mine->id)->assertActionHidden('delete_view');
        Livewire::test(ListInvoices::class)->assertActionDoesNotExist('view_'.$shared->id);

        $this->actingAs($owner);
        Livewire::test(ListPeople::class)->callAction('delete_view', data: ['view' => $mine->id]);
        $this->assertNull($mine->fresh());
    }
}
