<?php

namespace Tests\Feature\Lists;

use App\Filament\Pages\EventListsPage;
use App\Models\AuditEvent;
use App\Models\Event;
use App\Models\Form;
use App\Models\Household;
use App\Models\Participation;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Forms\Forms;
use App\Support\Install\Defaults;
use App\Support\Lists\CampFolder;
use App\Support\Lists\EventLists;
use App\Support\Lists\ListExport;
use App\Support\Lists\ListTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

class EventListsTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    private Person $ann;

    private Person $ben;

    private Form $sheet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2027-06-01 12:00'));
        app(Defaults::class)->install('en');
        app()->setLocale('en');
        $this->withoutMiddleware(AuthenticateSession::class);
        $this->event = Event::query()->create(['title' => 'Camp 2027', 'starts_at' => '2027-08-01 09:00', 'ends_at' => '2027-08-08 16:00', 'status' => 'open']);
        $this->event->roles()->create(['role_key' => 'child', 'capacity' => 40]);
        $household = Household::query()->create(['name' => 'Ash']);
        $guardian = Person::factory()->create(['first_name' => 'Alex', 'last_name' => 'Ash', 'locale' => 'en']);
        $guardian->contactPoints()->create(['type' => 'phone', 'value' => '+43 1 234', 'is_primary' => true]);
        $household->members()->attach($guardian->id, ['role' => 'guardian']);
        $this->ann = Person::factory()->create(['first_name' => 'Ann', 'last_name' => 'Ash', 'birth_date' => '2015-08-03']);
        $this->ben = Person::factory()->create(['first_name' => 'Ben', 'last_name' => 'Ash', 'birth_date' => '2016-01-01']);
        foreach ([$this->ann, $this->ben] as $child) {
            $household->members()->attach($child->id, ['role' => 'child']);
            $child->guardians()->attach($guardian->id);
            Participation::query()->create(['event_id' => $this->event->id, 'person_id' => $child->id, 'role_key' => 'child', 'status' => 'registered', 'registered_at' => now()]);
        }
        $this->sheet = Form::query()->where('key', 'safety_sheet')->with('fields')->firstOrFail();
    }

    /** @param array<string, mixed> $values */
    private function fill(Person $person, array $values): void
    {
        app(Forms::class)->save($this->sheet, $person, $values + ['diet' => 'none', 'allergies' => ':none', 'intolerances' => ':none', 'medical_needs' => ':none', 'swimmer' => 'yes'], null);
    }

    private function actAs(string $role): User
    {
        auth()->forgetUser(); // roles are assigned by the system here
        $person = Person::factory()->create();
        $person->roleAssignments()->create(['role_id' => Role::query()->where('key', $role)->value('id')]);
        $user = User::query()->create(['name' => $role, 'email' => $role.'@example.org', 'password' => 'x', 'person_id' => $person->id]);
        $this->actingAs($user);

        return $user;
    }

    private function list(string $key): ListTable
    {
        return app(EventLists::class)->build($this->event, $key);
    }

    public function test_every_list_can_be_built_and_exported_in_all_formats(): void
    {
        $this->fill($this->ann, ['allergies' => 'Nuts', 'medication' => [['name' => 'Inhaler', 'dose' => '2', 'time' => 'morning']]]);
        foreach (array_keys(EventLists::LISTS) as $key) {
            $list = $this->list($key);
            foreach ($list->rows as $row) {
                $this->assertCount(count($list->headers), $row, $key);
            }
            foreach (ListExport::FORMATS as $format) {
                $this->assertNotSame('', app(ListExport::class)->render($list, $format, 'Camp'), $key.' '.$format);
            }
        }
    }

    public function test_only_people_who_take_part_are_listed(): void
    {
        $this->event->participations()->where('person_id', $this->ben->id)->update(['status' => 'waitlist']);
        $this->assertSame(['Ann'], array_column($this->list('participants')->rows, 2));
    }

    public function test_birthdays_kitchen_medication_and_missing(): void
    {
        $this->fill($this->ann, ['allergies' => 'Nuts', 'medication' => [['name' => 'Inhaler', 'dose' => '2', 'time' => 'morning']]]);
        $this->assertSame([['Ann Ash', '12']], array_map(fn (array $r) => [$r[1], $r[2]], $this->list('birthdays')->rows));
        $kitchen = $this->list('kitchen')->rows;
        $this->assertSame('Ann Ash', $kitchen[0][0]);
        $this->assertContains('Nuts', $kitchen[0]);
        $this->assertSame('Ben Ash', $kitchen[1][0], 'a missing sheet must not look like "no restrictions"');
        $this->assertSame(['Ann Ash', 'Inhaler', '2', 'morning', '–'], $this->list('medication')->rows[0]);
        $this->assertSame(['Ben Ash'], array_column($this->list('missing')->rows, 0));

        $this->fill($this->ben, []);
        $this->assertSame(['Ann Ash'], array_column($this->list('kitchen')->rows, 0), 'no restrictions: not on the kitchen list');
        $this->assertSame([], $this->list('missing')->rows);
    }

    public function test_changes_since_the_last_event_are_listed_and_marked(): void
    {
        $this->travelTo(Carbon::parse('2026-06-01 12:00'));
        $last = Event::query()->create(['title' => 'Camp 2026', 'starts_at' => '2026-08-01 09:00', 'ends_at' => '2026-08-08 16:00', 'status' => 'finished']);
        Participation::query()->create(['event_id' => $last->id, 'person_id' => $this->ann->id, 'role_key' => 'child', 'status' => 'attended', 'registered_at' => now()]);
        $this->fill($this->ann, []);
        $this->travelTo(Carbon::parse('2027-06-01 12:00'));
        $this->fill($this->ann, ['allergies' => 'Wasps']);
        $this->fill($this->ben, ['allergies' => 'Dust']);

        $changes = $this->list('changes');
        $this->assertCount(1, $changes->rows, 'Ben was not at an earlier event');
        $this->assertSame(['Ann Ash', 'Known allergies', 'Wasps'], array_slice($changes->rows[0], 0, 3));
        $medical = $this->list('medical');
        $column = array_search('Known allergies', $medical->headers, true);
        $this->assertIsInt($column);
        $this->assertTrue($medical->marked(0, $column));
        $this->assertCount(1, $medical->marks);
    }

    public function test_children_over_the_age_limit_are_not_on_safety_lists(): void
    {
        $this->fill($this->ann, ['allergies' => 'Nuts']);
        $this->ann->update(['birth_date' => '2010-01-01']);
        $this->assertNotContains('Ann Ash', array_column($this->list('kitchen')->rows, 0));
        $this->assertNotContains('Ann Ash', array_column($this->list('medical')->rows, 0));
        $this->assertNotContains('Ann Ash', array_map(fn (array $s) => $s['person']->fullName(), app(CampFolder::class)->sheets($this->event)));
    }

    public function test_confidential_lists_are_for_the_safety_role_only(): void
    {
        $leader = $this->actAs('camp_leader');
        foreach (EventLists::LISTS as $key => $confidential) {
            $response = $this->withSession(['two_factor_passed' => true])->get(route('admin.lists.export', [$this->event, $key, 'csv']));
            $confidential ? $response->assertForbidden() : $response->assertOk();
            $this->assertSame(! $confidential, EventLists::mayView($leader, $key));
        }
        $this->get(route('admin.lists.folder', [$this->event]))->assertForbidden();
        $this->fill($this->ann, ['allergies' => 'Nuts']);
        Livewire::test(EventListsPage::class)->set('list', 'kitchen')->assertDontSee('Nuts');
        $this->assertSame(0, AuditEvent::query()->where('action', 'list.viewed')->count());
    }

    public function test_treasurer_and_auditor_get_no_lists(): void
    {
        foreach (['treasurer', 'auditor'] as $role) {
            $this->assertSame([], EventLists::keysFor($this->actAs($role)));
        }
    }

    public function test_views_and_exports_of_the_safety_role_are_logged_without_values(): void
    {
        $this->fill($this->ann, ['allergies' => 'Nuts']);
        $this->actAs('safety_officer');
        Livewire::test(EventListsPage::class)->set('list', 'kitchen')->assertSee('Nuts');
        $this->assertSame(1, AuditEvent::query()->where('action', 'list.viewed')->count());
        $this->withSession(['two_factor_passed' => true])->get(route('admin.lists.export', [$this->event, 'kitchen', 'pdf']))->assertOk()->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->get(route('admin.lists.folder', [$this->event]))->assertOk();
        $this->get(route('admin.lists.folder', [$this->event, $this->ann]))->assertOk();
        $this->get(route('admin.lists.folder', [$this->event, Person::factory()->create()]))->assertNotFound();
        $this->assertSame(1, AuditEvent::query()->where('action', 'list.exported')->count());
        $this->assertSame(2, AuditEvent::query()->where('action', 'camp_folder.exported')->count());
        $this->assertStringNotContainsString('Nuts', (string) AuditEvent::query()->get()->toJson());
    }

    public function test_entered_formulas_stay_text(): void
    {
        $list = new ListTable('t', ['a', 'b'], [['=1+1', '+43 1 234'], ['-cmd', '@x']]);
        $csv = app(ListExport::class)->render($list, 'csv');
        $this->assertStringContainsString("'=1+1;\"+43 1 234\"", $csv);
        $this->assertStringContainsString("'-cmd;'@x", $csv);
        $path = (string) tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($path, app(ListExport::class)->render($list, 'xlsx'));
        $zip = new ZipArchive;
        $zip->open($path);
        $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml').$zip->getFromName('xl/sharedStrings.xml');
        $zip->close();
        unlink($path);
        $this->assertStringContainsString('=1+1', $xml);
        $this->assertStringNotContainsString('<f>', $xml);
        $this->assertStringContainsString('+43 1 234', $xml);
    }
}
