<?php

namespace Tests\Feature\Events;

use App\Filament\Resources\Events\Pages\ListEvents;
use App\Filament\Resources\EventTemplates\Pages\CreateEventTemplate;
use App\Filament\Resources\EventTemplates\Pages\EditEventTemplate;
use App\Models\AuditEvent;
use App\Models\Event;
use App\Models\EventDocument;
use App\Models\EventTemplate;
use App\Models\Participation;
use App\Models\Person;
use App\Models\PublicForm;
use App\Models\PublicSubmission;
use App\Models\Role;
use App\Models\User;
use App\Support\Events\EventCopier;
use App\Support\Events\EventStatus;
use App\Support\Install\Defaults;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class EventTemplatesTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2027-03-01 12:00'));
        app(Defaults::class)->install('en');
        app()->setLocale('en');
        $this->event = Event::query()->create(['title' => 'Camp 2027', 'starts_at' => '2027-08-01 09:00', 'ends_at' => '2027-08-08 16:00', 'status' => 'open',
            'registration_closes_at' => '2027-06-01 23:59', 'answers_until' => '2027-07-01', 'cancellation_text' => ['en' => 'Conditions'], 'reminder_days_before' => [3, 14]]);
        $this->event->roles()->create(['role_key' => 'child', 'capacity' => 2, 'min_age' => 8, 'max_age' => 13, 'price_cents' => 42000]);
        $this->event->prices()->create(['role_key' => 'child', 'until_date' => '2027-02-01', 'price_cents' => 39000]);
        $this->event->installments()->create(['key' => 'deposit', 'label' => ['en' => 'Deposit', 'de' => 'Anzahlung'], 'kind' => 'fixed', 'amount_cents' => 10000, 'due_date' => '2027-04-01', 'sort' => 1]);
        $this->event->costs()->create(['label' => 'Bus', 'amount_cents' => 100]);
    }

    private function actAs(string $role): User
    {
        $person = Person::factory()->create(['locale' => 'en']);
        $person->roleAssignments()->create(['role_id' => Role::query()->where('key', $role)->value('id')]);
        $user = User::query()->create(['name' => $role, 'email' => $role.'@example.org', 'password' => 'x', 'person_id' => $person->id]);
        $this->actingAs($user);

        return $user;
    }

    public function test_copy_moves_all_dates_and_takes_no_people_or_money(): void
    {
        Participation::query()->create(['event_id' => $this->event->id, 'person_id' => Person::factory()->create()->id, 'role_key' => 'child', 'status' => 'registered', 'registered_at' => now()]);
        $copy = app(EventCopier::class)->copy($this->event->refresh(), 'Camp 2028', Carbon::parse('2028-07-30'))->refresh();
        $this->assertSame(['2028-07-30 09:00', '2028-08-06 16:00'], [$copy->starts_at->format('Y-m-d H:i'), $copy->ends_at->format('Y-m-d H:i')]);
        $this->assertSame('2028-05-30 23:59', $copy->registration_closes_at?->format('Y-m-d H:i'));
        $this->assertSame('2028-06-29', $copy->answers_until?->toDateString());
        $this->assertSame('2028-03-30', $copy->installments()->sole()->due_date?->toDateString());
        $this->assertSame('2028-01-31', $copy->prices()->sole()->until_date?->toDateString());
        $this->assertSame('Conditions', $copy->cancellation_text);
        $this->assertSame([3, 14], $copy->reminder_days_before);
        $this->assertSame('Deposit', $copy->installments()->sole()->label);
        $this->assertSame('draft', $copy->status);
        $this->assertNotSame($this->event->public_id, $copy->public_id);
        $this->assertSame([0, 0, 42000], [$copy->participations()->count(), $copy->costs()->count(), $copy->roles()->sole()->price_cents]);
    }

    public function test_templates_are_never_events(): void
    {
        $this->assertSame(3, EventTemplate::query()->count(), 'default types');
        $this->assertSame(1, Event::query()->count());
        $template = EventTemplate::query()->where('title', 'like', 'Camp%')->sole();
        $this->assertSame(3, $template->roles()->count());
        $this->get('/events/'.$template->public_id)->assertNotFound();
        $event = app(EventCopier::class)->copy($template, 'Camp 2028', Carbon::parse('2028-07-01'));
        $this->assertSame(Event::class, $event::class);
        $this->assertFalse($event->refresh()->is_template);
        $this->assertSame(3, $event->roles()->count());
        app(Defaults::class)->install('en');
        $this->assertSame(3, EventTemplate::query()->count(), 'installed once');
    }

    public function test_admin_interface_copy_template_and_create(): void
    {
        $this->actAs('administrator');
        Livewire::test(ListEvents::class)->callTableAction('copy', $this->event, data: ['title' => 'Camp 2028', 'starts_on' => '2028-07-30'])->assertHasNoTableActionErrors();
        Livewire::test(ListEvents::class)->callTableAction('template', $this->event, data: ['title' => 'Our camp'])->assertHasNoTableActionErrors();
        $template = EventTemplate::query()->where('title', 'Our camp')->sole();
        Livewire::test(ListEvents::class)->callAction('from_template', data: ['template' => $template->id, 'title' => 'New camp', 'starts_on' => '2029-07-30'])->assertHasNoActionErrors();
        $this->assertSame('2029-07-30', Event::query()->where('title', 'New camp')->sole()->starts_at->toDateString());
        Livewire::test(EditEventTemplate::class, ['record' => $template->id])->assertOk()->fillForm(['title' => 'Our camp 2'])->call('save')->assertHasNoFormErrors();
        $this->assertSame(1, $template->refresh()->roles()->count());
        Livewire::test(CreateEventTemplate::class)->fillForm(['title' => 'Day trip', 'status' => 'draft', 'starts_at' => '2027-05-01 09:00', 'ends_at' => '2027-05-01 18:00', 'max_guests' => 0, 'sibling_mode' => 'none', 'sibling_order' => 'oldest', 'roles' => [['role_key' => 'child', 'registered_by' => 'guardian']]])
            ->call('create')->assertHasNoFormErrors();
        $this->assertSame(1, EventTemplate::query()->where('title', 'Day trip')->sole()->roles()->count());
    }

    public function test_status_changes_by_date_once(): void
    {
        $this->event->forceFill(['status' => 'draft', 'announce_at' => '2027-03-02 08:00', 'registration_opens_at' => '2027-03-03 08:00'])->save();
        $status = app(EventStatus::class);
        $step = function (string $time) use ($status): string {
            $this->travelTo(Carbon::parse($time));
            $status->tick();

            return (string) Event::query()->findOrFail($this->event->id)->status;
        };
        $this->assertSame('draft', $step('2027-03-02 07:59'));
        $this->assertSame('announced', $step('2027-03-02 08:00'));
        $this->assertSame('open', $step('2027-03-03 08:01'));
        $this->assertSame('closed', $step('2027-06-02 00:00'));
        $this->event->refresh()->update(['status' => 'open']); // by hand
        $this->assertSame('open', $step('2027-06-02 00:05'));
        $this->assertSame('running', $step('2027-08-01 09:00'));
        $this->assertSame('finished', $step('2027-08-08 16:01'));
        $this->assertSame(5, AuditEvent::query()->where('action', 'event.status_by_date')->count());
    }

    public function test_public_page_and_headers(): void
    {
        $url = '/events/'.$this->event->public_id;
        $this->get($url)->assertNotFound();
        $this->event->update(['public_page' => true, 'map_url' => 'https://example.org/map']);
        $this->get($url)->assertOk()->assertSee('Camp 2027')->assertSee('Places left: 2')->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $this->assertStringEndsWith("; frame-ancestors 'self'", (string) $this->get($url)->headers->get('Content-Security-Policy'));
        $this->get($url.'?embed=1')->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        Settings::set('embed_origins', 'https://www.example.org https://bad.example;script-src http://insecure.example');
        $embedded = $this->get($url.'?embed=1')->assertHeaderMissing('X-Frame-Options');
        $this->assertStringEndsWith("; frame-ancestors 'self' https://www.example.org", (string) $embedded->headers->get('Content-Security-Policy'));
        $this->assertStringNotContainsString('insecure.example', (string) $embedded->headers->get('Content-Security-Policy'));
        foreach (['/login?embed=1', '/admin?embed=1', '/portal?embed=1', $url] as $other) {
            $this->get($other)->assertHeader('X-Frame-Options', 'SAMEORIGIN')->assertHeader('Referrer-Policy', 'same-origin');
        }
        $this->event->update(['status' => 'draft']);
        $this->get($url)->assertNotFound();
    }

    public function test_embedded_form_works_without_cookies_and_shows_errors(): void
    {
        Mail::fake();
        PublicForm::query()->create(['slug' => 'news', 'kind' => 'interest', 'title' => ['en' => 'News'], 'active' => true]);
        $this->post('/f/news?embed=1', ['first_name' => '', 'locale' => 'en'])->assertOk()->assertSee('first name')->assertDontSee('Thank you');
        $this->assertSame(0, PublicSubmission::query()->count());
        $this->post('/f/news?embed=1', ['first_name' => 'A', 'last_name' => 'B', 'email' => 'a@example.org', 'locale' => 'en', 'privacy' => '1'])->assertOk()->assertSee('Thank you');
        $this->assertSame(1, PublicSubmission::query()->count());
    }

    public function test_documents_are_for_participants_and_their_guardians(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('event-documents/a.pdf', 'x');
        $document = EventDocument::query()->create(['event_id' => $this->event->id, 'title' => 'Packing list', 'path' => 'event-documents/a.pdf']);
        $url = route('event.document', $document);
        $this->get($url)->assertNotFound();
        $child = Person::factory()->create();
        $guardian = Person::factory()->create();
        $child->guardians()->attach($guardian->id);
        $user = User::query()->create(['name' => 'G', 'email' => 'g@example.org', 'password' => 'x', 'person_id' => $guardian->id]);
        $this->actingAs($user)->get($url)->assertNotFound();
        $p = Participation::query()->create(['event_id' => $this->event->id, 'person_id' => $child->id, 'role_key' => 'child', 'status' => 'waitlist', 'registered_at' => now()]);
        $this->get($url)->assertNotFound();
        $p->update(['status' => 'registered']);
        $this->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get(route('portal.child', $child->public_id))->assertSee('Packing list');
        $this->post('/logout');
        $document->update(['visibility' => 'public']);
        $this->get($url)->assertNotFound();
        $this->event->update(['public_page' => true]);
        $this->get($url)->assertOk();

        $copy = app(EventCopier::class)->copy($this->event, 'Copy', Carbon::parse('2028-08-01'));
        $document->delete();
        Storage::disk('local')->assertExists('event-documents/a.pdf');
        $copy->documents()->sole()->delete();
        Storage::disk('local')->assertMissing('event-documents/a.pdf');
    }
}
