<?php

namespace Tests\Feature\Events;

use App\Filament\Resources\PublicSubmissions\Pages\ListPublicSubmissions;
use App\Mail\ConfirmSubmissionMail;
use App\Mail\GuestInvitationMail;
use App\Models\Consent;
use App\Models\Event;
use App\Models\Form;
use App\Models\Group;
use App\Models\GuestInvitation;
use App\Models\MembershipType;
use App\Models\OutgoingMail;
use App\Models\Participation;
use App\Models\Person;
use App\Models\PublicForm;
use App\Models\PublicSubmission;
use App\Models\Role;
use App\Models\User;
use App\Support\Events\Guests;
use App\Support\Events\PublicForms;
use App\Support\Install\Defaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class PublicFormsTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2027-03-01 12:00'));
        app(Defaults::class)->install('en');
        app()->setLocale('en');
        Mail::fake();
        $this->event = Event::query()->create(['title' => 'Alumni', 'starts_at' => '2027-07-01 09:00', 'ends_at' => '2027-07-03 16:00', 'status' => 'open', 'max_guests' => 1]);
        $this->event->roles()->create(['role_key' => 'staff', 'capacity' => 10, 'min_age' => 15, 'registered_by' => 'self']);
        $this->event->roles()->create(['role_key' => 'guest', 'capacity' => 10, 'min_age' => 15, 'registered_by' => 'self', 'price_cents' => 5000]);
    }

    /** @param array<string, mixed> $more */
    private function form(string $kind, array $more = []): PublicForm
    {
        return PublicForm::query()->create($more + ['slug' => $kind, 'kind' => $kind, 'title' => ['en' => 'Title '.$kind, 'de' => 'x'], 'active' => true]);
    }

    /** @param array<string, mixed> $more */
    private function send(PublicForm $form, array $more = []): void
    {
        $this->post('/f/'.$form->slug, $more + ['first_name' => 'Robin', 'last_name' => 'Example', 'email' => 'robin@example.org', 'birth_date' => '1990-05-05', 'locale' => 'en', 'privacy' => '1'])
            ->assertSessionHasNoErrors()->assertRedirect('/f/'.$form->slug);
    }

    private function token(): string
    {
        $token = '';
        Mail::assertSent(ConfirmSubmissionMail::class, function (ConfirmSubmissionMail $mail) use (&$token) {
            $token = basename($mail->url);

            return true;
        });

        return $token;
    }

    public function test_nothing_happens_before_the_address_is_confirmed(): void
    {
        $people = Person::query()->count();
        $this->send($this->form('event', ['event_id' => $this->event->id, 'role_key' => 'staff']));
        $this->assertSame($people, Person::query()->count());
        $this->assertStringNotContainsString('robin@example.org', (string) DB::table('public_submissions')->get()->toJson(), 'encrypted');
        $token = $this->token();
        $this->get('/confirm/'.$token)->assertOk();
        $this->assertSame(0, Participation::query()->count(), 'opening the link (e.g. by a mail scanner) confirms nothing');
        $this->post('/confirm/'.$token)->assertOk()->assertSee('everything is done');
        $p = Participation::query()->sole();
        $this->assertSame(['registered', 'staff', 'Robin Example'], [$p->status, $p->role_key, $p->person?->fullName()]);
        $this->assertNull(PublicSubmission::query()->sole()->data);
        $this->post('/confirm/'.$token)->assertSee('no longer valid');
        $this->assertSame(1, Participation::query()->count());
    }

    public function test_known_people_are_recognised_and_not_overwritten(): void
    {
        $known = Person::factory()->create(['first_name' => 'Robin', 'last_name' => 'Example', 'birth_date' => '1985-01-01']);
        $known->contactPoints()->create(['type' => 'email', 'value' => 'robin@example.org', 'is_primary' => true]);
        $form = $this->form('event', ['event_id' => $this->event->id, 'role_key' => 'staff']);
        $this->send($form);
        $this->post('/confirm/'.$this->token());
        $this->assertSame($known->id, Participation::query()->sole()->person_id);
        $this->assertSame('1985-01-01', $known->fresh()?->birth_date?->toDateString());
    }

    public function test_another_person_with_the_same_address_is_a_new_person(): void
    {
        $known = Person::factory()->create(['first_name' => 'Alex', 'last_name' => 'Example']);
        $known->contactPoints()->create(['type' => 'email', 'value' => 'robin@example.org', 'is_primary' => true]);
        User::query()->create(['name' => 'Alex', 'email' => 'robin@example.org', 'password' => 'x', 'person_id' => $known->id]);
        $this->send($this->form('event', ['event_id' => $this->event->id, 'role_key' => 'staff']));
        $this->post('/confirm/'.$this->token())->assertOk();
        $this->assertNotSame($known->id, Participation::query()->sole()->person_id);
        $this->assertSame(1, User::query()->where('email', 'robin@example.org')->count());
    }

    public function test_age_rule_and_closed_forms(): void
    {
        $form = $this->form('event', ['event_id' => $this->event->id, 'role_key' => 'staff']);
        $this->send($form, ['birth_date' => '2016-01-01']);
        $this->post('/confirm/'.$this->token())->assertSee('did not work');
        $this->assertSame('failed', PublicSubmission::query()->sole()->status);

        $form->update(['closes_at' => '2027-02-01']);
        $this->get('/f/event')->assertSee('is closed');
        $this->post('/f/event', ['first_name' => 'A', 'last_name' => 'B', 'email' => 'a@example.org', 'birth_date' => '1990-01-01', 'locale' => 'en', 'privacy' => '1'])->assertForbidden();
        $form->update(['closes_at' => null, 'opens_at' => '2027-04-01']);
        $this->get('/f/event')->assertSee('opens on');
        $form->update(['active' => false]);
        $this->get('/f/event')->assertNotFound();
    }

    public function test_bots_and_missing_consent(): void
    {
        $this->form('interest');
        $this->post('/f/interest', ['first_name' => 'A', 'last_name' => 'B', 'email' => 'a@example.org', 'locale' => 'en', 'privacy' => '1', 'website' => 'spam'])->assertRedirect();
        $this->post('/f/interest', ['first_name' => 'A', 'last_name' => 'B', 'email' => 'a@example.org', 'locale' => 'en'])->assertSessionHasErrors('privacy');
        $this->get('/f/interest')->assertDontSee('Thank you')->assertSee('name="email"', false);
        Mail::assertNothingSent();
        $this->assertSame(0, PublicSubmission::query()->count());
    }

    public function test_application_waits_for_approval_and_then_starts_the_membership(): void
    {
        $group = Group::query()->create(['name' => 'Team', 'kind' => 'manual']);
        $type = MembershipType::query()->where('key', 'regular')->firstOrFail();
        $questions = Form::query()->create(['key' => 'q', 'kind' => 'registration', 'name' => ['en' => 'Q', 'de' => 'Q']]);
        $questions->fields()->create(['key' => 'why', 'type' => 'text', 'label' => ['en' => 'Why', 'de' => 'Warum'], 'required' => true]);
        $form = $this->form('application', ['group_id' => $group->id, 'membership_type_id' => $type->id, 'form_id' => $questions->id]);
        $this->send($form, ['values' => ['why' => 'fun']]);
        $this->post('/confirm/'.$this->token())->assertSee('check your application');
        $this->assertFalse(Person::query()->where('last_name', 'Example')->exists(), 'no person before the approval');

        $admin = Person::factory()->create();
        $admin->roleAssignments()->create(['role_id' => Role::query()->where('key', 'board')->value('id')]);
        $this->actingAs(User::query()->create(['name' => 'b', 'email' => 'b@example.org', 'password' => 'x', 'person_id' => $admin->id]));
        $submission = PublicSubmission::query()->sole();
        Livewire::test(ListPublicSubmissions::class)->assertCanSeeTableRecords([$submission])->callTableAction('approve', $submission);
        $person = Person::query()->where('last_name', 'Example')->sole();
        $this->assertSame([$type->id], $person->memberships()->pluck('membership_type_id')->all());
        $this->assertTrue($person->groups()->whereKey($group->id)->exists());
        $this->assertSame(1, OutgoingMail::query()->where('person_id', $person->id)->where('subject', 'like', 'Welcome%')->count());
    }

    public function test_declined_and_expired_submissions_keep_no_data(): void
    {
        $form = $this->form('application');
        $this->send($form);
        $this->post('/confirm/'.$this->token());
        app(PublicForms::class)->decline(PublicSubmission::query()->sole(), 'no place');
        $this->assertNull(PublicSubmission::query()->sole()->data);
        $this->send($form, ['email' => 'other@example.org']);
        $this->travel(4)->days();
        $this->assertSame(1, app(PublicForms::class)->expire());
    }

    public function test_interest_gives_the_newsletter_consent(): void
    {
        $this->send($this->form('interest'));
        $this->post('/confirm/'.$this->token());
        $this->assertTrue(Consent::query()->where('kind', 'newsletter')->sole()->granted);
    }

    public function test_guests_register_personally_within_the_limit(): void
    {
        $person = Person::factory()->create(['birth_date' => '1990-01-01', 'locale' => 'en']);
        $user = User::query()->create(['name' => 'H', 'email' => 'h@example.org', 'password' => 'x', 'person_id' => $person->id]);
        $this->actingAs($user)->post(route('portal.child.register', $person->public_id), ['event' => $this->event->public_id, 'role' => 'staff'])->assertSessionHasNoErrors();
        $host = Participation::query()->sole();
        $this->get(route('portal.child', $person->public_id))->assertOk()->assertSee('invite 1 more');
        $url = route('portal.child.guest', [$person->public_id, $host->id]);
        $this->post($url, ['email' => 'guest@example.org'])->assertSessionHasNoErrors();
        $this->post($url, ['email' => 'second@example.org'])->assertSessionHasErrors('email');
        $token = '';
        Mail::assertSent(GuestInvitationMail::class, function (GuestInvitationMail $mail) use (&$token) {
            $token = basename($mail->url);

            return true;
        });
        $this->post('/logout');
        $this->get('/guest/'.$token)->assertOk()->assertDontSee('name="email"', false);
        $data = ['first_name' => 'Guest', 'last_name' => 'Person', 'birth_date' => '1991-01-01', 'locale' => 'en', 'privacy' => '1'];
        $this->post('/guest/'.$token, $data)->assertOk()->assertSee('everything is done');
        $guest = Participation::query()->where('role_key', 'guest')->sole();
        $this->assertSame($host->id, $guest->guest_of_id);
        $this->assertSame(0, $guest->person?->memberships()->count());
        $this->post('/guest/'.$token, $data)->assertSee('no longer valid');
        $this->assertNull(GuestInvitation::query()->sole()->email);
        $this->assertSame(0, app(Guests::class)->left($guest->load('event')), 'guests cannot invite guests');
    }
}
