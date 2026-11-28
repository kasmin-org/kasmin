<?php

namespace Tests\Feature\Portal;

use App\Models\Account;
use App\Models\ExpenseClaim;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Accounting\Advances;
use App\Support\Accounting\ExpenseClaims;
use App\Support\Accounting\Periods;
use App\Support\Install\Defaults;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Expense claims in the portal (E1d.3): team members submit with a photo from the phone and see only their own. */
class ExpensesPortalTest extends TestCase
{
    use RefreshDatabase;

    private User $kim;

    private User $robin;

    protected function setUp(): void
    {
        parent::setUp();
        app(Defaults::class)->install('en');
        app()->setLocale('en');
        app(Periods::class)->create('This year', now()->subMonths(6)->toDateString(), now()->addMonths(6)->toDateString());
        Storage::fake('local');
        $this->kim = $this->member('Kim', 'Kale');
        $this->robin = $this->member('Robin', 'Rowe');
    }

    private function member(string $first, string $last, bool $team = true): User
    {
        $person = Person::factory()->create(['first_name' => $first, 'last_name' => $last, 'locale' => 'en', 'birth_date' => '1995-01-01']);
        $email = strtolower($first).'@example.org';
        $person->contactPoints()->create(['type' => 'email', 'value' => $email, 'purposes' => ['login'], 'is_primary' => true]);
        if ($team) {
            $person->roleAssignments()->create(['role_id' => Role::query()->where('key', 'counsellor')->value('id')]);
        }

        return User::query()->where('person_id', $person->id)->first() ?? User::query()->create(['name' => $first, 'email' => $email, 'password' => 'x', 'person_id' => $person->id]);
    }

    private function person(User $user): Person
    {
        return $user->person ?? throw new \LogicException('no person');
    }

    public function test_a_team_member_submits_a_receipt_from_the_phone_and_sees_its_status(): void
    {
        $this->actingAs($this->kim)->get('/portal')->assertOk()->assertSee('Expenses and advances');
        $page = $this->actingAs($this->kim)->get('/portal/expenses')->assertOk();
        $page->assertSee('enctype="multipart/form-data"', false)->assertSee('accept="image/*,application/pdf"', false)->assertSee('No IBAN saved yet.');

        $this->actingAs($this->kim)->post('/portal/expenses', [
            'date' => now()->subDay()->toDateString(), 'amount' => '61.70', 'purpose' => 'Craft material',
            'receipt' => UploadedFile::fake()->image('IMG_0001.jpg', 1200, 1600),
        ])->assertRedirect(route('portal.expenses'))->assertSessionHas('status', 'Your expense claim has been submitted.');

        $claim = ExpenseClaim::query()->sole();
        $this->assertSame([6170, 'submitted', $this->kim->person_id, $this->kim->id], [$claim->amount_cents, $claim->status, $claim->person_id, $claim->submitted_by_id]);
        Storage::disk('local')->assertExists((string) $claim->receipt_path);
        $this->actingAs($this->kim)->get('/portal/expenses')->assertSee('Craft material')->assertSee('61,70')->assertSee('submitted');
        $this->actingAs($this->kim)->get(route('portal.expenses.receipt', $claim->id))->assertOk()->assertHeader('Cache-Control', 'no-store, private');

        // Without a receipt nothing is submitted (a flat rate needs none).
        $this->actingAs($this->kim)->post('/portal/expenses', ['date' => now()->toDateString(), 'amount' => '5', 'purpose' => 'Glue'])->assertSessionHasErrors('receipt');
        Settings::set('expense_flat_rates', 'Travel allowance per event: 90');
        $this->actingAs($this->kim)->post('/portal/expenses', ['kind' => 'Travel allowance per event', 'date' => now()->toDateString(), 'quantity' => '1'])->assertSessionHasNoErrors();
        $this->assertSame(9000, ExpenseClaim::query()->where('flat', true)->sole()->amount_cents);
    }

    public function test_everybody_sees_only_their_own_claims_and_receipts(): void
    {
        $claims = app(ExpenseClaims::class);
        $own = $claims->submit($this->person($this->kim), ['date' => now()->toDateString(), 'amount_cents' => 1000, 'purpose' => 'Kims tape', 'receipt' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')]);
        $other = $claims->submit($this->person($this->robin), ['date' => now()->toDateString(), 'amount_cents' => 2000, 'purpose' => 'Robins paint', 'receipt' => UploadedFile::fake()->create('b.pdf', 10, 'application/pdf')]);

        $this->actingAs($this->kim)->get('/portal/expenses')->assertOk()->assertSee('Kims tape')->assertDontSee('Robins paint');
        $this->actingAs($this->kim)->get(route('portal.expenses.receipt', $other->id))->assertNotFound();
        $this->actingAs($this->kim)->post(route('portal.expenses.waive', $other->id))->assertNotFound();
        $this->assertFalse($other->refresh()->waived);
        $this->actingAs($this->kim)->post(route('portal.expenses.waive', $own->id))->assertRedirect(route('portal.expenses'));
        $this->assertTrue($own->refresh()->waived);

        // Not for people without a role in the team, and not with a login link from a mail only.
        $guardian = $this->member('Alex', 'Ash', team: false);
        $this->actingAs($guardian)->get('/portal')->assertOk()->assertDontSee('Expenses and advances');
        $this->actingAs($guardian)->get('/portal/expenses')->assertNotFound();
        $this->actingAs($this->kim)->withSession(['login_scope' => 'limited'])->get('/portal/expenses')->assertRedirect(route('step-up'));
    }

    public function test_the_iban_is_checked_and_shown_masked(): void
    {
        $this->actingAs($this->kim)->post('/portal/expenses/iban', ['iban' => 'AT48 3200 0000 1234 5865'])->assertSessionHasErrors('iban');
        $this->actingAs($this->kim)->post('/portal/expenses/iban', ['iban' => 'AT48 3200 0000 1234 5864'])->assertSessionHas('status', 'The IBAN has been saved.');
        $this->assertSame('AT483200000012345864', $this->person($this->kim)->refresh()->payout_iban);
        $this->actingAs($this->kim)->get('/portal/expenses')->assertSee('AT** **** **** **** 5864')->assertDontSee('AT483200000012345864')->assertDontSee('3200 0000');
    }

    public function test_receipts_against_an_open_advance_can_be_chosen(): void
    {
        $advance = app(Advances::class)->handOut($this->person($this->kim), 30000, Account::system('cash'), now()->subDays(3), 'Camp cash');
        $page = $this->actingAs($this->kim)->get('/portal/expenses')->assertOk()->assertSee('Against my advance')->assertSee('still to account for: 300,00');
        $page->assertSee('Camp cash');
        $this->actingAs($this->kim)->post('/portal/expenses', [
            'date' => now()->toDateString(), 'amount' => '120', 'purpose' => 'Food', 'advance_id' => $advance->id,
            'receipt' => UploadedFile::fake()->image('food.png'),
        ])->assertSessionHasNoErrors();
        $this->assertSame($advance->id, ExpenseClaim::query()->sole()->advance_id);
        // Somebody else's advance cannot be used.
        $this->actingAs($this->robin)->post('/portal/expenses', [
            'date' => now()->toDateString(), 'amount' => '10', 'purpose' => 'Food', 'advance_id' => $advance->id,
            'receipt' => UploadedFile::fake()->image('food.png'),
        ])->assertSessionHasErrors('claim');
        $this->assertSame(1, ExpenseClaim::query()->count());
    }
}
