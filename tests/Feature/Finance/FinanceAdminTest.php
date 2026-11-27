<?php

namespace Tests\Feature\Finance;

use App\Filament\Pages\InvoiceSettingsPage;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Filament\Resources\Events\RelationManagers\ParticipationsRelationManager;
use App\Filament\Resources\MembershipTypes\Pages\ManageMembershipTypes;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Resources\Refunds\Pages\ListRefunds;
use App\Models\Event;
use App\Models\Household;
use App\Models\Invoice;
use App\Models\MembershipType;
use App\Models\Participation;
use App\Models\Payment;
use App\Models\Person;
use App\Models\Refund;
use App\Models\Role;
use App\Models\User;
use App\Support\Events\Registrations;
use App\Support\Finance\Billing;
use App\Support\Finance\Invoices;
use App\Support\Finance\Reminders;
use App\Support\Install\Defaults;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class FinanceAdminTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    private Person $guardian;

    private Participation $participation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2027-01-15 12:00'));
        app(Defaults::class)->install('en');
        app()->setLocale('en');
        $this->event = Event::query()->create(['title' => 'Camp', 'starts_at' => '2027-08-01 09:00', 'ends_at' => '2027-08-08 16:00', 'status' => 'open',
            'cancellation_fee_cents' => 1500, 'cancellation_text' => ['en' => 'Our conditions']]);
        $this->event->roles()->create(['role_key' => 'child', 'capacity' => 40, 'price_cents' => 45000]);
        $this->event->installments()->create(['key' => 'deposit', 'label' => ['en' => 'Deposit'], 'kind' => 'fixed', 'amount_cents' => 10000, 'due_days' => 14, 'refundable' => false, 'sort' => 1]);
        $this->event->installments()->create(['key' => 'balance', 'label' => ['en' => 'Balance'], 'kind' => 'rest', 'due_date' => '2027-06-01', 'refundable' => true, 'refund_until_days' => 30, 'sort' => 2]);
        $household = Household::query()->create(['name' => 'Ash']);
        $this->guardian = Person::factory()->create(['first_name' => 'Alex', 'last_name' => 'Ash', 'birth_date' => '1985-01-01', 'locale' => 'en']);
        $this->guardian->contactPoints()->create(['type' => 'email', 'value' => 'alex@example.org', 'is_primary' => true]);
        $household->members()->attach($this->guardian->id, ['role' => 'guardian']);
        $child = Person::factory()->create(['first_name' => 'Ann', 'last_name' => 'Ash', 'birth_date' => '2015-01-01']);
        $household->members()->attach($child->id, ['role' => 'child']);
        $child->guardians()->attach($this->guardian->id);
        $this->participation = app(Registrations::class)->register($this->event, $child, 'child');
    }

    private function actAs(string $role): User
    {
        auth()->forgetUser(); // roles are assigned by the system here
        $person = Person::factory()->create();
        $person->roleAssignments()->create(['role_id' => Role::query()->where('key', $role)->value('id')]);
        $user = User::query()->create(['name' => $role, 'email' => $role.'@example.org', 'password' => 'x', 'person_id' => $person->id]);
        $this->actingAs($user);

        return $user;
    }

    private function issued(string $key): Invoice
    {
        $installment = $this->event->installments()->where('key', $key)->firstOrFail();
        app(Invoices::class)->draftRun($this->event->fresh() ?? $this->event, $installment);

        return app(Invoices::class)->issue(Invoice::query()->where('installment_key', $key)->where('status', 'draft')->firstOrFail());
    }

    public function test_pages_are_open_for_finance_roles_only(): void
    {
        $this->withoutMiddleware(AuthenticateSession::class);
        $this->actAs('treasurer');
        foreach (['/admin/refunds', '/admin/membership-types', '/admin/events/'.$this->event->id.'/edit', '/admin/payments'] as $page) {
            $this->withSession(['two_factor_passed' => true])->get($page)->assertOk();
        }
    }

    public function test_pages_are_closed_for_camp_leaders(): void
    {
        $this->withoutMiddleware(AuthenticateSession::class);
        $this->actAs('camp_leader');
        $this->withSession(['two_factor_passed' => true])->get('/admin')->assertOk();
        foreach (['/admin/refunds', '/admin/membership-types', '/admin/payments'] as $page) {
            $this->get($page)->assertForbidden();
        }
    }

    public function test_reduced_place_is_for_finance_roles_and_changes_the_price(): void
    {
        $component = fn () => Livewire::test(ParticipationsRelationManager::class, ['ownerRecord' => $this->event, 'pageClass' => EditEvent::class]);
        $this->actAs('treasurer');
        $component()->callTableAction('price', $this->participation, data: ['price' => '50', 'reason' => 'social reasons'])->assertHasNoTableActionErrors();
        $fresh = $this->participation->fresh() ?? $this->participation;
        $this->assertSame(5000, $fresh->price_cents);
        $this->assertSame(5000, app(Billing::class)->price($this->event, $fresh));

        $component()->callTableAction('price', $this->participation, data: ['price' => '50', 'reason' => null])->assertHasTableActionErrors(['reason']);
        $component()->callTableAction('price', $this->participation, data: ['price' => '', 'reason' => ''])->assertHasNoTableActionErrors();
        $this->assertNull($this->participation->fresh()?->price_cents);

        $this->actAs('camp_leader');
        $component()->assertTableActionHidden('price', $this->participation);
    }

    public function test_cancelling_needs_a_reason_and_is_not_possible_through_the_status_form(): void
    {
        $this->actAs('administrator');
        $component = fn () => Livewire::test(ParticipationsRelationManager::class, ['ownerRecord' => $this->event, 'pageClass' => EditEvent::class]);
        $component()->callTableAction('edit', $this->participation, data: ['status' => 'cancelled'])->assertHasTableActionErrors(['status']);
        $component()->callTableAction('cancel', $this->participation, data: ['reason' => ''])->assertHasTableActionErrors(['reason']);
        $component()->callTableAction('cancel', $this->participation, data: ['reason' => 'moved away'])->assertHasNoTableActionErrors();
        $fresh = $this->participation->fresh();
        $this->assertSame('cancelled', $fresh?->status);
        $this->assertSame('moved away', $fresh->cancel_reason);
        $this->assertNotNull($fresh->cancelled_at);
    }

    public function test_split_credit_and_refund_through_the_admin_interface(): void
    {
        $this->actAs('treasurer');
        $deposit = $this->issued('deposit');
        $balance = $this->issued('balance');
        $payment = Payment::query()->create(['amount_cents' => 46000, 'paid_on' => '2027-01-14', 'method' => 'bank', 'match' => 'unmatched', 'payer_name' => 'Alex Ash', 'iban' => 'DE89370400440532013000']);

        Livewire::test(ListPayments::class)->callTableAction('split', $payment, data: ['parts' => [
            ['invoice_id' => $deposit->id, 'amount' => '100'], ['invoice_id' => $balance->id, 'amount' => '350'],
        ]])->assertHasNoTableActionErrors();
        $this->assertSame('paid', $deposit->fresh()?->status);
        $this->assertSame('paid', $balance->fresh()?->status);
        $rest = Payment::query()->whereNull('invoice_id')->sole();
        $this->assertSame(1000, $rest->amount_cents);
        $this->assertSame(46000, (int) Payment::query()->sum('amount_cents'));

        Livewire::test(ListPayments::class)->callTableAction('refund', $rest, data: ['reason' => 'too much'])->assertHasNoTableActionErrors();
        $refund = Refund::query()->sole();
        $this->assertSame('DE89370400440532013000', $refund->iban);
        Livewire::test(ListRefunds::class)->assertCanSeeTableRecords([$refund])->callTableAction('done', $refund, data: ['on' => '2027-01-15'])->assertHasNoTableActionErrors();
        $this->assertSame('done', $refund->fresh()?->status);
        $this->assertSame('refunded', $rest->fresh()?->match);
    }

    public function test_auditor_sees_refunds_but_cannot_change_them(): void
    {
        $credit = Payment::query()->create(['amount_cents' => 1000, 'paid_on' => '2027-01-14', 'method' => 'bank', 'match' => 'credit']);
        $refund = Refund::query()->create(['payment_id' => $credit->id, 'amount_cents' => 1000, 'reason' => 'x']);
        $this->actAs('auditor');
        Livewire::test(ListRefunds::class)->assertCanSeeTableRecords([$refund])->assertTableActionHidden('done', $refund)->assertTableActionHidden('keep', $refund);
        Livewire::test(ListPayments::class)->assertTableActionHidden('refund', $credit)->assertTableActionHidden('use_credit', $credit);
    }

    public function test_membership_type_fee(): void
    {
        $this->actAs('treasurer');
        $type = MembershipType::query()->create(['key' => 'club', 'name' => 'Club', 'pays_fee' => true, 'sort' => 50]);
        Livewire::test(ManageMembershipTypes::class)->callTableAction('edit', $type, data: ['fee_cents' => '25.50', 'pays_fee' => true, 'name' => 'Club'])->assertHasNoTableActionErrors();
        $this->assertSame(2550, $type->fresh()?->fee_cents);
    }

    public function test_numbers_per_year_need_the_year_in_the_format(): void
    {
        $this->actAs('treasurer');
        Livewire::test(InvoiceSettingsPage::class)->fillForm(['invoice_number_per_year' => true, 'invoice_number_format' => 'R{number}'])->call('save')->assertHasFormErrors(['invoice_number_format']);
        Livewire::test(InvoiceSettingsPage::class)->fillForm(['invoice_number_per_year' => true, 'invoice_number_format' => '{year}-{number}'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('2027-1', $this->issued('deposit')->number);
    }

    public function test_reminder_fee_is_off_by_default_and_an_own_invoice_otherwise(): void
    {
        Settings::set('invoice_overdue_days', '7,21');
        $invoice = $this->issued('deposit');
        $reminders = app(Reminders::class);
        $this->travelTo(Carbon::parse('2027-02-06 12:00'));
        $this->assertTrue($reminders->send($invoice, 'overdue', -7));
        $this->assertSame(1, Invoice::query()->count());

        Settings::set('invoice_overdue_fees', '0;5,50');
        $this->assertSame(0, $reminders->feeCents(7));
        $this->assertSame(550, $reminders->feeCents(21));
        $this->assertTrue($reminders->send($invoice, 'overdue', -21));
        $fee = Invoice::query()->where('fee_for_id', $invoice->id)->sole();
        $this->assertSame([550, 'issued'], [$fee->total_cents, $fee->status]);
        $this->assertFalse($reminders->send($invoice, 'overdue', -21), 'each reminder only once');
        $this->assertTrue($reminders->send($fee, 'overdue', -21));
        $this->assertSame(2, Invoice::query()->count(), 'no fee on a fee');
        $this->assertSame(10000, $invoice->fresh()?->total_cents);
    }

    public function test_portal_shows_the_cancellation_conditions_before_cancelling(): void
    {
        $user = User::query()->create(['name' => 'Alex', 'email' => 'alex@example.org', 'password' => 'x', 'person_id' => $this->guardian->id]);
        $child = $this->participation->person ?? $this->guardian;
        $this->actingAs($user)->get(route('portal.child', $child->public_id))->assertOk()
            ->assertSee('Our conditions')->assertSee('Deposit: not refundable.')->assertSee('Balance: refundable until 2 July 2027.')->assertSee('Cancellation fee: '.Invoice::money(1500));
        $this->travelTo(Carbon::parse('2027-07-03 12:00'));
        $this->actingAs($user)->get(route('portal.child', $child->public_id))->assertSee('Balance: no longer refundable.');
    }
}
