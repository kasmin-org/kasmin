<?php

namespace Tests\Feature\People;

use App\Filament\Pages\DuplicatesPage;
use App\Mail\GuardianInvitationMail;
use App\Mail\LoginLinkMail;
use App\Models\AuditEvent;
use App\Models\Event;
use App\Models\Form;
use App\Models\GuardianInvitation;
use App\Models\Household;
use App\Models\Invoice;
use App\Models\OutgoingMail;
use App\Models\Participation;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Events\Registrations;
use App\Support\Finance\Invoices;
use App\Support\Forms\Forms;
use App\Support\Install\Defaults;
use App\Support\People\Duplicates;
use App\Support\People\Guardians;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class GuardiansAndDuplicatesTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private Person $guardian;

    private Person $child;

    private User $user;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2027-03-01 12:00'));
        app(Defaults::class)->install('en');
        app()->setLocale('en');
        Mail::fake();
        $this->household = Household::query()->create(['name' => 'Ash']);
        $this->guardian = $this->person('Alex', 'Ash', '1985-01-01', 'alex@example.org');
        $this->child = $this->person('Kim', 'Ash', '2012-06-01', null);
        $this->household->members()->attach($this->guardian->id, ['role' => 'guardian']);
        $this->household->members()->attach($this->child->id, ['role' => 'child']);
        $this->child->guardians()->attach($this->guardian->id);
        $this->user = User::query()->create(['name' => 'Alex', 'email' => 'alex@example.org', 'password' => 'x', 'person_id' => $this->guardian->id]);
        $this->event = Event::query()->create(['title' => 'Young weekend', 'starts_at' => '2027-07-01 09:00', 'ends_at' => '2027-07-03 16:00', 'status' => 'open']);
        $this->event->roles()->create(['role_key' => 'guest', 'capacity' => 10, 'min_age' => 13, 'registered_by' => 'self', 'price_cents' => 5000]);
        $this->event->installments()->create(['key' => 'rest', 'label' => ['en' => 'Price'], 'kind' => 'rest', 'due_days' => 14, 'sort' => 1]);
    }

    private function person(string $first, string $last, ?string $born, ?string $mail): Person
    {
        $person = Person::factory()->create(['first_name' => $first, 'last_name' => $last, 'birth_date' => $born, 'locale' => 'en']);
        if ($mail !== null) {
            $person->contactPoints()->create(['type' => 'email', 'value' => $mail, 'is_primary' => true]);
        }

        return $person;
    }

    private function invitationToken(): string
    {
        $token = '';
        Mail::assertSent(GuardianInvitationMail::class, function (GuardianInvitationMail $mail) use (&$token) {
            $token = basename($mail->url);

            return true;
        });

        return $token;
    }

    public function test_a_guardian_invites_another_one_with_the_same_rights(): void
    {
        $this->actingAs($this->user)->post(route('portal.guardian.invite', $this->household->public_id), ['first_name' => 'Sam', 'last_name' => 'Birch', 'email' => 'sam@example.org'])->assertSessionHasNoErrors();
        $token = $this->invitationToken();
        $this->post('/logout');
        $this->get('/guardian/'.$token)->assertOk()->assertSee('Alex Ash');
        $this->assertSame(1, $this->child->guardians()->count(), 'opening the link accepts nothing');
        $this->post('/guardian/'.$token)->assertOk()->assertSee('Done');
        $sam = Person::query()->where('first_name', 'Sam')->sole();
        $this->assertTrue($this->child->guardians()->whereKey($sam->id)->exists());
        $this->assertNull(GuardianInvitation::query()->sole()->data);
        $this->post('/guardian/'.$token)->assertSee('no longer valid');
        $this->actingAs(User::query()->where('email', 'sam@example.org')->firstOrFail())->get(route('portal.child', $this->child->public_id))->assertOk();
    }

    public function test_limit_and_strangers(): void
    {
        Settings::set('max_guardians', '2');
        $url = route('portal.guardian.invite', $this->household->public_id);
        $data = ['first_name' => 'Sam', 'last_name' => 'Birch', 'email' => 'sam@example.org'];
        $this->actingAs($this->user)->post($url, $data)->assertSessionHasNoErrors();
        $this->post($url, ['email' => 'more@example.org'] + $data)->assertSessionHasErrors('email');
        $stranger = $this->person('X', 'Y', '1980-01-01', null);
        $this->actingAs(User::query()->create(['name' => 's', 'email' => 's@example.org', 'password' => 'x', 'person_id' => $stranger->id]))->post($url, $data)->assertNotFound();
    }

    public function test_an_invitation_ends_when_the_inviting_person_is_no_guardian_any_more(): void
    {
        $this->actingAs($this->user)->post(route('portal.guardian.invite', $this->household->public_id), ['first_name' => 'Sam', 'last_name' => 'Birch', 'email' => 'sam@example.org']);
        $this->household->members()->detach($this->guardian->id);
        $this->post('/guardian/'.$this->invitationToken())->assertSee('no longer valid');
        $this->assertSame(1, $this->child->guardians()->count());
    }

    public function test_child_login_from_the_minimum_age_and_read_only_safety_sheet(): void
    {
        $sheet = Form::query()->where('key', 'safety_sheet')->with('fields')->firstOrFail();
        app(Forms::class)->save($sheet, $this->child, ['allergies' => 'Nuts'], $this->guardian);
        $this->actingAs($this->user)->post(route('portal.child.login', $this->child->public_id), ['email' => 'alex@example.org'])->assertSessionHasErrors('email');
        $this->post(route('portal.child.login', $this->child->public_id), ['email' => 'kim@example.org'])->assertSessionHasNoErrors();
        Mail::assertSent(LoginLinkMail::class);
        $kim = User::query()->where('email', 'kim@example.org')->firstOrFail();
        $this->actingAs($kim)->get(route('portal.form.edit', [$this->child->public_id, 'safety_sheet']))->assertOk()->assertSee('Nuts')->assertDontSee('<textarea', false);
        $this->post(route('portal.form.update', [$this->child->public_id, 'safety_sheet']), ['values' => []])->assertForbidden();
        $this->post(route('portal.form.confirm', [$this->child->public_id, 'safety_sheet']))->assertForbidden();
        $this->get(route('portal.child', $this->guardian->public_id))->assertNotFound();

        $young = $this->person('Lee', 'Ash', '2016-01-01', null);
        $young->guardians()->attach($this->guardian->id);
        $this->actingAs($this->user)->post(route('portal.child.login', $young->public_id), ['email' => 'lee@example.org'])->assertSessionHasErrors('email');
    }

    public function test_own_registrations_are_private_and_paid_by_the_person(): void
    {
        $this->child->contactPoints()->create(['type' => 'email', 'value' => 'kim@example.org', 'is_primary' => true]);
        $p = app(Registrations::class)->register($this->event, $this->child, 'guest', $this->child);
        $this->assertSame(['kim@example.org'], OutgoingMail::query()->where('kind', 'transactional')->pluck('to_address')->all());
        foreach ([route('portal'), route('portal.child', $this->child->public_id)] as $url) {
            $this->actingAs($this->user)->get($url)->assertOk()->assertDontSee('Young weekend');
        }
        $this->post(route('portal.child.cancel', [$this->child->public_id, $p->id]))->assertNotFound();
        app(Invoices::class)->draftRun($this->event, $this->event->installments()->firstOrFail());
        $invoice = Invoice::query()->sole();
        $this->assertNull($invoice->household_id);
        $this->assertSame($this->child->id, $invoice->payer_id);
        app(Invoices::class)->issue($invoice);
        $this->get(route('portal'))->assertDontSee((string) $invoice->fresh()?->reference);

        Settings::set('own_registrations', 'visible');
        $this->get(route('portal.child', $this->child->public_id))->assertSee('Young weekend');
    }

    public function test_guardianship_ends_at_the_legal_age(): void
    {
        $this->child->update(['birth_date' => '2009-03-02']);
        $this->child->contactPoints()->create(['type' => 'email', 'value' => 'kim@example.org', 'is_primary' => true]);
        $this->assertSame(0, app(Guardians::class)->endAtAge());
        $this->travelTo(Carbon::parse('2027-03-02 04:00'));
        $this->assertSame(1, app(Guardians::class)->endAtAge());
        $this->assertSame(0, app(Guardians::class)->endAtAge(), 'only once');
        $this->assertSame(2, OutgoingMail::query()->where('subject', 'like', 'Change for%')->count());
        $this->actingAs($this->user)->get(route('portal.child', $this->child->public_id))->assertNotFound();
        $this->get(route('portal'))->assertOk()->assertDontSee('portal/children/'.$this->child->public_id, false);
    }

    public function test_duplicates_are_found_but_not_siblings_or_namesakes(): void
    {
        $same = $this->person('Kim', 'Ash', '2012-06-01', null);
        $sound = $this->person('Alexx', 'Asch', '1985-01-01', null);
        $this->person('Kim', 'Ash', '1970-01-01', null); // other date of birth
        $this->person('Robin', 'Ash', '2015-01-01', 'alex@example.org'); // family address
        $pairs = collect(app(Duplicates::class)->all())->map(fn (array $p) => [$p['first']->id, $p['second']->id, $p['reason']])->all();
        $this->assertContains([$this->child->id, $same->id, 'name_birth'], $pairs);
        foreach ($pairs as [$first, $second]) {
            $this->assertContains([$first, $second], [[$this->child->id, $same->id], [$this->guardian->id, $sound->id]]);
        }
        app(Duplicates::class)->dismiss($same, $this->child);
        $this->assertNotContains($same->id, array_map(fn (Person $p) => $p->id, app(Duplicates::class)->of($this->child)));
    }

    public function test_merging_keeps_everything(): void
    {
        $double = $this->person('Kim', 'Asch', '2012-06-02', 'kim@example.org');
        $double->contactPoints()->create(['type' => 'phone', 'value' => '+43 1 1', 'is_primary' => true]);
        $double->guardians()->attach($this->guardian->id); // the same guardianship exists for the kept person
        $participation = Participation::query()->create(['event_id' => $this->event->id, 'person_id' => $double->id, 'role_key' => 'guest', 'status' => 'registered', 'registered_at' => now()]);
        $invoice = Invoice::query()->create(['payer_id' => $double->id, 'payer_name' => 'Kim Asch', 'total_cents' => 100]);
        $invoice->lines()->create(['person_id' => $double->id, 'participation_id' => $participation->id, 'description' => 'x', 'amount_cents' => 100, 'discount_cents' => 0]);
        app(Invoices::class)->issue($invoice);
        $login = User::query()->create(['name' => 'Kim', 'email' => 'kim@example.org', 'password' => 'x', 'person_id' => $double->id]);

        app(Duplicates::class)->merge($this->child, $double, ['birth_date' => '2012-06-02']);
        $this->assertNull(Person::query()->find($double->id));
        $this->assertSame('2012-06-02', $this->child->refresh()->birth_date?->toDateString());
        $this->assertSame('Ash', $this->child->last_name);
        $this->assertSame($this->child->id, $participation->fresh()?->person_id);
        $this->assertSame($this->child->id, $invoice->fresh()?->payer_id);
        $this->assertSame($this->child->id, $invoice->lines()->sole()->person_id);
        $this->assertSame($this->child->id, $login->fresh()?->person_id);
        $this->assertSame(2, $this->child->contactPoints()->count());
        $this->assertSame(1, $this->child->guardians()->count());
        $this->assertSame(1, AuditEvent::query()->where('action', 'person.merged')->count());
    }

    public function test_merging_never_gives_more_rights_and_keeps_invoiced_registrations(): void
    {
        $admin = $this->person('Kim', 'Ash', '2012-06-01', null);
        $admin->roleAssignments()->create(['role_id' => Role::query()->where('key', 'administrator')->value('id')]);
        try {
            app(Duplicates::class)->merge($this->child, $admin);
            $this->fail('merged roles');
        } catch (RuntimeException) {
            $this->assertNotNull($admin->fresh());
        }
        $double = $this->person('Kim', 'Ash', '2012-06-01', null);
        foreach ([$this->child, $double] as $person) {
            $p = Participation::query()->create(['event_id' => $this->event->id, 'person_id' => $person->id, 'role_key' => 'guest', 'status' => 'registered', 'registered_at' => now()]);
        }
        Invoice::query()->create(['total_cents' => 100])->lines()->create(['participation_id' => $p->id, 'description' => 'x', 'amount_cents' => 100, 'discount_cents' => 0]);
        $this->expectException(RuntimeException::class);
        app(Duplicates::class)->merge($this->child, $double);
    }

    public function test_merging_keeps_the_payout_account_of_expense_claims(): void
    {
        $double = $this->person('Kim', 'Ash', '2012-06-01', null);
        $double->forceFill(['payout_iban' => 'AT611904300234573201'])->save();
        app(Duplicates::class)->merge($this->child, $double);
        $this->assertSame('AT611904300234573201', $this->child->fresh()?->payout_iban);
    }

    public function test_duplicates_page(): void
    {
        $double = $this->person('Kim', 'Ash', '2012-06-01', 'kim@example.org');
        $make = function (string $role): User {
            auth()->forgetUser(); // roles are assigned by the system here
            $person = $this->person('Role', $role, null, null);
            $person->roleAssignments()->create(['role_id' => Role::query()->where('key', $role)->value('id')]);

            return User::query()->create(['name' => $role, 'email' => $role.'@example.org', 'password' => 'x', 'person_id' => $person->id]);
        };
        $this->actingAs($make('camp_leader'));
        $this->assertFalse(DuplicatesPage::canAccess());
        $this->actingAs($make('board'));
        Livewire::test(DuplicatesPage::class)->assertSee('Same name and date of birth')
            ->callAction('merge', data: ['keep' => 'first'], arguments: ['first' => $this->child->id, 'second' => $double->id])->assertHasNoActionErrors();
        $this->assertNull(Person::query()->find($double->id));
    }
}
