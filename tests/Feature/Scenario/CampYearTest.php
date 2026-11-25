<?php

namespace Tests\Feature\Scenario;

use App\Mail\ConfirmSignUpMail;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\Event;
use App\Models\EventSeries;
use App\Models\Form;
use App\Models\FormField;
use App\Models\Invoice;
use App\Models\OutgoingMail;
use App\Models\Participation;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Auth\LoginLinks;
use App\Support\Automations\Engine;
use App\Support\Events\EventStatus;
use App\Support\Events\Registrations;
use App\Support\Finance\Payments;
use App\Support\Forms\Forms;
use App\Support\Install\Defaults;
use App\Support\Lists\EventLists;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * One camp year from the first settings to the start of the next season, with invented families. Legal guardians use the
 * portal over HTTP; the team works through the automations (scheduler tick, then the approval of a board member).
 *
 * Families: Ash (two children), Birch (one child, never pays), Cedar (waitlist, moves up), Dogwood (stays on the waitlist).
 */
class CampYearTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    private User $board;

    /** @var array<string, Person> children by first name */
    private array $children = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2027-01-05 10:00'));
        app(Defaults::class)->install('en');
        app()->setLocale('en');
        config(['app.locale' => 'en']);
        Mail::fake(); // only the mails with one-time links (sign-up); everything else is stored as OutgoingMail
        // Filament checks the password hash of the session; team members are logged in directly in the test.
        $this->withoutMiddleware(AuthenticateSession::class);
    }

    public function test_a_whole_camp_year_from_the_settings_to_the_next_season(): void
    {
        $this->organisationAndEvent();
        $this->familiesRegister();
        $this->depositInvoices();
        $this->paymentRemindersAndReleasedPlace();
        $this->balanceInvoicesWithSiblingDiscount();
        $this->safetySheet();
        $this->listsBeforeTheCamp();
        $this->afterTheCamp();
        $this->nextSeason();
    }

    public function test_composing_a_mail_keeps_the_date_format_of_everything_after_it(): void
    {
        $event = Event::query()->create(['title' => 'Camp 2027', 'starts_at' => '2027-08-01 09:00', 'ends_at' => '2027-08-08 16:00', 'status' => 'open']);
        $event->roles()->create(['role_key' => 'child', 'capacity' => 3]);
        $guardian = Person::factory()->create(['first_name' => 'Alex', 'last_name' => 'Ash', 'locale' => 'en']);
        $guardian->contactPoints()->create(['type' => 'email', 'value' => 'ash@example.org', 'is_primary' => true]);
        $child = Person::factory()->create(['first_name' => 'Alder', 'last_name' => 'Ash', 'birth_date' => '2016-03-01']);
        $child->guardians()->attach($guardian->id);

        $this->assertSame('09/03/2027', Carbon::parse('2027-03-09')->isoFormat('L'), 'English dates are written day first');
        app(Registrations::class)->register($event, $child);
        $this->assertSame(1, $this->mails('ash', 'Registration for Camp 2027: Alder Ash'));

        // Placeholders::date() once used Carbon's static setLocale() and changed all later dates to the American order.
        $this->assertSame('09/03/2027', Carbon::parse('2027-03-09')->isoFormat('L'));
    }

    /** Step 1: settings, series, event with payment plan; the registration opens by date. */
    private function organisationAndEvent(): void
    {
        Settings::set('organisation_name', 'Example Camp Association');
        Settings::set('organisation_iban', 'AT611904300234573201');
        Settings::set('organisation_bic', 'BKAUATWW');

        $person = Person::factory()->create(['first_name' => 'Rowan', 'last_name' => 'Board', 'locale' => 'en']);
        $person->roleAssignments()->create(['role_id' => Role::query()->where('key', 'board')->value('id')]);
        $this->board = User::query()->create(['name' => 'Rowan Board', 'email' => 'board@example.org', 'password' => 'x', 'person_id' => $person->id]);

        $series = EventSeries::query()->create(['name' => 'Summer camp']);
        $this->event = Event::query()->create([
            'title' => 'Camp 2027', 'event_series_id' => $series->id, 'starts_at' => '2027-08-01 09:00', 'ends_at' => '2027-08-08 16:00',
            'status' => 'draft', 'registration_opens_at' => '2027-01-10 09:00',
            'sibling_mode' => 'staggered', 'sibling_amount_cents' => 2000, 'sibling_cap_position' => 3,
        ]);
        $this->event->roles()->create(['role_key' => 'child', 'capacity' => 3, 'min_age' => 8, 'max_age' => 13, 'price_cents' => 40000]);
        $this->event->installments()->create(['key' => 'deposit', 'label' => ['en' => 'Deposit', 'de' => 'Anzahlung'], 'kind' => 'fixed', 'amount_cents' => 10000, 'due_days' => 14, 'discounts_apply' => false, 'refundable' => false, 'sort' => 1]);
        $this->event->installments()->create(['key' => 'balance', 'label' => ['en' => 'Balance', 'de' => 'Restbetrag'], 'kind' => 'rest', 'due_date' => '2027-06-01', 'discounts_apply' => true, 'refundable' => true, 'sort' => 2]);

        // The team switches on the standard automations it wants to use; all of them ask for an approval.
        Automation::query()->whereIn('key', ['waitlist_offers', 'waitlist_positions', 'deposit_invoices', 'balance_invoices', 'payment_followup', 'safety_sheet_reminders', 'close_event'])->update(['active' => true]);
        $this->assertTrue(Automation::query()->where('key', 'payment_reminders')->value('active'), 'payment reminders are switched on by default');

        $this->tick();
        $this->assertSame('draft', $this->event->refresh()->status);
        $this->travelTo(Carbon::parse('2027-01-10 10:00'));
        $this->tick();
        $this->assertSame('open', $this->event->refresh()->status, 'the registration opens by date');
    }

    /** Step 2: four families sign up, add their children and register them; three places. */
    private function familiesRegister(): void
    {
        $this->signUp('Alex', 'Ash', 'ash@example.org');
        $alder = $this->addChild('Alder', 'Ash', '2014-03-01');
        $aspen = $this->addChild('Aspen', 'Ash', '2017-04-01');
        $this->get(route('portal.child.register.form', [$alder->public_id, $this->event->public_id, 'child']))->assertOk()->assertSee('Deposit')->assertSee('Balance');
        $this->registerChild($alder);
        $this->registerChild($aspen);
        $this->get('/portal')->assertOk()->assertSee('Alder Ash')->assertSee('Aspen Ash');
        $this->post('/logout');

        $this->travel(1)->hours();
        $this->signUp('Blake', 'Birch', 'birch@example.org');
        $this->registerChild($this->addChild('Bay', 'Birch', '2016-06-01'));
        $this->post('/logout');

        $this->travel(1)->hours();
        $this->signUp('Casey', 'Cedar', 'cedar@example.org');
        $cy = $this->addChild('Cy', 'Cedar', '2016-09-01');
        $this->get('/portal/children/'.$cy->public_id)->assertOk()->assertSee('Join the waitlist');
        $this->registerChild($cy);
        $this->get('/portal/children/'.$cy->public_id)->assertSee('Position 1 of 1 on the waitlist');
        $this->post('/logout');

        $this->travel(1)->hours();
        $this->signUp('Drew', 'Dogwood', 'dogwood@example.org');
        $this->registerChild($this->addChild('Dale', 'Dogwood', '2017-02-01'));
        $this->post('/logout');

        $this->assertSame(
            ['Alder' => 'registered', 'Aspen' => 'registered', 'Bay' => 'registered', 'Cy' => 'waitlist', 'Dale' => 'waitlist'],
            $this->statuses($this->event),
        );
        foreach (['ash' => ['Alder Ash', 'Aspen Ash'], 'birch' => ['Bay Birch'], 'cedar' => ['Cy Cedar'], 'dogwood' => ['Dale Dogwood']] as $family => $names) {
            foreach ($names as $name) {
                $this->assertSame(1, $this->mails($family, 'Registration for Camp 2027: '.$name), 'confirmation for '.$name);
            }
        }
        $this->assertStringContainsString('Registered', $this->mail('ash', 'Registration for Camp 2027: Alder Ash')->html);
        $this->assertStringContainsString('position 1 of 1', $this->mail('cedar', 'Registration for Camp 2027: Cy Cedar')->html);
        $this->assertStringContainsString('position 2 of 2', $this->mail('dogwood', 'Registration for Camp 2027: Dale Dogwood')->html);
    }

    /** Step 3: deposit invoices, one per family, issued and sent. */
    private function depositInvoices(): void
    {
        $this->travelTo(Carbon::parse('2027-01-12 12:00'));
        $run = app(Engine::class)->trigger($this->automation('deposit_invoices'), 'manual', $this->event, null, $this->board);
        $this->assertSame('done', $run?->status);
        $this->assertCount(2, $run->plan, 'families on the waitlist get no invoice');

        $ash = $this->invoice('Ash', 'deposit');
        $birch = $this->invoice('Birch', 'deposit');
        $this->assertSame(2, Invoice::query()->count());
        $this->assertSame(['issued', 20000, 2, 'Alex Ash'], [$ash->status, $ash->total_cents, $ash->lines->count(), $ash->payer_name]);
        $this->assertSame(['issued', 10000, '2027-01-26'], [$birch->status, $birch->total_cents, $birch->due_on?->toDateString()]);
        $mail = $this->mail('ash', 'Invoice '.$ash->number.' – Camp 2027');
        $this->assertStringContainsString((string) $ash->reference, $mail->html);
        $this->assertEquals([['invoice_id' => $ash->id, 'paid' => false]], $mail->attachments);
        $this->assertSame(1, $this->mails('birch', 'Invoice '.$birch->number.' – Camp 2027'));
        $this->assertSame(0, $this->mails('cedar', 'Invoice%'));

        // The legal guardian sees the invoice in the portal and gets the PDF.
        $this->logIn('ash@example.org');
        $this->get('/portal')->assertOk()->assertSee((string) $ash->number)->assertSee((string) $ash->reference);
        $pdf = $this->get('/portal/invoices/'.$ash->id.'.pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', (string) $pdf->getContent());
        $this->get('/portal/invoices/'.$birch->id.'.pdf')->assertNotFound();
        $this->post('/logout');
    }

    /** Step 4: one family pays, the other is reminded and loses the place; the waitlist moves up. */
    private function paymentRemindersAndReleasedPlace(): void
    {
        $ash = $this->invoice('Ash', 'deposit');
        $birch = $this->invoice('Birch', 'deposit');

        $this->travelTo(Carbon::parse('2027-01-20 12:00'));
        $this->assertSame(['imported' => 1, 'matched' => 1, 'suggested' => 0, 'duplicates' => 0], $this->bankImport([['200,00', 'Camp '.$ash->reference, 'Alex Ash']]));
        $this->assertSame('paid', $ash->refresh()->status);
        $this->assertEquals([['invoice_id' => $ash->id, 'paid' => true]], $this->mail('ash', 'Payment received: invoice '.$ash->number)->attachments);

        // Three days before the due date, then 7 and 21 days after it.
        $this->travelTo(Carbon::parse('2027-01-23 12:00'));
        $this->tick();
        $this->assertCount(1, $this->approve('payment_reminders')->plan);
        $this->assertSame(1, $this->mails('birch', 'Friendly reminder: invoice '.$birch->number.'%'));
        $this->travelTo(Carbon::parse('2027-02-02 12:00'));
        $this->tick();
        $this->approve('payment_reminders');
        $this->travelTo(Carbon::parse('2027-02-16 12:00'));
        $this->tick();
        $this->approve('payment_reminders');
        $this->assertSame(2, $this->mails('birch', 'Payment reminder: invoice '.$birch->number));
        $this->assertSame(0, $this->mails('ash', '%reminder: invoice%'), 'who paid is not reminded');
        $this->assertSame('registered', $this->participation('Bay')->status);

        // 14 days after the last reminder the release of the place is proposed.
        $this->travelTo(Carbon::parse('2027-03-01 12:00'));
        $this->tick();
        $this->assertNull($this->pending('payment_followup'), 'one day too early');
        $this->travelTo(Carbon::parse('2027-03-02 12:00'));
        $this->tick();
        $release = $this->approve('payment_followup');
        $this->assertSame(['Release the place of Bay Birch'], array_map(fn (array $i) => substr($i['label'], 0, 30), $release->plan));
        $this->assertSame('cancelled', $this->participation('Bay')->status);
        $cancellation = $this->mail('birch', 'Cancellation of Camp 2027: Bay Birch');
        // Nothing was paid, so the invoice ends with the place (credit note); the mail promises exactly that.
        $this->assertStringContainsString('Open invoices for it are corrected', $cancellation->html);
        $this->assertSame('cancelled', $birch->refresh()->status);
        $this->assertSame([], app(Engine::class)->preview($this->automation('invoice_corrections')));

        // The freed place starts the waitlist automation; the first one on the list gets the offer.
        $this->assertSame('waitlist', $this->participation('Cy')->status, 'nothing happens before the approval');
        $offer = $this->approve('waitlist_offers');
        $this->assertCount(1, $offer->plan);
        $this->assertSame('answer until 09/03/2027', $offer->plan[0]['details'] ?? null);
        $this->assertSame(['offered', 'waitlist'], [$this->participation('Cy')->status, $this->participation('Dale')->status]);
        $this->assertSame(1, $this->mails('cedar', 'A place is free: Camp 2027 for Cy Cedar'));

        $this->travelTo(Carbon::parse('2027-03-03 18:00'));
        $cy = $this->children['Cy'];
        $this->logIn('cedar@example.org');
        $this->get('/portal/children/'.$cy->public_id)->assertOk()->assertSee('A place has become free!');
        $this->post(route('portal.child.offer', [$cy->public_id, $this->participation('Cy')->id]), ['answer' => 'accept'])->assertSessionHasNoErrors()->assertRedirect();
        $this->post('/logout');
        $this->assertSame('registered', $this->participation('Cy')->status);
        $this->assertSame(2, $this->mails('cedar', 'Registration for Camp 2027: Cy Cedar'), 'a second confirmation after accepting');

        // The next family hears that it moved up.
        $this->travelTo(Carbon::parse('2027-03-04 12:00'));
        $this->tick();
        $this->approve('waitlist_positions');
        $this->assertSame(1, $this->mails('dogwood', 'Camp 2027: Dale Dogwood is at position 1'));

        // The family that moved up gets its deposit invoice with the next run and pays.
        $run = app(Engine::class)->trigger($this->automation('deposit_invoices'), 'manual', $this->event, null, $this->board);
        $this->assertCount(1, $run->plan ?? []);
        $cedar = $this->invoice('Cedar', 'deposit');
        $this->assertSame(['issued', 10000], [$cedar->status, $cedar->total_cents]);
        $this->travelTo(Carbon::parse('2027-03-08 12:00'));
        $this->bankImport([['100,00', (string) $cedar->number, 'Casey Cedar']]);
        $this->assertSame('paid', $cedar->refresh()->status);
    }

    /** The balance 90 days before the start: the second child of a family gets the sibling discount. */
    private function balanceInvoicesWithSiblingDiscount(): void
    {
        $this->travelTo(Carbon::parse('2027-05-04 12:00'));
        $this->tick();
        $this->assertCount(2, $this->approve('balance_invoices')->plan);
        $ash = $this->invoice('Ash', 'balance');
        $cedar = $this->invoice('Cedar', 'balance');
        // 2 × (400 − 100) − 20 for the younger child
        $this->assertSame([58000, [30000, 28000], [0, 2000]], [$ash->total_cents, $ash->lines->pluck('amount_cents')->all(), $ash->lines->pluck('discount_cents')->all()]);
        $this->assertStringContainsString('Aspen Ash', (string) $ash->lines->last()?->description);
        $this->assertSame([30000, '2027-06-01'], [$cedar->total_cents, $cedar->due_on?->toDateString()]);
        $this->assertSame(1, $this->mails('ash', 'Invoice '.$ash->number.' – Camp 2027'));

        $this->travelTo(Carbon::parse('2027-05-10 12:00'));
        $result = $this->bankImport([['580,00', 'Balance '.$ash->reference, 'Alex Ash'], ['300,00', 'Balance '.$cedar->reference, 'Casey Cedar']]);
        $this->assertSame(2, $result['matched']);
        $this->assertSame(['paid', 'paid'], [$ash->refresh()->status, $cedar->refresh()->status]);
        $this->assertSame(0, Invoice::query()->where('type', 'invoice')->whereIn('status', ['issued', 'partially_paid'])->count());
    }

    /** Step 5: one safety sheet is filled in; the others are reminded. */
    private function safetySheet(): void
    {
        $this->travelTo(Carbon::parse('2027-06-10 12:00'));
        $sheet = Form::query()->where('key', 'safety_sheet')->firstOrFail();
        $alder = $this->children['Alder'];
        $this->logIn('ash@example.org');
        $url = '/portal/children/'.$alder->public_id.'/forms/safety_sheet';
        $this->get($url)->assertOk()->assertSee('Known allergies')->assertSee('Alex Ash');
        $this->post($url, ['values' => [
            'diet' => 'vegetarian', 'intolerances' => FormField::NONE, 'allergies' => 'Peanuts', 'medical_needs' => FormField::NONE,
            'tetanus' => '2024', 'tbe' => 'unknown', 'swimmer' => 'yes', 'photos_internal' => 'yes', 'photos_public' => 'no',
        ]])->assertSessionHasNoErrors()->assertRedirect('/portal/children/'.$alder->public_id);
        $this->get('/portal/children/'.$alder->public_id)->assertSee('Confirmed');
        $this->post('/logout');
        $this->assertSame('confirmed', app(Forms::class)->status($sheet, $alder));
        $this->assertSame(1, $this->mails('ash', 'Thank you: safety sheet confirmed'));

        $this->travelTo(Carbon::parse('2027-06-10 19:00'));
        $this->tick();
        $run = $this->approve('safety_sheet_reminders');
        $labels = array_column($run->plan, 'label');
        sort($labels);
        $this->assertSame(['Ash (Aspen Ash)', 'Cedar (Cy Cedar)'], $labels, 'only children without a sheet');
        $request = $this->mail('ash', 'Please fill in or confirm the safety sheet');
        $this->assertStringContainsString('Aspen', $request->html);
        $this->assertStringNotContainsString('Alder', $request->html);
        $this->assertStringNotContainsString('Peanuts', $request->html);
        $this->assertSame(1, $this->mails('cedar', 'Please fill in or confirm the safety sheet'));
        $this->assertSame(0, $this->mails('dogwood', '%safety sheet%'), 'not for the waitlist');
        $this->assertSame(0, $this->mails('birch', '%safety sheet%'), 'not for cancelled registrations');
    }

    /** Step 6: lists for the team. */
    private function listsBeforeTheCamp(): void
    {
        $this->travelTo(Carbon::parse('2027-07-20 12:00'));
        $lists = app(EventLists::class);
        $participants = $lists->build($this->event, 'participants');
        $this->assertSame([['Ash', 'Alder'], ['Ash', 'Aspen'], ['Cedar', 'Cy']], array_map(fn (array $r) => [$r[1], $r[2]], $participants->rows));
        $this->assertSame('Alex Ash', $participants->rows[0][8]);
        $this->assertSame('13', $participants->rows[0][5], 'age at the start of the camp');

        $figures = $lists->build($this->event, 'figures');
        // role, female, male, diverse, not specified, total, were there before, new, waitlist
        // The portal never asks for the gender of a child, so the figures by gender stay empty until the team enters it.
        $this->assertSame(['Child', '0', '0', '0', '3', '3', '0', '3', '1'], $figures->rows[0]);

        $safety = Person::factory()->create(['locale' => 'en']);
        $safety->roleAssignments()->create(['role_id' => Role::query()->where('key', 'safety_officer')->value('id')]);
        $officer = User::query()->create(['name' => 'Safety', 'email' => 'safety@example.org', 'password' => 'x', 'person_id' => $safety->id]);
        $this->assertTrue(EventLists::mayView($officer, 'kitchen', $this->event->id));
        $this->assertFalse(EventLists::mayView($this->board, 'kitchen', $this->event->id), 'health data is not for the board');
        $this->actingAs($officer)->withSession(['two_factor_passed' => true]);
        $kitchen = $lists->build($this->event, 'kitchen');
        $this->assertSame('Alder Ash', $kitchen->rows[0][0]);
        $this->assertContains('Peanuts', $kitchen->rows[0]);
        $this->assertSame(['Aspen Ash', 'Cy Cedar'], array_column($lists->build($this->event, 'missing')->rows, 0));
        $csv = $this->get(route('admin.lists.export', [$this->event, 'kitchen', 'csv']))->assertOk();
        $this->assertStringContainsString('Peanuts', (string) $csv->getContent());
        $this->actingAs($this->board)->withSession(['two_factor_passed' => true])->get(route('admin.lists.export', [$this->event, 'kitchen', 'csv']))->assertForbidden();
        $this->get(route('admin.lists.export', [$this->event, 'participants', 'csv']))->assertOk();
        $this->post('/logout');
    }

    /** Step 7: the event is closed; the families get the confirmation of participation. */
    private function afterTheCamp(): void
    {
        $this->travelTo(Carbon::parse('2027-08-09 12:00'));
        $this->tick();
        $this->assertSame('finished', $this->event->refresh()->status);
        $this->assertNull($this->pending('close_event'), 'one day after the end');
        $this->travelTo(Carbon::parse('2027-08-10 12:00'));
        $this->tick();
        $this->assertCount(3, $this->approve('close_event')->plan);
        $this->assertSame(
            ['Alder' => 'attended', 'Aspen' => 'attended', 'Bay' => 'cancelled', 'Cy' => 'attended', 'Dale' => 'waitlist'],
            $this->statuses($this->event),
        );

        $alder = $this->children['Alder'];
        $this->logIn('ash@example.org');
        $this->get(route('portal.child', $alder->public_id))->assertOk()->assertSee('Confirmation of participation');
        $pdf = $this->get(route('portal.child.confirmation', [$alder->public_id, $this->participation('Alder')->id]))->assertOk();
        $this->assertStringStartsWith('%PDF', (string) $pdf->getContent());
        $this->get(route('portal.child.confirmation', [$this->children['Cy']->public_id, $this->participation('Cy')->id]))->assertNotFound();
        $this->post('/logout');
    }

    /** Step 8: the next season takes over who was there and who was waiting. */
    private function nextSeason(): void
    {
        $this->travelTo(Carbon::parse('2028-01-10 12:00'));
        $next = Event::query()->create(['title' => 'Camp 2028', 'event_series_id' => $this->event->event_series_id, 'starts_at' => '2028-07-30 09:00', 'ends_at' => '2028-08-06 16:00', 'status' => 'open']);
        $next->roles()->create(['role_key' => 'child', 'capacity' => 3, 'min_age' => 8, 'max_age' => 13, 'price_cents' => 42000]);
        $next->roles()->create(['role_key' => 'junior_counsellor', 'capacity' => 2, 'min_age' => 14, 'max_age' => 15, 'price_cents' => 20000]);

        $automation = Automation::query()->where('key', 'start_season')->firstOrFail();
        $ids = fn (string ...$names) => array_map(fn (string $n) => $this->children[$n]->id, $names);
        $plan = app(Engine::class)->preview($automation, $next);
        $keys = array_column($plan, 'key');
        sort($keys);
        $expected = ['propose:'.$ids('Alder')[0], 'register:'.$ids('Aspen')[0], 'register:'.$ids('Cy')[0], 'waitlist:'.$ids('Dale')[0]];
        sort($expected);
        $this->assertSame($expected, $keys);

        $run = app(Engine::class)->trigger($automation, 'manual', $next, null, $this->board);
        $this->assertSame('done', $run?->status);
        $this->assertSame(['Aspen' => 'registered', 'Cy' => 'registered', 'Dale' => 'waitlist'], $this->statuses($next));
        $dale = Participation::query()->where('event_id', $next->id)->where('person_id', $this->children['Dale']->id)->firstOrFail();
        $this->assertTrue($this->participation('Dale')->registered_at->equalTo($dale->registered_at), 'the place in the queue is kept');
        $this->assertSame(1, $dale->waitlistPosition());
        $this->assertSame(1, $this->mails('ash', 'Registration for Camp 2028: Aspen Ash'));
        $this->assertSame(1, $this->mails('cedar', 'Registration for Camp 2028: Cy Cedar'));
        // The family on the waitlist is told that its entry was carried over.
        $this->assertSame(1, $this->mails('dogwood', '%Camp 2028%'));
        // One place is free although somebody waits: it is offered by the daily waitlist automation, if that is switched on.
        $this->assertCount(1, app(Engine::class)->preview($this->automation('waitlist_offers_daily')));

        // A second run has nothing to do; the proposal for the child that is too old is made once.
        $this->assertSame([], app(Engine::class)->preview($automation, $next));
    }

    // ---- helpers ----------------------------------------------------------------------------------------------------

    /** What the scheduler does every hour: status of events by date, then the automations. */
    private function tick(): void
    {
        app(EventStatus::class)->tick();
        app(Engine::class)->tick();
    }

    private function automation(string $key): Automation
    {
        return Automation::query()->where('key', $key)->firstOrFail();
    }

    private function pending(string $key): ?AutomationRun
    {
        return $this->automation($key)->runs()->where('status', 'pending_approval')->latest('id')->first();
    }

    /** A board member approves the open request of an automation. */
    private function approve(string $key): AutomationRun
    {
        $run = $this->pending($key);
        $this->assertNotNull($run, 'no open request of '.$key);
        $this->assertTrue(OutgoingMail::query()->where('to_address', 'board@example.org')->where('subject', 'like', 'Approval needed%')->exists());
        $run = app(Engine::class)->decide($run, $this->board, true);
        $this->assertSame('done', $run->status, $key.': '.json_encode($run->result));

        return $run;
    }

    /** A legal guardian signs up on the public page, confirms the address and is logged in by the link. */
    private function signUp(string $first, string $last, string $email): void
    {
        $this->post('/register', ['first_name' => $first, 'last_name' => $last, 'email' => $email, 'locale' => 'en', 'privacy' => '1'])->assertSessionHasNoErrors()->assertRedirect('/login/code');
        $token = '';
        Mail::assertSent(ConfirmSignUpMail::class, function (ConfirmSignUpMail $mail) use (&$token, $email) {
            if ($mail->hasTo($email)) {
                $token = basename($mail->url);
            }

            return true;
        });
        $this->assertNotSame('', $token);
        $login = (string) $this->post('/register/confirm/'.$token)->assertRedirect()->headers->get('Location');
        $this->post($login)->assertRedirect(route('portal'));
        $this->get('/portal')->assertOk()->assertSee($first);
    }

    /** A later visit: login link as sent by mail. */
    private function logIn(string $email): void
    {
        $user = User::query()->where('email', $email)->firstOrFail();
        $this->post(route('login.link.consume', app(LoginLinks::class)->issue($user)['token']))->assertRedirect(route('portal'));
    }

    private function addChild(string $first, string $last, string $born): Person
    {
        $this->post('/portal/children', ['first_name' => $first, 'last_name' => $last, 'birth_date' => $born])->assertSessionHasNoErrors()->assertRedirect();

        return $this->children[$first] = Person::query()->where('first_name', $first)->where('last_name', $last)->sole();
    }

    private function registerChild(Person $child): void
    {
        $this->post('/portal/children/'.$child->public_id.'/register', ['event' => $this->event->public_id, 'role' => 'child'])->assertSessionHasNoErrors()->assertRedirect();
    }

    private function participation(string $child, ?Event $event = null): Participation
    {
        return Participation::query()->where('event_id', ($event ?? $this->event)->id)->where('person_id', $this->children[$child]->id)->sole();
    }

    /** @return array<string, string> status by first name of the child */
    private function statuses(Event $event): array
    {
        $result = [];
        foreach (Participation::query()->where('event_id', $event->id)->with('person')->get() as $p) {
            $result[(string) $p->person?->first_name] = $p->status;
        }
        ksort($result);

        return $result;
    }

    private function invoice(string $family, string $installment): Invoice
    {
        return Invoice::query()->where('type', 'invoice')->where('installment_key', $installment)->whereHas('household', fn ($q) => $q->where('name', $family))->with('lines')->sole();
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string}>  $rows  amount, text, name
     * @return array{imported: int, matched: int, suggested: int, duplicates: int}
     */
    private function bankImport(array $rows): array
    {
        $csv = (string) tempnam(sys_get_temp_dir(), 'bank');
        $content = "Date;Amount;Text;Name\n";
        foreach ($rows as $row) {
            $content .= now()->format('d.m.Y').';'.implode(';', $row)."\n";
        }
        file_put_contents($csv, $content);
        try {
            return app(Payments::class)->import($csv, 'bank.csv', ['date' => 0, 'amount' => 1, 'reference' => 2, 'name' => 3]);
        } finally {
            unlink($csv);
        }
    }

    private function mails(string $family, string $subject): int
    {
        return OutgoingMail::query()->where('to_address', $family.'@example.org')->where('subject', 'like', $subject)->count();
    }

    private function mail(string $family, string $subject): OutgoingMail
    {
        return OutgoingMail::query()->where('to_address', $family.'@example.org')->where('subject', 'like', $subject)->sole();
    }
}
