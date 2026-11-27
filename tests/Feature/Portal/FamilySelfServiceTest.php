<?php

namespace Tests\Feature\Portal;

use App\Mail\AddressChangedMail;
use App\Mail\ConfirmAddressMail;
use App\Models\Automation;
use App\Models\Consent;
use App\Models\Event;
use App\Models\EventSeries;
use App\Models\Form;
use App\Models\Group;
use App\Models\Household;
use App\Models\Mailing;
use App\Models\MailTemplate;
use App\Models\OutgoingMail;
use App\Models\Participation;
use App\Models\Person;
use App\Models\PublicForm;
use App\Models\PublicSubmission;
use App\Models\User;
use App\Support\Auth\ProofOfWork;
use App\Support\Automations\Engine;
use App\Support\Events\Registrations;
use App\Support\Finance\Invoices;
use App\Support\Forms\Forms;
use App\Support\Install\Defaults;
use App\Support\Mail\Mailings;
use App\Support\People\GroupMembers;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class FamilySelfServiceTest extends TestCase
{
    use RefreshDatabase;

    private Person $guardian;

    private Person $child;

    private Household $household;

    private User $user;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        app(Defaults::class)->install('en');
        app()->setLocale('en');
        Settings::set('organisation_iban', 'AT611904300234573201');
        $this->household = Household::query()->create(['name' => 'Ash']);
        $this->guardian = Person::factory()->create(['first_name' => 'Alex', 'last_name' => 'Ash', 'locale' => 'en', 'birth_date' => '1985-01-01']);
        $this->guardian->contactPoints()->create(['type' => 'email', 'value' => 'alex@example.org', 'purposes' => ['login'], 'is_primary' => true]);
        $this->guardian->contactPoints()->create(['type' => 'phone', 'value' => '+43 1 1', 'is_primary' => true]);
        $this->child = Person::factory()->create(['first_name' => 'Kim', 'last_name' => 'Ash', 'birth_date' => now()->subYears(10)->toDateString(), 'locale' => 'en']);
        $this->household->members()->attach($this->guardian->id, ['role' => 'guardian']);
        $this->household->members()->attach($this->child->id, ['role' => 'child']);
        $this->child->guardians()->attach($this->guardian->id);
        $this->user = User::query()->create(['name' => 'Alex', 'email' => 'alex@example.org', 'password' => 'x', 'person_id' => $this->guardian->id]);
        $series = EventSeries::query()->create(['name' => 'Summer camp']);
        $this->event = Event::query()->create(['title' => 'Camp', 'event_series_id' => $series->id, 'starts_at' => now()->addMonths(3), 'ends_at' => now()->addMonths(3)->addWeek(), 'status' => 'open']);
        $this->event->roles()->create(['role_key' => 'child', 'capacity' => 1]);
    }

    private function addressToken(): string
    {
        $token = '';
        Mail::assertSent(ConfirmAddressMail::class, function (ConfirmAddressMail $mail) use (&$token) {
            $token = basename($mail->url);

            return true;
        });

        return $token;
    }

    public function test_phones_and_family_data(): void
    {
        $this->actingAs($this->user)->get('/portal/profile')->assertOk()->assertSee('alex@example.org');
        $this->post('/portal/profile/phones', ['phones' => ['+43 664 2', '']])->assertSessionHasNoErrors();
        $this->assertSame(['+43 664 2'], $this->guardian->contactPoints()->where('type', 'phone')->pluck('value')->all());
        $this->post('/portal/profile/phones', ['phones' => ['<script>']])->assertSessionHasErrors();
        $this->post('/portal/profile/families/'.$this->household->public_id, ['street' => 'Lane 1', 'postal_code' => '1010', 'city' => 'Vienna', 'country' => 'at', 'invoice_recipient_id' => $this->guardian->id])->assertSessionHasNoErrors();
        $this->household->refresh();
        $this->assertSame(['Lane 1', 'AT', $this->guardian->id], [$this->household->getAttribute('street'), $this->household->getAttribute('country'), (int) $this->household->getAttribute('invoice_recipient_id')]);
        $this->post('/portal/profile/families/'.$this->household->public_id, ['country' => 'AT', 'invoice_recipient_id' => $this->child->id])->assertSessionHasErrors('invoice_recipient_id');
        $other = Household::query()->create(['name' => 'Other']);
        $this->post('/portal/profile/families/'.$other->public_id, ['country' => 'AT'])->assertNotFound();
        $this->withSession(['login_scope' => 'limited'])->get('/portal/profile')->assertRedirect(route('step-up'));
    }

    public function test_a_new_address_counts_after_the_confirmation_and_the_old_one_is_told(): void
    {
        Mail::fake();
        $point = $this->guardian->contactPoints()->where('type', 'email')->firstOrFail();
        $this->actingAs($this->user)->post('/portal/profile/email', ['email' => 'New@Example.org', 'replaces' => $point->id])->assertSessionHasNoErrors();
        $this->assertSame('alex@example.org', $this->user->fresh()?->email);
        $this->assertStringNotContainsString('new@example.org', (string) DB::table('email_changes')->get()->toJson());
        $token = $this->addressToken();
        Mail::assertSent(ConfirmAddressMail::class, fn (ConfirmAddressMail $mail) => str_contains($mail->render(), 'Your address alex@example.org will be replaced by new@example.org.'));
        $this->get('/address/'.$token)->assertOk()->assertSee('Your address alex@example.org will be replaced by new@example.org.')->assertSee('Confirm');
        $this->assertSame('alex@example.org', $this->user->fresh()?->email);
        $this->user->loginTokens()->create(['token_hash' => 'x', 'code_hash' => 'x', 'purpose' => 'login', 'scope' => 'full', 'expires_at' => now()->addHour()]);
        $this->post('/address/'.$token)->assertOk()->assertSee('is confirmed');
        $this->assertSame('new@example.org', $this->user->refresh()->email);
        $this->assertSame(['new@example.org'], $this->guardian->contactPoints()->where('type', 'email')->pluck('value')->all());
        $this->assertSame(0, $this->user->loginTokens()->count(), 'links sent to the old address end');
        Mail::assertSent(AddressChangedMail::class, fn (AddressChangedMail $mail) => $mail->hasTo('alex@example.org'));
        $this->post('/address/'.$token)->assertSee('no longer valid');
    }

    public function test_addresses_of_others_are_neither_taken_nor_revealed(): void
    {
        Mail::fake();
        $other = Person::factory()->create();
        User::query()->create(['name' => 'O', 'email' => 'other@example.org', 'password' => 'x', 'person_id' => $other->id]);
        $this->actingAs($this->user)->post('/portal/profile/email', ['email' => 'other@example.org'])->assertSessionHas('status', __('portal.email_sent'));
        Mail::assertNothingSent();
        $foreign = $other->contactPoints()->create(['type' => 'email', 'value' => 'o2@example.org']);
        $this->post('/portal/profile/email', ['email' => 'mine@example.org', 'replaces' => $foreign->id])->assertNotFound();
        $this->post('/portal/profile/email/'.$foreign->id.'/remove')->assertNotFound();
        $this->post('/portal/profile/email/'.$this->guardian->contactPoints()->where('type', 'email')->value('id').'/remove')->assertSessionHasErrors('email');
        $this->assertSame('alex@example.org', $this->guardian->primaryEmail());
    }

    public function test_open_tasks_sheet_status_payment_data_and_child_page(): void
    {
        $this->household->update(['street' => null]);
        app(Registrations::class)->register($this->event, $this->child, 'child', $this->guardian);
        $invoice = app(Invoices::class)->issue(app(Invoices::class)->single($this->guardian, [['description' => 'Camp', 'amount_cents' => 5000]]));
        $invoice->lines()->update(['person_id' => $this->child->id]);
        Consent::query()->create(['person_id' => $this->child->id, 'kind' => 'photos', 'granted' => true, 'text' => 'Photos for the album']);
        $this->actingAs($this->user)->get('/portal')->assertOk()->assertSee('Open tasks')->assertSee('Safety sheet of Kim is missing')->assertSee('Pay invoice '.$invoice->number)->assertSee('add your address')
            ->assertSee('AT61 1904 3002 3457 3201')->assertSee('data:image/png;base64', false)->assertSee('Safety sheet:');
        $this->get(route('portal.child', $this->child->public_id))->assertSee((string) $invoice->number)->assertSee('Photos for the album');
        $this->household->update(['street' => 'Lane 1', 'city' => 'Vienna']);
        app(Forms::class)->save(Form::query()->where('key', 'safety_sheet')->with('fields')->firstOrFail(), $this->child, ['swimmer' => 'yes'], $this->guardian);
        $this->get('/portal')->assertDontSee('Safety sheet of Kim is missing')->assertDontSee('add your address');
    }

    public function test_data_export_contains_own_data_only(): void
    {
        $sheet = Form::query()->where('key', 'safety_sheet')->with('fields')->firstOrFail();
        app(Forms::class)->save($sheet, $this->child, ['allergies' => 'Nuts'], $this->guardian);
        $stranger = Person::factory()->create(['first_name' => 'Strangername']);
        app(Forms::class)->save($sheet, $stranger, ['allergies' => 'Secretwasps'], null);
        $teen = Person::factory()->create(['first_name' => 'Teen', 'birth_date' => now()->subYears(16)->subDay()->toDateString()]);
        $teen->guardians()->attach($this->guardian->id);
        app(Forms::class)->save($sheet, $teen, ['allergies' => 'Oldallergy'], $this->guardian);
        Participation::query()->create(['event_id' => $this->event->id, 'person_id' => $teen->id, 'role_key' => 'child', 'status' => 'registered', 'registered_at' => now(), 'registered_by_id' => $teen->id]);
        $json = (string) $this->actingAs($this->user)->get('/portal/profile/export')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->getContent();
        foreach (['Alex', 'Kim', 'Nuts', 'Teen'] as $expected) {
            $this->assertStringContainsString($expected, $json);
        }
        foreach (['Strangername', 'Secretwasps', 'Oldallergy', '"event": "Camp"'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $json);
        }
        $this->withSession(['login_scope' => 'limited'])->get('/portal/profile/export')->assertRedirect(route('step-up'));
    }

    public function test_unsubscribing_without_an_earlier_choice_one_click_and_single_topics(): void
    {
        $url = URL::temporarySignedRoute('mail.preferences', now()->addDay(), ['person' => $this->guardian->public_id]);
        $this->get($url)->assertOk()->assertSee('value="newsletter_off"', false)->assertSee('value="optout_all"', false);
        $this->post($url, ['List-Unsubscribe' => 'One-Click'])->assertNoContent();
        $this->assertSame([false, false], [Consent::query()->where('kind', 'newsletter')->sole()->granted, Consent::query()->where('kind', 'optout_all')->sole()->granted]);
        $this->post($url, ['choice' => 'optin_all']);
        $this->post($url, ['choice' => 'newsletter_on']);
        app(Registrations::class)->register($this->event, $this->child, 'child', $this->guardian);
        $group = Group::query()->create(['name' => 'Choir', 'kind' => 'manual']);
        GroupMembers::join($group, $this->child->id, 'manual');
        $mailing = fn (array $recipients) => Mailing::query()->create(['name' => 'M', 'mail_template_id' => MailTemplate::query()->where('key', 'event_information')->value('id'), 'recipients' => $recipients]);
        $count = fn (Mailing $m) => count(app(Mailings::class)->plan($m)['mails']);
        [$camp, $choir] = [$mailing(['event_id' => $this->event->id]), $mailing(['group_ids' => [$group->id]])];
        $this->assertSame([1, 1], [$count($camp), $count($choir)]);
        $this->get($url)->assertSee('No more mails about: Summer camp')->assertSee('No more mails about: Choir');
        $this->post($url, ['choice' => 'topic_off:series:'.$this->event->event_series_id]);
        $this->assertSame([0, 1], [$count($camp), $count($choir)]);
        $this->post($url, ['choice' => 'topic_off:group:'.$group->id]);
        $this->post($url, ['choice' => 'topic_on:series:'.$this->event->event_series_id]);
        $this->assertSame([1, 0], [$count($camp), $count($choir)]);
        $this->post($url, ['choice' => 'topic_off:group:9999'])->assertNotFound();
        $this->post('/mail/preferences/'.$this->guardian->public_id, ['List-Unsubscribe' => 'One-Click'])->assertForbidden();
    }

    public function test_legal_guardians_are_told_about_own_registrations_if_set(): void
    {
        $this->child->contactPoints()->create(['type' => 'email', 'value' => 'kim@example.org', 'is_primary' => true]);
        Settings::set('own_registrations', 'informed');
        app(Registrations::class)->register($this->event, $this->child, 'child', $this->child);
        $notice = OutgoingMail::query()->where('to_address', 'alex@example.org')->sole();
        $this->assertStringContainsString('Kim', $notice->subject);
        $this->assertStringNotContainsString('Camp', $notice->subject.$notice->html);
        $this->actingAs($this->user)->get(route('portal.child', $this->child->public_id))->assertDontSee('Cancel this registration');
    }

    public function test_moving_up_on_the_waitlist_is_told_once(): void
    {
        $others = [];
        foreach ([1, 2] as $i) {
            $others[$i] = Person::factory()->create(['birth_date' => now()->subYears(10)->toDateString()]);
            app(Registrations::class)->register($this->event, $others[$i], 'child', $this->guardian);
        }
        $mine = app(Registrations::class)->register($this->event, $this->child, 'child', $this->guardian);
        $this->assertSame(2, $mine->waitlistPosition());
        $automation = Automation::query()->where('key', 'waitlist_positions')->firstOrFail();
        $this->assertFalse($automation->active);
        $this->assertSame([], app(Engine::class)->preview($automation));
        app(Registrations::class)->cancel(Participation::query()->where('person_id', $others[2]->id)->sole());
        $automation->update(['active' => true, 'mode' => 'automatic']);
        app(Engine::class)->trigger($automation, 'daily');
        $this->assertCount(1, OutgoingMail::query()->where('subject', 'like', '%is at position 1%')->get());
        app(Engine::class)->trigger($automation, 'daily');
        $this->assertSame(1, OutgoingMail::query()->where('subject', 'like', '%is at position 1%')->count());
    }

    public function test_proof_of_work(): void
    {
        config(['kasmin.proof_of_work' => true]);
        Mail::fake();
        $solve = function (string $challenge): string {
            for ($n = 0; ; $n++) {
                if (ProofOfWork::leadingZeros($challenge.':'.$n, ProofOfWork::BITS)) {
                    return $challenge.':'.$n;
                }
            }
        };
        $data = ['first_name' => 'A', 'last_name' => 'B', 'email' => 'a@example.org', 'locale' => 'en', 'privacy' => '1'];
        $this->get('/register')->assertSee('data-challenge', false);
        $this->post('/register', $data)->assertSessionHasErrors('pow');
        $solution = $solve(ProofOfWork::challenge());
        $this->post('/register', $data + ['pow' => $solution])->assertSessionHasNoErrors();
        $this->post('/register', $data + ['pow' => $solution])->assertSessionHasErrors('pow'); // every challenge works once
        $this->assertFalse(ProofOfWork::valid(preg_replace('/\.14\./', '.1.', $solve(ProofOfWork::challenge()))), 'easier challenges are not signed');
        $old = $solve(ProofOfWork::challenge());
        $this->travel(2)->hours();
        $this->assertFalse(ProofOfWork::valid($old), 'expired');
        PublicForm::query()->create(['slug' => 'news', 'kind' => 'interest', 'title' => ['en' => 'News'], 'active' => true]);
        $this->postJson('/api/v1/public/forms/news', ['privacy' => true] + $data)->assertUnprocessable();
        $challenge = (string) $this->getJson('/api/v1/public/forms/news')->json('data.challenge');
        $this->postJson('/api/v1/public/forms/news', ['privacy' => true, 'pow' => $solve($challenge)] + $data)->assertStatus(202);
        $this->assertSame(1, PublicSubmission::query()->count());
    }

    public function test_newsletter_question_on_public_forms(): void
    {
        Mail::fake();
        $form = PublicForm::query()->create(['slug' => 'team', 'kind' => 'application', 'title' => ['en' => 'Team'], 'active' => true, 'ask_newsletter' => true, 'needs_approval' => false]);
        $this->get('/f/team')->assertSee('name="newsletter"', false);
        Settings::set('newsletter_ask', '0');
        $this->get('/f/team')->assertDontSee('name="newsletter"', false);
        $this->get('/register')->assertDontSee('name="newsletter"', false);
        Settings::set('newsletter_ask', '1');
        $this->post('/f/team', ['first_name' => 'A', 'last_name' => 'B', 'email' => 'a@example.org', 'birth_date' => '1990-01-01', 'locale' => 'en', 'privacy' => '1', 'newsletter' => '1'])->assertSessionHasNoErrors();
        $this->assertNotEmpty(PublicSubmission::query()->where('public_form_id', $form->id)->sole()->data['newsletter'] ?? null);
    }

    public function test_a_further_address_is_named_in_the_mail_and_on_the_page(): void
    {
        Mail::fake();
        $this->actingAs($this->user)->post('/portal/profile/email', ['email' => 'second@example.org'])->assertSessionHasNoErrors();
        $token = $this->addressToken();
        Mail::assertSent(ConfirmAddressMail::class, fn (ConfirmAddressMail $mail) => str_contains($mail->render(), 'The address second@example.org will be added to your account.'));
        $this->get('/address/'.$token)->assertOk()->assertSee('The address second@example.org will be added to your account.')->assertDontSee('replaced');
    }

    public function test_the_gender_of_a_child_must_be_chosen_and_can_carry_a_note(): void
    {
        $this->actingAs($this->user)->get('/portal/children/new')->assertOk()
            ->assertSee('data-gender-note="diverse"', false)->assertSee('We separate the children by gender per room.')
            ->assertDontSee('data-gender-note="female"', false)->assertDontSee('value="unspecified"', false)->assertDontSee('not specified');
        $child = ['first_name' => 'Robin', 'last_name' => 'Ash', 'birth_date' => now()->subYears(9)->toDateString()];
        $this->post('/portal/children', $child)->assertSessionHasErrors('gender');
        $this->post('/portal/children', $child + ['gender' => 'unspecified'])->assertSessionHasErrors('gender');
        $this->post('/portal/children', $child + ['gender' => 'diverse'])->assertSessionHasNoErrors();
        $this->assertSame('diverse', Person::query()->where('first_name', 'Robin')->value('gender'));

        Settings::set('gender_note_diverse', '');
        Settings::set('gender_note_female', "Rooms for girls are on the first floor.\nSecond line <b>");
        $this->get('/portal/children/new')->assertOk()->assertDontSee('data-gender-note="diverse"', false)
            ->assertSee('data-gender-note="female"', false)->assertSee('Rooms for girls are on the first floor.<br />', false)->assertSee('&lt;b&gt;', false);
    }
}
