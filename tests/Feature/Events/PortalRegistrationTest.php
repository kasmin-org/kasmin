<?php

namespace Tests\Feature\Events;

use App\Mail\ConfirmSignUpMail;
use App\Mail\LoginLinkMail;
use App\Models\Consent;
use App\Models\Event;
use App\Models\Household;
use App\Models\Person;
use App\Models\SignUp;
use App\Models\User;
use App\Support\Install\Defaults;
use App\Support\People\SignUps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PortalRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(Defaults::class)->installRoles();
        $this->openSignUp();
        app()->setLocale('en');
        config(['app.locale' => 'en']);
    }

    public function test_public_sign_up_creates_the_family_after_the_confirmation(): void
    {
        Mail::fake();
        $data = ['first_name' => 'Robin', 'last_name' => 'Example', 'email' => 'Robin@Example.org', 'locale' => 'en', 'privacy' => '1', 'newsletter' => '1'];
        $people = Person::query()->count();
        $this->post('/register', $data)->assertRedirect('/login/code');
        $this->assertSame($people, Person::query()->count(), 'nothing is created before the confirmation');
        $this->assertStringNotContainsString('robin@example.org', (string) DB::table('sign_ups')->get()->toJson(), 'encrypted');
        $token = '';
        Mail::assertSent(ConfirmSignUpMail::class, function (ConfirmSignUpMail $mail) use (&$token) {
            $token = basename($mail->url);

            return true;
        });
        $this->get('/register/confirm/'.$token)->assertOk();
        $this->assertSame($people, Person::query()->count(), 'opening the link (mail scanners) confirms nothing');
        $login = $this->post('/register/confirm/'.$token)->assertRedirect()->headers->get('Location');
        $this->assertStringContainsString('/login/link/', (string) $login);
        $user = User::query()->where('email', 'robin@example.org')->firstOrFail();
        $this->assertSame('guardian', $user->person?->households()->firstOrFail()->getRelationValue('pivot')->getAttribute('role'));
        $consent = Consent::query()->where('kind', 'newsletter')->sole();
        $this->assertTrue($consent->granted);
        $this->assertNotEmpty($consent->getAttribute('text'));
        $this->post('/register/confirm/'.$token)->assertSee('no longer valid');

        // Same address again: no second account, but a login link.
        $this->post('/register', $data);
        $this->assertSame(1, User::query()->where('email', 'robin@example.org')->count());
        Mail::assertSent(LoginLinkMail::class, 1);
    }

    public function test_unconfirmed_sign_ups_expire(): void
    {
        Mail::fake();
        $this->post('/register', ['first_name' => 'A', 'last_name' => 'B', 'email' => 'a@example.org', 'locale' => 'en', 'privacy' => '1']);
        $this->travel(4)->days();
        $this->assertSame(1, app(SignUps::class)->expire());
        $this->assertSame(0, SignUp::query()->count());
    }

    public function test_sign_up_needs_privacy_consent_and_ignores_bots(): void
    {
        Mail::fake();
        $this->post('/register', ['first_name' => 'A', 'last_name' => 'B', 'email' => 'a@example.org', 'locale' => 'de'])->assertSessionHasErrors('privacy');
        $this->post('/register', ['first_name' => 'A', 'last_name' => 'B', 'email' => 'bot@example.org', 'locale' => 'de', 'privacy' => '1', 'website' => 'spam']);
        $this->assertFalse(User::query()->where('email', 'bot@example.org')->exists());
        Mail::assertNothingSent();
    }

    public function test_guardian_adds_a_child_and_registers_it_with_waitlist_position(): void
    {
        $guardian = Person::factory()->create();
        $user = User::query()->create(['name' => 'G', 'email' => 'g@example.org', 'password' => 'x', 'person_id' => $guardian->id]);
        $event = Event::query()->create(['title' => 'Summer camp 2027', 'starts_at' => now()->addMonths(6), 'ends_at' => now()->addMonths(6)->addWeek(), 'status' => 'open']);
        $event->roles()->create(['role_key' => 'child', 'capacity' => 0, 'min_age' => 8, 'max_age' => 13]);

        $this->actingAs($user)->post('/portal/children', ['first_name' => 'Kim', 'last_name' => 'Example', 'birth_date' => now()->subYears(10)->toDateString(), 'gender' => 'female'])
            ->assertRedirect();
        $child = $guardian->wards()->firstOrFail();

        $this->get('/portal/children/'.$child->public_id)->assertOk()->assertSee('Summer camp 2027')->assertSee('Join the waitlist');
        $this->post('/portal/children/'.$child->public_id.'/register', ['event' => $event->public_id, 'role' => 'child'])->assertRedirect();
        $this->get('/portal/children/'.$child->public_id)->assertSee('Position 1 of 1 on the waitlist');
        $this->get('/portal')->assertSee('Kim Example')->assertSee('Waitlist');
    }

    public function test_guardians_cannot_open_other_families_children(): void
    {
        $other = Person::factory()->create();
        $foreignChild = Person::factory()->create();
        $foreignChild->guardians()->attach($other->id);
        Household::factory()->create()->members()->attach($foreignChild->id, ['role' => 'child']);

        $guardian = Person::factory()->create();
        $user = User::query()->create(['name' => 'G', 'email' => 'g2@example.org', 'password' => 'x', 'person_id' => $guardian->id]);

        $this->actingAs($user)->get('/portal/children/'.$foreignChild->public_id)->assertNotFound();
    }
}
