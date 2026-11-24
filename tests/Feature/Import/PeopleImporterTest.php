<?php

namespace Tests\Feature\Import;

use App\Filament\Pages\ImportPeoplePage;
use App\Models\Event;
use App\Models\Household;
use App\Models\ImportBatch;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Import\FamilyBuilder;
use App\Support\Import\PeopleImporter;
use App\Support\Install\Defaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class PeopleImporterTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        return [
            ['source_id' => '101', 'first_name' => 'Ann', 'last_name' => 'Brook', 'birth_date' => '03.04.2015', 'groups' => 'Camp 2027',
                'guardian1_first_name' => 'Chris', 'guardian1_last_name' => 'Brook', 'guardian1_email' => 'Chris.Brook@example.org', 'guardian1_phone' => '+43 660 1234567',
                'street' => 'Main street 1', 'postal_code' => '1010', 'city' => 'Vienna', 'membership_type' => 'child', 'participations' => 'Summer camp 2026:child:attended'],
            ['source_id' => '102', 'first_name' => 'Ben', 'last_name' => 'Brook', 'birth_date' => '2017-08-01',
                'guardian1_first_name' => 'Chris', 'guardian1_last_name' => 'Brook', 'guardian1_email' => 'chris.brook@example.org',
                'guardian2_first_name' => 'Dana', 'guardian2_last_name' => 'Stone', 'guardian2_email' => 'dana@example.org'],
            ['source_id' => '103', 'first_name' => 'Eli', 'last_name' => 'Field', 'birth_date' => '2014-01-01', 'street' => 'Main street 1', 'postal_code' => '1010',
                'guardian1_first_name' => 'Fran', 'guardian1_email' => 'fran@example.org'],
            ['source_id' => '104', 'first_name' => 'Gil', 'last_name' => '', 'birth_date' => 'yesterday'],
            ['source_id' => '105', 'first_name' => 'Hana', 'last_name' => 'Ito', 'birth_date' => '1990-02-02', 'email' => 'hana@example.org', 'phone' => '0664 7654321'],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        app(Defaults::class)->install('en');
        Event::query()->create(['title' => 'Summer camp 2026', 'starts_at' => '2026-08-15 09:00', 'ends_at' => '2026-08-22 16:00', 'status' => 'finished']);
    }

    public function test_dry_run_changes_nothing_and_reports_errors_and_families(): void
    {
        $plan = app(PeopleImporter::class)->plan($this->rows(), 'test');
        $this->assertSame(['create' => 4, 'update' => 0, 'error' => 1], $plan['summary']);
        $this->assertSame('error', $plan['rows'][3]['action']);
        $this->assertContains('birth_date is not a date', $plan['rows'][3]['messages']);
        $this->assertSame(0, Person::query()->count());

        $families = collect($plan['families']);
        $brook = $families->first(fn ($f) => $f['rows'] === [1, 2]);
        $this->assertNotNull($brook);
        $this->assertSame('high', $brook['confidence']);
        $this->assertContains('same e-mail 1', $brook['reasons']);
        $address = $families->first(fn ($f) => in_array(3, $f['rows'], true));
        $this->assertSame('medium', $address['confidence'] ?? null, 'address only is a suggestion');
    }

    public function test_import_builds_families_guardians_logins_and_is_idempotent(): void
    {
        $batch = app(PeopleImporter::class)->import($this->rows(), 'test');
        $this->assertSame(4, $batch->stats['created'] ?? null);
        $this->assertSame(1, $batch->stats['skipped'] ?? null);

        $ann = Person::query()->where('first_name', 'Ann')->firstOrFail();
        $ben = Person::query()->where('first_name', 'Ben')->firstOrFail();
        $this->assertSame('2015-04-03', $ann->birth_date?->toDateString());
        $this->assertSame(['Chris'], $ann->guardians()->pluck('first_name')->all());
        $this->assertEqualsCanonicalizing(['Chris', 'Dana'], $ben->guardians()->pluck('first_name')->all());
        $household = $ann->households()->firstOrFail();
        $this->assertSame($household->id, $ben->households()->firstOrFail()->id, 'siblings share one family');
        $this->assertSame(4, $household->members()->count());
        $this->assertTrue(User::query()->where('email', 'chris.brook@example.org')->exists());
        $this->assertTrue(User::query()->where('email', 'hana@example.org')->exists());
        $this->assertSame(['Camp 2027'], $ann->groups()->pluck('name')->all());
        $this->assertSame('attended', $ann->participations()->firstOrFail()->status);
        $this->assertSame(1, $ann->memberships()->count());
        $this->assertNotSame($household->id, Person::query()->where('first_name', 'Eli')->firstOrFail()->households()->first()?->id, 'address only does not merge families');

        $people = Person::query()->count();
        $rows = $this->rows();
        $rows[0]['first_name'] = 'Anna';
        $again = app(PeopleImporter::class)->import($rows, 'test');
        $this->assertSame(0, $again->stats['created'] ?? null);
        $this->assertSame($people, Person::query()->count(), 'no duplicates on re-import');
        $this->assertSame('Anna', $ann->refresh()->first_name, 'changes in the source update the record');
        $this->assertSame(1, Household::query()->whereHas('members', fn ($q) => $q->where('people.id', $ann->id))->count());
    }

    public function test_rollback_restores_and_deletes(): void
    {
        $first = app(PeopleImporter::class)->import($this->rows(), 'test');
        $rows = $this->rows();
        $rows[4]['last_name'] = 'Itō';
        $second = app(PeopleImporter::class)->import($rows, 'test');
        $this->travel(1)->minutes();

        app(PeopleImporter::class)->rollback($second);
        $this->assertSame('Ito', Person::query()->where('first_name', 'Hana')->firstOrFail()->last_name);
        $this->assertSame('rolled_back', $second->refresh()->status);

        app(PeopleImporter::class)->rollback($first);
        $this->assertSame(0, Person::query()->count());
        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, \DB::table('external_ids')->count());
    }

    public function test_rollback_refuses_after_later_changes(): void
    {
        $batch = app(PeopleImporter::class)->import($this->rows(), 'test');
        $this->travel(1)->minutes();
        Person::query()->where('first_name', 'Ann')->firstOrFail()->update(['first_name' => 'Annie']);

        $this->expectException(\RuntimeException::class);
        app(PeopleImporter::class)->rollback(ImportBatch::query()->findOrFail($batch->id));
    }

    public function test_full_guardian_name_is_split(): void
    {
        app(PeopleImporter::class)->import([['first_name' => 'Ivo', 'last_name' => 'Kern', 'guardian1_name' => 'Maria Luise Kern-Berg']], 'test');
        $guardian = Person::query()->where('first_name', 'Ivo')->firstOrFail()->guardians()->firstOrFail();
        $this->assertSame(['Maria Luise', 'Kern-Berg'], [$guardian->first_name, $guardian->last_name]);
    }

    public function test_wizard_reads_a_csv_file_previews_and_imports(): void
    {
        $person = Person::factory()->create();
        $person->roleAssignments()->create(['role_id' => Role::query()->where('key', 'administrator')->value('id')]);
        $this->actingAs(User::query()->create(['name' => 'a', 'email' => 'a@example.org', 'password' => 'x', 'person_id' => $person->id]));
        Storage::fake('local');
        Storage::disk('local')->put('imports/list.csv', "Vorname;last_name;source_id;guardian1_name\nUla;Vogt;7;Rita Vogt\nUwe;Vogt;8;Rita Vogt\n");

        $page = Livewire::test(ImportPeoplePage::class)
            ->fillForm(['type' => 'file', 'locale' => 'de', 'source' => 'old-list', 'file' => ['imports/list.csv']])
            ->call('read')
            ->assertSet('columns', ['vorname', 'last_name', 'source_id', 'guardian1_name']);
        $this->assertFalse(Storage::disk('local')->exists('imports/list.csv'), 'the file is deleted after reading');
        $page->set('data.mapping.first_name', 'vorname')->call('preview')->assertSet('plan.summary.create', 2)->call('import');
        $this->assertSame(2, Person::query()->where('last_name', 'Vogt')->whereHas('guardians')->count());
        $this->assertSame(1, ImportBatch::query()->count());
    }

    public function test_family_keys_for_guardian_only_rows(): void
    {
        $keys = app(FamilyBuilder::class)->familyKeys([
            ['first_name' => 'A', 'last_name' => 'X', 'guardian1_first_name' => 'P'],
            ['first_name' => 'B', 'last_name' => 'Y'],
        ]);
        $this->assertNotNull($keys[0]);
        $this->assertNull($keys[1]);
    }
}
