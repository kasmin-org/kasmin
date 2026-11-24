<?php

namespace Tests\Feature\Reports;

use App\Models\AuditEvent;
use App\Models\Automation;
use App\Models\Consent;
use App\Models\Event;
use App\Models\Form;
use App\Models\FormResponse;
use App\Models\Household;
use App\Models\Invoice;
use App\Models\Participation;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Import\PeopleImporter;
use App\Support\Install\Defaults;
use App\Support\Mail\Notifier;
use App\Support\Privacy\Privacy;
use Filament\Http\Middleware\AuthenticateSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrivacyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(Defaults::class)->install('en');
        app()->setLocale('en');
    }

    public function test_anonymisation_deletes_personal_data_and_keeps_statistics(): void
    {
        $person = Person::factory()->create(['first_name' => 'Robin', 'last_name' => 'Ash', 'birth_date' => '2012-05-17']);
        $person->contactPoints()->create(['type' => 'email', 'value' => 'robin@example.org']);
        User::query()->create(['name' => 'Robin', 'email' => 'robin@example.org', 'password' => 'x', 'person_id' => $person->id]);
        Household::query()->create(['name' => 'Ash'])->members()->attach($person->id, ['role' => 'child']);
        $event = Event::query()->create(['title' => 'Camp 2025', 'starts_at' => '2025-08-01', 'ends_at' => '2025-08-08', 'status' => 'finished']);
        Participation::query()->create(['event_id' => $event->id, 'person_id' => $person->id, 'role_key' => 'child', 'status' => 'attended', 'registered_at' => '2025-01-01']);
        Consent::query()->create(['person_id' => $person->id, 'kind' => 'deletion_request', 'granted' => true, 'source' => 'mail_link']);

        $privacy = app(Privacy::class);
        $this->assertSame([$person->id], $privacy->openRequests()->pluck('id')->all());
        $plan = $privacy->plan($person);
        $this->assertSame(1, $plan['delete']['contact_points']);
        $this->assertSame(1, $plan['keep']['participations']['count']);

        $privacy->anonymise($person, 'request');
        $person->refresh();
        $this->assertNotNull($person->anonymised_at);
        $this->assertSame('Anonymised', $person->first_name);
        $this->assertSame('2012-01-01', $person->birth_date?->toDateString());
        $this->assertSame(0, $person->contactPoints()->count());
        $this->assertFalse(User::query()->where('email', 'robin@example.org')->exists());
        $this->assertSame(1, $person->participations()->count());
        $this->assertSame([], $privacy->openRequests()->all());
        $this->assertTrue(AuditEvent::query()->where('action', 'privacy.anonymised')->exists());
    }

    public function test_anonymisation_also_cleans_copies_and_refuses_active_roles(): void
    {
        app(PeopleImporter::class)->import([['source_id' => '1', 'first_name' => 'Zoe', 'last_name' => 'Quill', 'email' => 'zoe@example.org', 'family' => 'f1', 'street' => 'Secret lane 1']], 'old');
        $person = Person::query()->where('last_name', 'Quill')->firstOrFail();
        $automation = Automation::query()->firstOrFail();
        $run = $automation->runs()->create(['status' => 'done', 'trigger' => 'manual', 'plan' => [['key' => 'a', 'label' => 'Offer to Zoe Quill']]]);
        app(Notifier::class)->event('registrations', 'Zoe Quill registered');
        $invoice = fn (int $years) => Invoice::query()->create(['payer_id' => $person->id, 'payer_name' => 'Zoe Quill', 'payer_address' => 'Secret lane 1', 'status' => 'paid', 'issued_on' => now()->subYears($years)->toDateString()]);
        [$old, $recent] = [$invoice(9), $invoice(2)];

        app(Privacy::class)->anonymise($person, 'request');

        $this->assertStringNotContainsString('Zoe', (string) json_encode($run->refresh()->plan));
        $this->assertSame(0, \DB::table('notification_events')->where('summary', 'like', '%Zoe%')->count());
        $this->assertSame(0, \DB::table('external_ids')->count());
        $this->assertSame(0, \DB::table('import_records')->where('model_type', Person::class)->count());
        $this->assertNull($old->refresh()->payer_address);
        $this->assertStringNotContainsString('Zoe', (string) $old->payer_name);
        $this->assertSame('Zoe Quill', $recent->refresh()->payer_name, 'kept within the retention period');
        $this->assertSame(0, Household::query()->where('street', 'Secret lane 1')->count());

        $board = Person::factory()->create();
        $board->roleAssignments()->create(['role_id' => Role::query()->where('key', 'board')->value('id')]);
        $this->expectException(\RuntimeException::class);
        app(Privacy::class)->anonymise($board, 'x');
    }

    public function test_retention_deletes_old_safety_sheets_only(): void
    {
        $form = Form::query()->where('key', 'safety_sheet')->firstOrFail();
        $old = Person::factory()->create(['birth_date' => now()->subYears(20)]);
        $recent = Person::factory()->create(['birth_date' => now()->subYears(10)]);
        $past = Event::query()->create(['title' => 'Camp old', 'starts_at' => now()->subYears(8), 'ends_at' => now()->subYears(8)->addWeek(), 'status' => 'finished']);
        $last = Event::query()->create(['title' => 'Camp last', 'starts_at' => now()->subYear(), 'ends_at' => now()->subYear()->addWeek(), 'status' => 'finished']);
        foreach ([[$old, $past], [$recent, $last]] as [$p, $e]) {
            Participation::query()->create(['event_id' => $e->id, 'person_id' => $p->id, 'role_key' => 'child', 'status' => 'attended', 'registered_at' => $e->starts_at]);
            FormResponse::query()->create(['form_id' => $form->id, 'person_id' => $p->id, 'form_version' => 1, 'values' => encrypt(json_encode([]))]);
        }
        $plan = app(Privacy::class)->retentionPlan();
        $this->assertSame([$old->id], array_map(fn ($e) => $e['person']->id, $plan));
        $this->assertSame(1, app(Privacy::class)->runRetention());
        $this->assertSame(0, FormResponse::query()->where('person_id', $old->id)->count());
        $this->assertSame(1, FormResponse::query()->where('person_id', $recent->id)->count());
    }

    public function test_admin_pages(): void
    {
        $this->withoutMiddleware(AuthenticateSession::class);
        $person = Person::factory()->create();
        $person->roleAssignments()->create(['role_id' => Role::query()->where('key', 'administrator')->value('id')]);
        $admin = User::query()->create(['name' => 'Admin', 'email' => 'admin@example.org', 'password' => 'x', 'person_id' => $person->id]);
        foreach (['/admin/privacy', '/admin/audit-events', '/admin/consents'] as $page) {
            $this->actingAs($admin)->withSession(['two_factor_passed' => true])->get($page)->assertOk();
        }
    }
}
