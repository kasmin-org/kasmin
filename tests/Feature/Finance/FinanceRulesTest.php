<?php

namespace Tests\Feature\Finance;

use App\Models\Automation;
use App\Models\Event;
use App\Models\Group;
use App\Models\Household;
use App\Models\Invoice;
use App\Models\MembershipType;
use App\Models\OutgoingMail;
use App\Models\Participation;
use App\Models\Payment;
use App\Models\Person;
use App\Models\Refund;
use App\Models\Role;
use App\Models\User;
use App\Support\Automations\Engine;
use App\Support\Events\Registrations;
use App\Support\Finance\Billing;
use App\Support\Finance\Corrections;
use App\Support\Finance\Credits;
use App\Support\Finance\Invoices;
use App\Support\Finance\MembershipFees;
use App\Support\Finance\Payments;
use App\Support\Install\Defaults;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

/** Price 450, deposit 100 (not refundable), balance 350 with sibling discount 20 per position (cap 3). */
class FinanceRulesTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    private Household $household;

    private Person $guardian;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2027-01-15 12:00'));
        app(Defaults::class)->install('en');
        app()->setLocale('en');
        $this->event = Event::query()->create(['title' => 'Camp', 'starts_at' => '2027-08-01 09:00', 'ends_at' => '2027-08-08 16:00', 'status' => 'open',
            'sibling_mode' => 'staggered', 'sibling_amount_cents' => 2000, 'sibling_cap_position' => 3]);
        $this->event->roles()->create(['role_key' => 'child', 'capacity' => 40, 'price_cents' => 45000]);
        $this->event->installments()->create(['key' => 'deposit', 'label' => ['en' => 'Deposit'], 'kind' => 'fixed', 'amount_cents' => 10000, 'due_days' => 14, 'refundable' => false, 'discounts_apply' => false, 'sort' => 1]);
        $this->event->installments()->create(['key' => 'balance', 'label' => ['en' => 'Balance'], 'kind' => 'rest', 'due_date' => '2027-06-01', 'refundable' => true, 'refund_until_days' => 30, 'discounts_apply' => true, 'sort' => 2]);
        $this->household = Household::query()->create(['name' => 'Ash']);
        $this->guardian = Person::factory()->create(['first_name' => 'Alex', 'last_name' => 'Ash', 'birth_date' => '1985-01-01', 'locale' => 'en']);
        $this->guardian->contactPoints()->create(['type' => 'email', 'value' => 'alex@example.org', 'is_primary' => true]);
        $this->household->members()->attach($this->guardian->id, ['role' => 'guardian']);
    }

    private function child(string $name, string $birth): Participation
    {
        $child = Person::factory()->create(['first_name' => $name, 'last_name' => 'Ash', 'birth_date' => $birth]);
        $child->guardians()->attach($this->guardian->id);
        $this->household->members()->attach($child->id, ['role' => 'child']);

        return Participation::query()->create(['event_id' => $this->event->id, 'person_id' => $child->id, 'role_key' => 'child', 'status' => 'registered', 'registered_at' => now()]);
    }

    private function invoice(string $installment): Invoice
    {
        $event = Event::query()->findOrFail($this->event->id);
        app(Invoices::class)->draftRun($event, $event->installments()->where('key', $installment)->firstOrFail());

        return app(Invoices::class)->issue(Invoice::query()->where('installment_key', $installment)->where('status', 'draft')->firstOrFail());
    }

    private function corrected(Invoice $invoice): ?Invoice
    {
        return app(Corrections::class)->correct(Event::query()->findOrFail($this->event->id), $invoice, 'changed');
    }

    private function cents(Invoice $invoice): int
    {
        return (int) Invoice::query()->whereKey($invoice->id)->value('paid_cents');
    }

    private function state(Invoice $invoice): string
    {
        return (string) Invoice::query()->whereKey($invoice->id)->value('status');
    }

    public function test_prices_by_age_and_date_and_reduced_places(): void
    {
        $this->event->prices()->create(['role_key' => 'child', 'max_age' => 9, 'price_cents' => 40000, 'sort' => 1]);
        $this->event->prices()->create(['role_key' => 'child', 'until_date' => '2027-01-31', 'price_cents' => 43000, 'sort' => 2]);
        $young = $this->child('Ann', '2018-08-02'); // 8 at the start
        $early = $this->child('Ben', '2015-01-01');
        $late = $this->child('Cat', '2014-01-01');
        $late->forceFill(['registered_at' => '2027-03-01'])->save();
        $free = $this->child('Dan', '2013-01-01');
        $free->forceFill(['price_cents' => 6000, 'price_reason' => 'social'])->save();
        $event = Event::query()->with('roles', 'installments', 'prices')->findOrFail($this->event->id);
        $billing = app(Billing::class);
        $this->assertSame([40000, 43000, 45000, 6000], array_map(fn ($p) => $billing->price($event, $p->load('person')), [$young, $early, $late, $free]));

        // The reduced place (60) never costs more than 60 in total: deposit 60, balance 0.
        $base = fn (string $key) => $billing->base($event, $event->installments->firstWhere('key', $key) ?? throw new RuntimeException, $free);
        $this->assertSame([6000, 0], [$base('deposit'), $base('balance')]);
    }

    public function test_issued_invoices_cannot_be_changed_or_deleted(): void
    {
        $this->child('Ann', '2016-01-01');
        $invoice = $this->invoice('deposit');
        foreach ([
            fn () => $invoice->forceFill(['total_cents' => 1])->save(),
            fn () => Invoice::query()->findOrFail($invoice->id)->lines()->firstOrFail()->forceFill(['amount_cents' => 1])->save(),
            fn () => Invoice::query()->findOrFail($invoice->id)->delete(),
        ] as $change) {
            try {
                $change();
                $this->fail('an issued invoice was changed');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('cannot', $e->getMessage());
            }
        }
        $this->assertSame(10000, (int) Invoice::query()->whereKey($invoice->id)->value('total_cents'));
    }

    public function test_overpayment_becomes_a_credit_with_refund_proposal(): void
    {
        $this->child('Ann', '2016-01-01');
        $invoice = $this->invoice('deposit');
        app(Payments::class)->record($invoice, 13000, Carbon::now());
        $this->assertSame(['paid', 10000], [$this->state($invoice), $this->cents($invoice)]);
        $credit = Payment::query()->where('match', 'credit')->firstOrFail();
        $this->assertSame([3000, $this->household->id], [$credit->amount_cents, $credit->household_id]);
        $refund = Refund::query()->firstOrFail();
        $this->assertSame([3000, 'proposed'], [$refund->amount_cents, $refund->status]);
        $this->assertSame(13000, (int) Payment::query()->sum('amount_cents'), 'no money appears or disappears');

        // Used for the next invoice: the proposal is gone.
        $balance = $this->invoice('balance');
        app(Credits::class)->useFor($credit, $balance);
        $this->assertSame(['partially_paid', 3000], [$this->state($balance), $this->cents($balance)]);
        $this->assertSame('declined', $refund->refresh()->status);
        $this->expectException(RuntimeException::class);
        app(Credits::class)->markRefunded($refund, Carbon::now());
    }

    public function test_one_transfer_for_several_invoices(): void
    {
        $this->child('Ann', '2016-01-01');
        $deposit = $this->invoice('deposit');
        $balance = $this->invoice('balance');
        $csv = (string) tempnam(sys_get_temp_dir(), 'bank');
        file_put_contents($csv, "Date;Amount;Text\n15.01.2027;450,00;{$deposit->reference} und {$balance->reference}\n");
        $mapping = ['date' => 0, 'amount' => 1, 'reference' => 2];
        $result = app(Payments::class)->import($csv, 'bank.csv', $mapping);
        $this->assertSame(1, $result['matched']);
        $this->assertSame(['paid', 'paid'], [$this->state($deposit), $this->state($balance)]);
        $this->assertSame(45000, (int) Payment::query()->sum('amount_cents'));
        $this->assertSame(1, app(Payments::class)->import($csv, 'bank.csv', $mapping)['duplicates'], 'the split transfer is still recognised');
        unlink($csv);
    }

    public function test_invoice_number_is_not_found_inside_a_longer_number(): void
    {
        $this->child('Ann', '2016-01-01');
        $invoice = $this->invoice('deposit'); // R1
        $payment = Payment::query()->create(['amount_cents' => 10000, 'paid_on' => now(), 'reference' => 'invoice R12', 'match' => 'unmatched']);
        $this->assertNull(app(Payments::class)->match($payment)[0]);
        $payment->forceFill(['reference' => 'Rechnung '.$invoice->number.' danke'])->save();
        $this->assertTrue(app(Payments::class)->match($payment)[1]);
    }

    public function test_payment_confirms_the_place_and_changes_groups(): void
    {
        $applications = Group::query()->create(['name' => 'Applications', 'kind' => 'manual']);
        $camp = Group::query()->create(['name' => 'Camp 2027', 'kind' => 'manual']);
        $this->event->installments()->where('key', 'deposit')->firstOrFail()->update(['confirms_place' => true, 'paid_add_group_id' => $camp->id, 'paid_remove_group_id' => $applications->id]);
        $ann = $this->child('Ann', '2016-01-01');
        $person = Person::query()->findOrFail($ann->person_id);
        $person->groups()->attach($applications->id);
        $invoice = $this->invoice('deposit');
        app(Payments::class)->record($invoice, 4000, Carbon::now());
        $this->assertSame('registered', Participation::query()->whereKey($ann->id)->value('status'));
        app(Payments::class)->record($invoice, 6000, Carbon::now());
        $this->assertSame('confirmed', Participation::query()->whereKey($ann->id)->value('status'));
        $this->assertSame(['Camp 2027'], $person->groups()->pluck('name')->all());
    }

    public function test_cancelled_sibling_is_corrected_with_credit_note_new_invoice_and_refund(): void
    {
        $this->child('Ann', '2014-01-01');
        $ben = $this->child('Ben', '2016-01-01');
        $this->child('Cat', '2017-01-01');
        $deposit = $this->invoice('deposit');
        $balance = $this->invoice('balance');
        $this->assertSame([30000, 99000], [$deposit->total_cents, $balance->total_cents], '350 + 330 + 310');
        app(Payments::class)->record($balance, 99000, Carbon::now());

        app(Registrations::class)->cancel($ben->load('event'), 'ill'); // 198 days before: balance refundable, deposit not
        $proposals = app(Corrections::class)->proposals(Event::query()->findOrFail($this->event->id));
        $this->assertSame([[$balance->id, 99000, 68000]], array_map(fn ($p) => [$p['invoice']->id, $p['old'], $p['new']], $proposals), '350 + 330; the deposit stays');
        $this->assertSame('paid', $this->state($balance), 'nothing changes without a decision');

        $new = $this->corrected($balance);
        $this->assertNotNull($new);
        $this->assertSame([68000, 68000, 'paid'], [$new->total_cents, $new->paid_cents, $new->status]);
        $this->assertSame('corrected', $this->state($balance));
        $credit = Invoice::query()->where('type', 'credit_note')->firstOrFail();
        $this->assertSame([-99000, $balance->id], [$credit->total_cents, $credit->corrects_id]);
        $refund = Refund::query()->firstOrFail();
        $this->assertSame(31000, $refund->amount_cents, '990 - 680');
        $this->assertSame(99000, (int) Payment::query()->sum('amount_cents'));
        $this->assertSame(1, OutgoingMail::query()->where('subject', 'like', 'Payment received%')->count(), 'no second confirmation');

        app(Credits::class)->markRefunded($refund, Carbon::now());
        $this->assertSame('refunded', Payment::query()->whereKey($refund->payment_id)->value('match'));
        $this->assertSame([], app(Corrections::class)->proposals(Event::query()->findOrFail($this->event->id)), 'nothing left to correct');
    }

    public function test_late_cancellation_and_cancellation_fee(): void
    {
        $ann = $this->child('Ann', '2014-01-01');
        $balance = $this->invoice('balance');
        $this->travelTo(Carbon::parse('2027-07-10')); // 22 days before the start
        app(Registrations::class)->cancel($ann->load('event'));
        $this->assertSame([], app(Corrections::class)->proposals(Event::query()->findOrFail($this->event->id)), 'too late for a refund');

        $this->travelTo(Carbon::parse('2027-06-20')); // 42 days before
        $ben = $this->child('Ben', '2016-01-01');
        $this->event->update(['cancellation_fee_cents' => 2500]);
        $new = $this->corrected($balance) ?? throw new RuntimeException; // Ann (kept) + Ben (second child: cancelled siblings do not count for the discount)
        $this->assertSame(70000, $new->total_cents);
        app(Registrations::class)->cancel($ben->load('event'));
        $again = $this->corrected($new);
        $this->assertSame(37500, $again?->total_cents, '350 + fee 25');
    }

    public function test_everything_cancelled_gives_a_credit_note_and_refund_without_new_invoice(): void
    {
        $ann = $this->child('Ann', '2014-01-01');
        $balance = $this->invoice('balance');
        app(Payments::class)->record($balance, 10000, Carbon::now());
        app(Registrations::class)->cancel($ann->load('event'));
        $this->assertNull($this->corrected($balance));
        $this->assertSame('cancelled', $this->state($balance));
        $this->assertSame(10000, Refund::query()->firstOrFail()->amount_cents);
    }

    public function test_new_sibling_after_the_invoice_is_proposed(): void
    {
        $this->child('Ann', '2014-01-01');
        $deposit = $this->invoice('deposit');
        $this->child('Ben', '2016-01-01');
        $proposals = app(Corrections::class)->proposals(Event::query()->findOrFail($this->event->id));
        $this->assertSame([[$deposit->id, 10000, 20000]], array_map(fn ($p) => [$p['invoice']->id, $p['old'], $p['new']], $proposals));
    }

    public function test_membership_fee_once_per_year_also_as_deposit(): void
    {
        $type = MembershipType::query()->where('key', 'child')->firstOrFail();
        $type->update(['fee_cents' => 5000]);
        $ann = $this->child('Ann', '2014-01-01');
        $ben = $this->child('Ben', '2016-01-01');
        foreach ([$ann, $ben] as $p) {
            Person::query()->findOrFail($p->person_id)->memberships()->create(['membership_type_id' => $type->id, 'starts_on' => '2027-01-01']);
        }
        $fees = app(MembershipFees::class);
        $this->assertCount(1, $fees->pending(2027), 'one family');
        $invoice = app(Invoices::class)->issue($fees->draft(2027, $fees->pending(2027)->first() ?? collect()));
        $this->assertSame([10000, 2], [$invoice->total_cents, $invoice->lines()->count()]);
        $this->assertCount(0, $fees->pending(2027));
        $this->assertCount(1, $fees->pending(2028));

        // As deposit of an event of the same year it is not charged again, but counts for the balance.
        $this->event->installments()->create(['key' => 'fee', 'label' => ['en' => 'Fee'], 'kind' => 'member', 'membership_type_id' => $type->id, 'sort' => 0, 'discounts_apply' => false]);
        $event = Event::query()->with('installments', 'roles', 'prices')->findOrFail($this->event->id);
        $lines = app(Billing::class)->lines($event, $event->installments->firstWhere('key', 'fee') ?? throw new RuntimeException, collect([$ann, $ben]), 'en');
        $this->assertSame([], $lines);
        $this->assertSame(30000, app(Billing::class)->base($event, $event->installments->firstWhere('key', 'balance') ?? throw new RuntimeException, $ann), '450 - 50 - 100');
    }

    public function test_numbers_per_year(): void
    {
        Settings::set('invoice_number_per_year', '1');
        Settings::set('invoice_number_format', '{year}-{number}');
        $this->child('Ann', '2014-01-01');
        $first = $this->invoice('deposit');
        $second = $this->invoice('balance');
        $this->travelTo(Carbon::parse('2028-01-02'));
        $this->assertSame(['2027-1', '2027-2'], [$first->number, $second->number]);
        $fresh = Invoice::query()->create(['payer_name' => 'x', 'total_cents' => 100]);
        $this->assertSame('2028-1', app(Invoices::class)->issue($fresh)->number);
    }

    public function test_reminders_and_corrections_wait_for_approval(): void
    {
        $board = Person::factory()->create(['locale' => 'en']);
        $board->roleAssignments()->create(['role_id' => Role::query()->where('key', 'board')->value('id')]);
        $user = User::query()->create(['name' => 'B', 'email' => 'b@example.org', 'password' => 'x', 'person_id' => $board->id]);
        $ann = $this->child('Ann', '2014-01-01');
        $deposit = $this->invoice('deposit'); // due 29 January
        $engine = app(Engine::class);
        $this->travelTo(Carbon::parse('2027-01-27 10:00'));
        $engine->tick();
        $reminders = Automation::query()->where('key', 'payment_reminders')->firstOrFail();
        $run = $reminders->runs()->firstOrFail();
        $this->assertSame('pending_approval', $run->status);
        $sent = fn () => OutgoingMail::query()->where('subject', 'like', 'Friendly reminder%')->count();
        $this->assertSame(0, $sent());
        $engine->decide($run, $user, true);
        $this->assertSame(1, $sent());

        app(Registrations::class)->cancel($ann->load('event'));
        $this->event->installments()->where('key', 'deposit')->firstOrFail()->update(['refundable' => true]);
        $this->travelTo(Carbon::parse('2027-01-28 10:00'));
        $engine->tick();
        $correction = Automation::query()->where('key', 'invoice_corrections')->firstOrFail()->runs()->firstOrFail();
        $this->assertSame(['pending_approval', 'issued'], [$correction->status, $this->state($deposit)]);
        $engine->decide($correction, $user, true);
        $this->assertSame('cancelled', $this->state($deposit));
    }
}
