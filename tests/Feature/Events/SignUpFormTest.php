<?php

namespace Tests\Feature\Events;

use App\Mail\ConfirmSignUpMail;
use App\Models\Group;
use App\Models\Household;
use App\Models\Person;
use App\Models\PublicForm;
use App\Models\SignUp;
use App\Models\SystemNotification;
use App\Models\User;
use App\Support\Install\Defaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Signing up only through a public form of the kind "signup", switched off by default (B16). */
class SignUpFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_installation_has_a_sign_up_form_switched_off_in_its_language(): void
    {
        app(Defaults::class)->install('de');
        $form = PublicForm::query()->where('kind', 'signup')->sole();
        $this->assertFalse($form->active);
        $this->assertSame('registrierung', $form->slug);
        $this->assertSame('Familienkonto anlegen', $form->title);

        // Switched off: no button on the start page, the old address says it is not possible, posting does nothing.
        Mail::fake();
        $this->get('/')->assertOk()->assertDontSee('/f/registrierung');
        $this->get('/register')->assertOk()->assertSee(__('public.no_signup_title'));
        $this->get('/f/registrierung')->assertNotFound();
        $this->post('/register', ['first_name' => 'A', 'last_name' => 'B', 'email' => 'a@example.org', 'privacy' => '1'])->assertOk();
        $this->assertSame(0, SignUp::query()->count());
        Mail::assertNothingSent();
    }

    public function test_switched_on_the_form_is_the_sign_up_and_puts_the_person_into_its_group(): void
    {
        app(Defaults::class)->install('en');
        app()->setLocale('en');
        Mail::fake();
        $group = Group::query()->create(['name' => 'New families', 'kind' => 'manual']);
        $form = $this->openSignUp();
        $form->update(['group_id' => $group->id, 'intro' => 'Welcome!']);

        $this->get('/')->assertSee('href="'.$form->url().'"', false);
        $this->get('/register')->assertRedirect($form->url());
        $this->get($form->url())->assertOk()->assertSee('Welcome!')->assertSee('name="privacy"', false);

        $this->post($form->url(), ['first_name' => 'Robin', 'last_name' => 'Example', 'email' => 'robin@example.org', 'privacy' => '1'])->assertRedirect('/login/code');
        $token = '';
        Mail::assertSent(ConfirmSignUpMail::class, function (ConfirmSignUpMail $mail) use (&$token) {
            $token = (string) preg_replace('#.*/register/confirm/#', '', $mail->url);

            return true;
        });
        $this->post('/register/confirm/'.$token)->assertRedirect();
        $person = Person::query()->where('first_name', 'Robin')->sole();
        $this->assertTrue($person->groups()->whereKey($group->id)->exists());
    }

    public function test_switching_the_form_off_stops_pending_confirmations(): void
    {
        app(Defaults::class)->install('en');
        Mail::fake();
        $form = $this->openSignUp();
        $this->post($form->url(), ['first_name' => 'Robin', 'last_name' => 'Example', 'email' => 'robin@example.org', 'privacy' => '1'])->assertRedirect('/login/code');
        $token = '';
        Mail::assertSent(ConfirmSignUpMail::class, function (ConfirmSignUpMail $mail) use (&$token) {
            $token = (string) preg_replace('#.*/register/confirm/#', '', $mail->url);

            return true;
        });
        $form->update(['active' => false]);
        $this->get('/register/confirm/'.$token)->assertOk()->assertSee(__('public.no_signup_title'));
        $this->post('/register/confirm/'.$token)->assertOk()->assertSee(__('public.no_signup_title'));
        $this->assertFalse(User::query()->where('email', 'robin@example.org')->exists());

        // Switched on again within the three days, the same link works.
        $form->update(['active' => true]);
        $this->post('/register/confirm/'.$token)->assertRedirect();
        $this->assertTrue(User::query()->where('email', 'robin@example.org')->exists());
    }

    public function test_in_the_portal_only_a_family_or_an_open_sign_up_adds_children(): void
    {
        app(Defaults::class)->install('en');
        // E.g. someone who confirmed a "stay informed" form: a login, but no family.
        $person = Person::factory()->create(['last_name' => 'Stranger']);
        $user = User::query()->create(['name' => 'Sam Stranger', 'email' => 'sam@example.org', 'password' => 'x', 'person_id' => $person->id]);
        $child = ['first_name' => 'Kim', 'last_name' => 'Stranger', 'birth_date' => now()->subYears(9)->toDateString(), 'gender' => 'female'];

        $this->actingAs($user)->get('/portal')->assertOk()->assertDontSee(route('portal.child.create'));
        $this->actingAs($user)->get('/portal/children/new')->assertRedirect('/portal');
        $this->actingAs($user)->post('/portal/children', $child)->assertForbidden();
        $this->assertFalse(Household::query()->where('name', 'Stranger')->exists());

        // While signing up is open, adding a child is signing up.
        $this->openSignUp();
        $this->actingAs($user)->get('/portal')->assertSee(route('portal.child.create'));
        $this->actingAs($user)->post('/portal/children', $child)->assertRedirect();
        $this->assertTrue(Household::query()->where('name', 'Stranger')->exists());

        // With a family of their own, also when it is closed again.
        PublicForm::query()->where('kind', 'signup')->update(['active' => false]);
        $this->actingAs($user)->get('/portal/children/new')->assertOk();
    }

    public function test_a_closed_sign_up_form_shows_that_and_the_start_page_has_no_button(): void
    {
        app(Defaults::class)->install('en');
        $form = $this->openSignUp();
        $form->update(['closes_at' => now()->subDay()]);
        $this->get('/')->assertDontSee(__('start.register'));
        $this->get($form->url())->assertOk()->assertSee(__('public.closed'))->assertDontSee('name="privacy"', false);
        $this->post($form->url(), ['first_name' => 'A', 'last_name' => 'B', 'email' => 'a@example.org', 'privacy' => '1'])->assertForbidden();
    }

    public function test_forms_marked_for_it_appear_on_the_start_page(): void
    {
        app(Defaults::class)->install('en');
        PublicForm::query()->create(['slug' => 'news', 'kind' => 'interest', 'title' => 'Stay informed', 'active' => true, 'on_start_page' => true]);
        PublicForm::query()->create(['slug' => 'hidden', 'kind' => 'interest', 'title' => 'Not listed', 'active' => true]);
        $this->get('/')->assertSee('Stay informed')->assertSee(url('/f/news'))->assertDontSee('Not listed');
    }

    public function test_the_update_tells_administrators_of_existing_installations(): void
    {
        PublicForm::query()->where('kind', 'signup')->delete();
        User::query()->create(['name' => 'A', 'email' => 'a@example.org', 'password' => 'x', 'person_id' => Person::factory()->create()->id]);
        $migration = require database_path('migrations/2026_12_26_000000_sign_up_only_through_a_public_form.php');
        $migration->up();
        $this->assertFalse(PublicForm::query()->where('kind', 'signup')->sole()->active);
        $this->assertTrue(SystemNotification::query()->where('key', 'signup_form_off')->exists());
    }
}
