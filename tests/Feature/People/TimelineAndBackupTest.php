<?php

namespace Tests\Feature\People;

use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\People\Pages\PersonTimeline;
use App\Filament\Resources\People\Pages\ViewPerson;
use App\Mail\LoginLinkMail;
use App\Models\Event;
use App\Models\Form;
use App\Models\Group;
use App\Models\Invoice;
use App\Models\MailTemplate;
use App\Models\OutgoingMail;
use App\Models\Participation;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Backup\Backups;
use App\Support\Events\Registrations;
use App\Support\Finance\Invoices;
use App\Support\Forms\Forms;
use App\Support\Install\Defaults;
use App\Support\Mail\Composer;
use App\Support\Mail\Routing;
use App\Support\People\GroupMembers;
use App\Support\Settings;
use App\Support\System\SystemStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class TimelineAndBackupTest extends TestCase
{
    use RefreshDatabase;

    private Person $child;

    private Person $guardian;

    protected function setUp(): void
    {
        parent::setUp();
        app(Defaults::class)->install('en');
        app()->setLocale('en');
        $this->guardian = Person::factory()->create(['first_name' => 'Alex', 'last_name' => 'Ash']);
        $this->guardian->contactPoints()->create(['type' => 'email', 'value' => 'alex@example.org', 'is_primary' => true]);
        $this->child = Person::factory()->create(['first_name' => 'Kim', 'last_name' => 'Ash', 'birth_date' => now()->subYears(10)->toDateString()]);
        $this->child->guardians()->attach($this->guardian->id);
    }

    private function user(string $role): User
    {
        auth()->forgetUser();
        $person = Person::factory()->create(['locale' => 'en']);
        $person->roleAssignments()->create(['role_id' => Role::query()->where('key', $role)->value('id')]);

        return User::query()->create(['name' => $role, 'email' => $role.'@example.org', 'password' => 'x', 'person_id' => $person->id]);
    }

    private function history(): void
    {
        $event = Event::query()->create(['title' => 'Timeline camp', 'starts_at' => now()->addMonths(3), 'ends_at' => now()->addMonths(3)->addWeek(), 'status' => 'open']);
        $event->roles()->create(['role_key' => 'child', 'capacity' => 5]);
        app(Registrations::class)->register($event, $this->child, 'child', $this->guardian);
        GroupMembers::join(Group::query()->create(['name' => 'Choir', 'kind' => 'manual']), $this->child->id, 'manual');
        app(Forms::class)->save(Form::query()->where('key', 'safety_sheet')->with('fields')->firstOrFail(), $this->child, ['allergies' => 'Secretnuts', 'swimmer' => 'yes'], $this->guardian);
        $invoice = app(Invoices::class)->issue(app(Invoices::class)->single($this->guardian, [['description' => 'Cap', 'amount_cents' => 1234]]));
        $invoice->lines()->update(['person_id' => $this->child->id]);
        app(Composer::class)->queue('newsletter', $this->child->contactPoints()->create(['type' => 'email', 'value' => 'kim@example.org', 'is_primary' => true])->person ?? $this->child);
    }

    public function test_timeline_shows_what_the_role_may_see(): void
    {
        $this->history();
        $page = fn (string $role) => Livewire::actingAs($this->user($role))->test(PersonTimeline::class, ['record' => $this->child->id]);
        $page('administrator')->assertSee('Timeline camp')->assertSee('Choir')->assertSee('12,34')->assertSee('Known allergies')->assertSee((string) OutgoingMail::query()->where('person_id', $this->child->id)->value('subject'))->assertDontSee('Secretnuts')
            ->set('type', 'group')->assertSee('Choir')->assertDontSee('Timeline camp');
        $page('treasurer')->assertSee('12,34')->assertDontSee('Timeline camp')->assertDontSee('Known allergies')->assertSee('My child can swim');
        $page('camp_leader')->assertSee('Timeline camp')->assertDontSee('12,34')->assertDontSee('Known allergies');
        $page('safety_officer')->assertSee('Known allergies')->assertDontSee('12,34');
        $page('auditor')->assertForbidden();
    }

    public function test_login_link_goes_only_to_the_known_address(): void
    {
        Mail::fake();
        $user = User::query()->create(['name' => 'Alex', 'email' => 'alex@example.org', 'password' => 'x', 'person_id' => $this->guardian->id]);
        Livewire::actingAs($this->user('board'))->test(ViewPerson::class, ['record' => $this->guardian->id])->callAction('login_link')->assertHasNoActionErrors();
        Mail::assertSent(LoginLinkMail::class, fn (LoginLinkMail $mail) => $mail->hasTo($user->email));
        Livewire::actingAs($this->user('treasurer'))->test(ViewPerson::class, ['record' => $this->guardian->id])->assertActionHidden('login_link');
        Livewire::actingAs($this->user('administrator'))->test(ViewPerson::class, ['record' => $this->child->id])->assertActionHidden('login_link');
    }

    public function test_single_invoice_is_a_draft(): void
    {
        Livewire::actingAs($this->user('treasurer'))->test(ListInvoices::class)->callAction('single', data: ['payer_id' => $this->guardian->id, 'lines' => [['description' => 'Cap', 'amount' => '12.50'], ['description' => 'Scarf', 'amount' => '7.50']]])->assertHasNoActionErrors();
        $invoice = Invoice::query()->sole();
        $this->assertSame(['draft', 2000, 'Alex Ash', 2], [$invoice->status, $invoice->total_cents, $invoice->payer_name, $invoice->lines()->count()]);
        Livewire::actingAs($this->user('auditor'))->test(ListInvoices::class)->assertActionHidden('single');
    }

    public function test_mails_are_routed_as_the_template_says(): void
    {
        $names = fn (?Participation $p = null) => array_map(fn (Person $r) => $r->first_name, Routing::recipients('registration_confirmation', $this->child, $p));
        $template = MailTemplate::query()->where('key', 'registration_confirmation')->firstOrFail();
        $this->assertSame(['Alex'], $names());
        $template->update(['audience' => 'both']);
        $this->assertSame(['Alex'], $names(), 'the child has no address');
        $this->child->contactPoints()->create(['type' => 'email', 'value' => 'kim@example.org', 'is_primary' => true]);
        $this->assertSame(['Alex', 'Kim'], $names());
        $template->update(['audience' => 'person']);
        $this->assertSame(['Kim'], $names());
        $template->update(['audience' => 'both']);
        $own = new Participation(['person_id' => $this->child->id, 'registered_by_id' => $this->child->id]);
        $this->assertSame(['Kim'], $names($own), 'own registrations stay private');
        $event = Event::query()->create(['title' => 'E', 'starts_at' => now()->addMonths(3), 'ends_at' => now()->addMonths(3)->addDay(), 'status' => 'open']);
        $event->roles()->create(['role_key' => 'child', 'capacity' => 5]);
        app(Registrations::class)->register($event, $this->child, 'child', $this->guardian);
        $this->assertEqualsCanonicalizing(['alex@example.org', 'kim@example.org'], OutgoingMail::query()->where('kind', 'transactional')->pluck('to_address')->all());
    }

    public function test_daily_backups_are_kept_and_shown(): void
    {
        Storage::fake('local');
        $backups = app(Backups::class);
        $check = fn () => collect(app(SystemStatus::class)->checks())->where('key', 'backup')->value('status');
        $this->assertSame('warning', $check());
        Settings::set('backup_keep', '2');
        Storage::disk('local')->put('backups/kasmin-before-0.1-20260101-000000.sql.gz', 'x');
        foreach (['2027-01-01', '2027-01-02', '2027-01-03'] as $day) {
            $this->travelTo($day.' 02:30');
            $this->artisan('kasmin:backup')->assertSuccessful(); // @phpstan-ignore method.nonObject
        }
        $names = array_column($backups->all(), 'name');
        $this->assertCount(3, $names, 'two daily ones and the one before the upgrade');
        $this->assertContains('kasmin-before-0.1-20260101-000000.sql.gz', $names);
        $this->travelBack();
        $this->assertSame('ok', $check());
    }
}
