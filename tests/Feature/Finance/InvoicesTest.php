<?php

namespace Tests\Feature\Finance;

use App\Filament\Pages\OrganisationSettingsPage;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Models\Event;
use App\Models\Household;
use App\Models\Invoice;
use App\Models\OutgoingMail;
use App\Models\Participation;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Finance\InvoicePdf;
use App\Support\Finance\Invoices;
use App\Support\Finance\Payments;
use App\Support\Finance\Reminders;
use App\Support\Finance\SiblingDiscounts;
use App\Support\Install\Defaults;
use App\Support\Install\MailTemplateDefaults;
use App\Support\Settings;
use Filament\Http\Middleware\AuthenticateSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class InvoicesTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    private Household $household;

    private Person $guardian;

    protected function setUp(): void
    {
        parent::setUp();
        app(Defaults::class)->installRoles();
        app(MailTemplateDefaults::class)->install();
        app()->setLocale('en');
        Settings::set('organisation_name', 'Example Camp');
        Settings::set('organisation_iban', 'AT61 1904 3002 3457 3201');
        Settings::set('organisation_bic', 'BKAUATWW');

        $this->event = Event::query()->create([
            'title' => 'Summer camp 2027', 'starts_at' => now()->addMonths(6), 'ends_at' => now()->addMonths(6)->addWeek(), 'status' => 'open',
            'sibling_mode' => 'staggered', 'sibling_amount_cents' => 2000, 'sibling_cap_position' => 3,
        ]);
        $this->event->roles()->create(['role_key' => 'child', 'capacity' => 40, 'price_cents' => 45000, 'counts_as_sibling' => true]);
        $this->event->roles()->create(['role_key' => 'junior_counsellor', 'capacity' => 6, 'price_cents' => 45000, 'role_discount_cents' => 15000, 'counts_as_sibling' => true]);
        $this->event->installments()->create(['key' => 'deposit', 'label' => ['en' => 'Deposit', 'de' => 'Anzahlung'], 'kind' => 'fixed', 'amount_cents' => 10000, 'due_days' => 14, 'discounts_apply' => false]);
        $this->event->installments()->create(['key' => 'balance', 'label' => ['en' => 'Balance', 'de' => 'Restbetrag'], 'kind' => 'rest', 'due_date' => now()->addMonths(4)->toDateString(), 'discounts_apply' => true]);

        $this->household = Household::query()->create(['name' => 'Example', 'street' => 'Main street 1', 'postal_code' => '1010', 'city' => 'Vienna']);
        $this->guardian = Person::factory()->create(['first_name' => 'Alex', 'last_name' => 'Example', 'birth_date' => '1985-01-01', 'locale' => 'en']);
        $this->guardian->contactPoints()->create(['type' => 'email', 'value' => 'alex@example.org', 'is_primary' => true]);
        $this->household->members()->attach($this->guardian->id, ['role' => 'guardian']);
    }

    private function child(string $name, string $birth, string $role = 'child'): Participation
    {
        $child = Person::factory()->create(['first_name' => $name, 'last_name' => 'Example', 'birth_date' => $birth]);
        $child->guardians()->attach($this->guardian->id);
        $this->household->members()->attach($child->id, ['role' => 'child']);

        return Participation::query()->create(['event_id' => $this->event->id, 'person_id' => $child->id, 'role_key' => $role, 'status' => 'registered', 'registered_at' => now()]);
    }

    public function test_staggered_sibling_discount_oldest_pays_full_and_cap(): void
    {
        $a = $this->child('Ann', '2014-01-01');
        $b = $this->child('Ben', '2016-01-01');
        $c = $this->child('Cat', '2017-01-01');
        $d = $this->child('Dan', '2018-01-01');
        $result = app(SiblingDiscounts::class)->compute($this->event->load('roles'), collect([$d, $b, $a, $c])->each->load('person'));

        $this->assertSame([0, 2000, 4000, 4000], [$result[$a->id]['discount'], $result[$b->id]['discount'], $result[$c->id]['discount'], $result[$d->id]['discount']]);
    }

    public function test_role_discount_replaces_sibling_discount_but_counts_as_sibling(): void
    {
        $a = $this->child('Ann', '2010-01-01', 'junior_counsellor');
        $b = $this->child('Ben', '2015-01-01');
        $result = app(SiblingDiscounts::class)->compute($this->event->load('roles'), collect([$a, $b])->each->load('person'));

        $this->assertSame(['role', 15000], [$result[$a->id]['kind'], $result[$a->id]['discount']]);
        $this->assertSame(['sibling', 2000, 2], [$result[$b->id]['kind'], $result[$b->id]['discount'], $result[$b->id]['position']]);
    }

    public function test_all_siblings_mode_needs_minimum(): void
    {
        $this->event->update(['sibling_mode' => 'all', 'sibling_amount_cents' => 1500, 'sibling_min_children' => 3]);
        $this->event->refresh();
        $a = $this->child('Ann', '2014-01-01');
        $b = $this->child('Ben', '2016-01-01');
        $two = app(SiblingDiscounts::class)->compute($this->event->load('roles'), collect([$a, $b])->each->load('person'));
        $this->assertSame([0, 0], array_column($two, 'discount'));

        $c = $this->child('Cat', '2017-01-01');
        $three = app(SiblingDiscounts::class)->compute($this->event->load('roles'), collect([$a, $b, $c])->each->load('person'));
        $this->assertSame([1500, 1500, 1500], (array_column($three, 'discount')));
    }

    public function test_invoice_run_deposit_then_balance_with_discounts_only_on_balance(): void
    {
        $this->child('Ann', '2014-01-01');
        $this->child('Ben', '2016-01-01');
        $this->assertSame(1, $this->draftRun('deposit'));
        $this->assertSame(0, $this->draftRun('deposit'), 'run is idempotent');
        $deposit = Invoice::query()->where('installment_key', 'deposit')->firstOrFail();
        $this->assertSame(20000, $deposit->total_cents);
        $this->assertSame($this->guardian->id, $deposit->payer_id);

        $this->draftRun('balance');
        $balance = Invoice::query()->where('installment_key', 'balance')->firstOrFail();
        // 2 × (450 − 100) − 20 sibling discount
        $this->assertSame(68000, $balance->total_cents);
        $this->assertSame([35000, 33000], $balance->lines->pluck('amount_cents')->all());
    }

    public function test_issue_assigns_number_and_valid_rf_reference(): void
    {
        $this->child('Ann', '2014-01-01');
        Settings::set('invoice_next_number', '516');
        $invoices = app(Invoices::class);
        $invoice = $this->issuedDeposit();

        $this->assertSame('R516', $invoice->number);
        $this->assertSame('issued', $invoice->status);
        $this->assertTrue(self::validRf((string) $invoice->reference), (string) $invoice->reference);
        $this->assertSame('RF18539007547034', Invoices::creditorReference('539007547034'), 'ISO 11649 example');
        $this->assertSame('517', Settings::get('invoice_next_number'));
    }

    public function test_pdf_and_epc_payload(): void
    {
        $this->child('Ann', '2014-01-01');
        $invoices = app(Invoices::class);
        $invoice = $this->issuedDeposit();

        $payload = (string) InvoicePdf::epcPayload($invoice);
        $lines = explode("\n", $payload);
        $this->assertSame(['BCD', '002', '1', 'SCT', 'BKAUATWW', 'Example Camp', 'AT611904300234573201', 'EUR100.00'], array_slice($lines, 0, 8));
        $this->assertSame($invoice->reference, $lines[9]);

        $pdf = app(InvoicePdf::class)->render($invoice);
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertSame('Invoice-'.$invoice->number.'.pdf', app(InvoicePdf::class)->fileName($invoice));
    }

    public function test_send_queues_invoice_mail_with_pdf(): void
    {
        $this->child('Ann', '2014-01-01');
        $invoices = app(Invoices::class);
        $invoice = $this->issuedDeposit();

        $this->assertTrue($invoices->send($invoice));
        $mail = OutgoingMail::query()->latest('id')->firstOrFail();
        $this->assertSame('alex@example.org', $mail->to_address);
        $this->assertStringContainsString($invoice->number.'', $mail->subject);
        $this->assertStringContainsString((string) $invoice->reference, $mail->html);
        $this->assertEquals([['invoice_id' => $invoice->id, 'paid' => false]], $mail->attachments); // MySQL sorts JSON keys
    }

    public function test_bank_import_matches_by_reference_and_confirms_payment(): void
    {
        $this->child('Ann', '2014-01-01');
        $invoices = app(Invoices::class);
        $invoice = $this->issuedDeposit();

        $csv = tempnam(sys_get_temp_dir(), 'bank');
        file_put_contents($csv, "Date;Amount;Text;Name;IBAN\n"
            .now()->format('d.m.Y').";100,00;Payment {$invoice->reference} thanks;Alex Example;AT02 2011 1000 0000 1234\n"
            .now()->format('d.m.Y').";-30,00;Bank fee;;\n"
            .now()->format('d.m.Y').";55,00;something else;Someone;\n");
        $mapping = ['date' => 0, 'amount' => 1, 'reference' => 2, 'name' => 3, 'iban' => 4];
        $result = app(Payments::class)->import($csv, 'bank.csv', $mapping);

        $this->assertSame(['imported' => 2, 'matched' => 1, 'suggested' => 0, 'duplicates' => 0], $result);
        $this->assertSame('paid', $invoice->fresh()?->status);
        $confirmation = OutgoingMail::query()->where('subject', 'like', 'Payment received%')->firstOrFail();
        $this->assertEquals([['invoice_id' => $invoice->id, 'paid' => true]], $confirmation->attachments); // MySQL sorts JSON keys

        $again = app(Payments::class)->import($csv, 'bank.csv', $mapping);
        $this->assertSame(2, $again['duplicates']);
        unlink($csv);
    }

    public function test_partial_payment_and_suggestion_by_amount_and_name(): void
    {
        $this->child('Ann', '2014-01-01');
        $invoices = app(Invoices::class);
        $invoice = $this->issuedDeposit();

        app(Payments::class)->record($invoice, 4000, Carbon::now());
        $this->assertSame(['partially_paid', 6000], [$invoice->fresh()?->status, $invoice->fresh()?->openCents()]);

        $csv = tempnam(sys_get_temp_dir(), 'bank');
        file_put_contents($csv, "Date;Amount;Text;Name\n".now()->format('d.m.Y').";60,00;camp;EXAMPLE ALEX\n");
        $result = app(Payments::class)->import($csv, 'bank.csv', ['date' => 0, 'amount' => 1, 'reference' => 2, 'name' => 3]);
        $this->assertSame(1, $result['suggested']);
        unlink($csv);
    }

    public function test_cancel_creates_credit_note(): void
    {
        $this->child('Ann', '2014-01-01');
        $invoices = app(Invoices::class);
        $invoice = $this->issuedDeposit();

        $credit = $invoices->cancel($invoice, 'cancelled registration');
        $this->assertSame(['credit_note', -10000, $invoice->id], [$credit->type, $credit->total_cents, $credit->corrects_id]);
        $this->assertNotSame($invoice->number, $credit->number);
        $this->assertSame('cancelled', $invoice->fresh()?->status);
    }

    public function test_reminders_before_due_and_overdue_are_sent_once(): void
    {
        $this->child('Ann', '2014-01-01');
        $invoices = app(Invoices::class);
        $invoice = $this->issuedDeposit();
        $reminders = app(Reminders::class);

        $this->assertSame(0, $reminders->run(), 'nothing due yet');
        $this->travel(12)->days();
        $this->assertSame(1, $reminders->run());
        $this->assertSame(0, $reminders->run(), 'only once');
        $this->assertSame(1, OutgoingMail::query()->where('subject', 'like', 'Friendly reminder%')->count());

        $this->travel(10)->days(); // 8 days overdue
        $this->assertSame(1, $reminders->run());
        $this->assertSame(1, OutgoingMail::query()->where('subject', 'like', 'Payment reminder%')->count());

        app(Payments::class)->record($invoice->fresh() ?? $invoice, 10000, Carbon::now());
        $this->travel(20)->days();
        $this->assertSame(0, $reminders->run(), 'paid invoices get no reminders');
    }

    public function test_admin_pages_respect_finance_roles(): void
    {
        $this->child('Ann', '2014-01-01');
        $invoice = $this->issuedDeposit();
        $this->withoutMiddleware(AuthenticateSession::class);
        $make = function (string $role): User {
            $person = Person::factory()->create();
            $person->roleAssignments()->create(['role_id' => Role::query()->where('key', $role)->value('id')]);

            return User::query()->create(['name' => $role, 'email' => $role.'@example.org', 'password' => 'x', 'person_id' => $person->id]);
        };
        $treasurer = $make('treasurer');
        $leader = $make('camp_leader');

        foreach (['/admin/invoices', '/admin/invoices/'.$invoice->id, '/admin/payments', '/admin/settings/organisation', '/admin/events/'.$this->event->id.'/edit'] as $page) {
            $this->actingAs($treasurer)->withSession(['two_factor_passed' => true])->get($page)->assertOk();
        }
        $this->actingAs($treasurer)->withSession(['two_factor_passed' => true])->get('/admin/invoices/'.$invoice->id.'/pdf')
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($leader)->withSession(['two_factor_passed' => true])->get('/admin/invoices')->assertForbidden();
        $this->actingAs($leader)->withSession(['two_factor_passed' => true])->get('/admin/invoices/'.$invoice->id.'/pdf')->assertForbidden();
    }

    public function test_invoice_run_issue_payment_and_settings_through_the_admin_interface(): void
    {
        $this->child('Ann', '2014-01-01');
        $person = Person::factory()->create();
        $person->roleAssignments()->create(['role_id' => Role::query()->where('key', 'treasurer')->value('id')]);
        $this->actingAs(User::query()->create(['name' => 't', 'email' => 't@example.org', 'password' => 'x', 'person_id' => $person->id]));
        $deposit = $this->event->installments()->where('key', 'deposit')->firstOrFail();

        Livewire::test(ListInvoices::class)->callAction('run', data: ['event_id' => $this->event->id, 'installment_id' => $deposit->id])->assertHasNoActionErrors();
        $invoice = Invoice::query()->firstOrFail();
        $this->assertSame('draft', $invoice->status);

        Livewire::test(ViewInvoice::class, ['record' => $invoice->id])->callAction('issue')->assertHasNoActionErrors();
        $this->assertSame('issued', $invoice->fresh()?->status);
        $this->assertSame(1, OutgoingMail::query()->where('subject', 'like', 'Invoice%')->count());

        Livewire::test(ViewInvoice::class, ['record' => $invoice->id])->callAction('payment', data: ['amount' => '100.00', 'paid_on' => now()->toDateString(), 'method' => 'cash'])->assertHasNoActionErrors();
        $this->assertSame('paid', Invoice::query()->findOrFail($invoice->id)->status);

        Livewire::test(OrganisationSettingsPage::class)->fillForm(['organisation_iban' => 'AT00 1234'])->call('save')->assertHasFormErrors(['organisation_iban']);
        Livewire::test(OrganisationSettingsPage::class)->fillForm(['organisation_iban' => 'de89 3704 0044 0532 0130 00', 'invoice_number_format' => '{year}-{number}'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('DE89370400440532013000', Settings::get('organisation_iban'));
    }

    public function test_portal_shows_own_invoices_only(): void
    {
        $this->child('Ann', '2014-01-01');
        $invoice = $this->issuedDeposit();
        $user = User::query()->create(['name' => 'Alex', 'email' => 'alex@example.org', 'password' => 'x', 'person_id' => $this->guardian->id]);
        $other = User::query()->create(['name' => 'Other', 'email' => 'other@example.org', 'password' => 'x', 'person_id' => Person::factory()->create()->id]);

        $this->actingAs($user)->get('/portal')->assertOk()->assertSee((string) $invoice->number)->assertSee((string) $invoice->reference);
        $this->actingAs($user)->get('/portal/invoices/'.$invoice->id.'.pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($other)->get('/portal/invoices/'.$invoice->id.'.pdf')->assertNotFound();
        $this->actingAs($other)->get('/portal')->assertDontSee((string) $invoice->reference);
    }

    private function draftRun(string $installment): int
    {
        $event = $this->event->fresh(['roles', 'installments']) ?? $this->event;

        return app(Invoices::class)->draftRun($event, $event->installments->firstWhere('key', $installment) ?? throw new \RuntimeException('no instalment'));
    }

    private function issuedDeposit(): Invoice
    {
        $this->draftRun('deposit');

        return app(Invoices::class)->issue(Invoice::query()->firstOrFail());
    }

    private static function validRf(string $rf): bool
    {
        $s = substr($rf, 4).substr($rf, 0, 4);
        $n = '';
        foreach (str_split($s) as $ch) {
            $n .= ctype_alpha($ch) ? (string) (ord($ch) - 55) : $ch;
        }

        return bcmod($n, '97') === '1';
    }
}
