<?php

namespace Tests\Feature\Forms;

use App\Models\AuditEvent;
use App\Models\Event;
use App\Models\Form;
use App\Models\Household;
use App\Models\Participation;
use App\Models\Person;
use App\Models\RegistrationAnswer;
use App\Models\User;
use App\Support\Install\Defaults;
use App\Support\Lists\EventLists;
use App\Support\Mail\Placeholders;
use App\Support\Privacy\Privacy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class RegistrationAnswersTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    private Person $ann;

    private Person $ben;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2027-03-01 12:00'));
        app(Defaults::class)->install('en');
        app()->setLocale('en');
        $form = Form::query()->create(['key' => 'camp_questions', 'kind' => 'registration', 'name' => ['en' => 'Questions', 'de' => 'Fragen']]);
        $field = fn (string $key, string $type, array $more = []) => $form->fields()->create($more + ['key' => $key, 'type' => $type, 'label' => ['en' => ucfirst($key), 'de' => $key]]);
        $field('bus', 'yes_no', ['required' => true]);
        $field('wish', 'text', ['sensitivity' => 'health']);
        $field('heard', 'text', ['scope' => 'family', 'required' => true]);
        $field('terms', 'consent', ['required' => true, 'help' => ['en' => 'Version of 2027', 'de' => 'x']]);
        $this->event = Event::query()->create(['title' => 'Camp', 'starts_at' => '2027-08-01 09:00', 'ends_at' => '2027-08-08 16:00', 'status' => 'open', 'answers_until' => '2027-07-01']);
        $this->event->roles()->create(['role_key' => 'child', 'capacity' => 40, 'price_cents' => 42000, 'registration_form_id' => $form->id]);
        $this->event->installments()->create(['key' => 'deposit', 'label' => ['en' => 'Deposit'], 'kind' => 'fixed', 'amount_cents' => 10000, 'due_days' => 14, 'refundable' => false, 'sort' => 1]);
        $household = Household::query()->create(['name' => 'Ash']);
        $guardian = Person::factory()->create(['locale' => 'en']);
        $household->members()->attach($guardian->id, ['role' => 'guardian']);
        $this->user = User::query()->create(['name' => 'G', 'email' => 'g@example.org', 'password' => 'x', 'person_id' => $guardian->id]);
        $this->ann = Person::factory()->create(['birth_date' => '2016-01-01']);
        $this->ben = Person::factory()->create(['birth_date' => '2015-01-01']);
        foreach ([$this->ann, $this->ben] as $child) {
            $child->guardians()->attach($guardian->id);
            $household->members()->attach($child->id, ['role' => 'child']);
        }
        $this->actingAs($this->user);
    }

    /**
     * @param  array<string, string>  $values
     * @return TestResponse<Response>
     */
    private function register(Person $child, array $values): TestResponse
    {
        return $this->post(route('portal.child.register', $child->public_id), ['event' => $this->event->public_id, 'role' => 'child', 'values' => $values]);
    }

    public function test_conditions_and_questions_are_shown_before_registering(): void
    {
        $this->get(route('portal.child.register.form', [$this->ann->public_id, $this->event->public_id, 'child']))->assertOk()
            ->assertSee('Price:')->assertSee('Deposit: not refundable.')->assertSee('Heard')->assertSee('Terms');
    }

    public function test_required_answers_and_consents(): void
    {
        $this->register($this->ann, ['bus' => 'yes', 'heard' => 'friends', 'terms' => 'no'])->assertSessionHasErrors('values.terms');
        $this->register($this->ann, ['heard' => 'friends', 'terms' => 'yes'])->assertSessionHasErrors('values.bus');
        $this->assertSame(0, Participation::query()->count());
        $this->register($this->ann, ['bus' => 'yes', 'heard' => 'friends', 'terms' => 'yes', 'wish' => 'secret thing'])->assertSessionHasNoErrors();

        $own = RegistrationAnswer::query()->whereNotNull('participation_id')->sole();
        $this->assertSame(['bus' => 'yes', 'terms' => 'yes'], collect($own->values)->sortKeys()->all());
        $this->assertSame(['wish' => 'secret thing'], $own->confidential);
        $this->assertSame(['terms' => 'Terms – Version of 2027'], $own->consents);
        $this->assertStringNotContainsString('secret', (string) DB::table('registration_answers')->get()->toJson());
        $this->assertStringNotContainsString('secret', (string) AuditEvent::query()->get()->toJson());
    }

    public function test_family_questions_are_asked_once(): void
    {
        $this->register($this->ann, ['bus' => 'yes', 'heard' => 'friends', 'terms' => 'yes']);
        $this->get(route('portal.child.register.form', [$this->ben->public_id, $this->event->public_id, 'child']))->assertOk()->assertDontSee('Heard');
        $this->register($this->ben, ['bus' => 'no', 'terms' => 'yes'])->assertSessionHasNoErrors();
        $this->assertSame(1, RegistrationAnswer::query()->whereNotNull('household_id')->count());
        $answers = app(EventLists::class)->build($this->event, 'answers');
        $column = array_search('Heard', $answers->headers, true);
        $this->assertIsInt($column);
        $this->assertSame(['friends', 'friends'], array_column($answers->rows, $column));
        $this->assertNotContains('Wish', $answers->headers, 'confidential answers are not on the list');
    }

    public function test_answers_can_be_changed_until_the_date(): void
    {
        $this->register($this->ann, ['bus' => 'yes', 'heard' => 'friends', 'terms' => 'yes']);
        $p = Participation::query()->sole();
        $url = route('portal.child.answers.save', [$this->ann->public_id, $p->id]);
        $this->post($url, ['values' => ['bus' => 'no', 'heard' => 'website', 'terms' => 'yes']])->assertSessionHasNoErrors();
        $this->assertSame('no', RegistrationAnswer::query()->whereNotNull('participation_id')->sole()->values['bus']);
        $this->travelTo(Carbon::parse('2027-07-02 08:00'));
        $this->post($url, ['values' => ['bus' => 'yes', 'heard' => 'website', 'terms' => 'yes']])->assertForbidden();
        $this->get(route('portal.child.answers', [$this->ann->public_id, $p->id]))->assertOk()->assertSee('can no longer be changed');
    }

    public function test_other_families_and_other_roles_have_no_access(): void
    {
        $this->register($this->ann, ['bus' => 'yes', 'heard' => 'friends', 'terms' => 'yes']);
        $p = Participation::query()->sole();
        $this->get(route('portal.child.answers', [$this->ben->public_id, $p->id]))->assertNotFound();
        $stranger = User::query()->create(['name' => 'S', 'email' => 's@example.org', 'password' => 'x', 'person_id' => Person::factory()->create()->id]);
        $this->actingAs($stranger)->get(route('portal.child.answers', [$this->ann->public_id, $p->id]))->assertNotFound();

        $this->event->roles()->create(['role_key' => 'staff', 'capacity' => null, 'registered_by' => 'admin']);
        $this->actingAs($this->user)->post(route('portal.child.register', $this->ben->public_id), ['event' => $this->event->public_id, 'role' => 'staff'])->assertNotFound();
    }

    public function test_placeholders_contain_only_answers_that_are_not_confidential(): void
    {
        $this->register($this->ann, ['bus' => 'yes', 'heard' => 'friends', 'terms' => 'yes', 'wish' => 'secret thing']);
        $context = app(Placeholders::class)->for($this->user->person ?? $this->ann, 'en', ['event' => $this->event, 'participation' => Participation::query()->with('event', 'person')->sole()]);
        $this->assertSame('Yes', $context['registration']['answers']['bus']);
        $this->assertArrayNotHasKey('wish', $context['registration']['answers']);
    }

    public function test_anonymising_deletes_the_answers(): void
    {
        $this->register($this->ann, ['bus' => 'yes', 'heard' => 'friends', 'terms' => 'yes']);
        Participation::query()->update(['price_reason' => 'private', 'cancel_reason' => 'private']);
        app(Privacy::class)->anonymise($this->ann, 'request');
        $this->assertSame(0, RegistrationAnswer::query()->whereNotNull('participation_id')->count());
        $this->assertNull(Participation::query()->sole()->price_reason);
    }
}
