<?php

namespace Tests\Feature\Accounting;

use App\Filament\Resources\Advances\Pages\ManageAdvances;
use App\Filament\Resources\ExpenseClaims\Pages\ManageExpenseClaims;
use App\Models\Account;
use App\Models\Advance;
use App\Models\ApiToken;
use App\Models\ExpenseClaim;
use App\Models\JournalEntry;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Accounting\Balances;
use App\Support\Accounting\ExpenseClaims;
use App\Support\Accounting\Periods;
use App\Support\Install\Defaults;
use App\Support\Settings;
use Filament\Http\Middleware\AuthenticateSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** The admin pages of expense claims and advances and the REST API (E1d.3). */
class ExpenseClaimsAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $treasurer;

    private Person $kim;

    protected function setUp(): void
    {
        parent::setUp();
        app(Defaults::class)->install('en');
        app()->setLocale('en');
        Settings::set('organisation_iban', 'AT611904300234573201');
        app(Periods::class)->create('This year', now()->subMonths(6)->toDateString(), now()->addMonths(6)->toDateString());
        $this->withoutMiddleware(AuthenticateSession::class);
        $this->treasurer = $this->user('treasurer', 'Toni');
        $this->kim = Person::factory()->create(['first_name' => 'Kim', 'last_name' => 'Kale', 'locale' => 'en', 'birth_date' => '1990-01-01']);
        $this->kim->contactPoints()->create(['type' => 'email', 'value' => 'kim@example.org', 'is_primary' => true]);
    }

    private function user(string $role, string $name): User
    {
        $person = Person::factory()->create(['first_name' => $name, 'last_name' => 'User', 'locale' => 'en']);
        $person->roleAssignments()->create(['role_id' => Role::query()->where('key', $role)->value('id')]);

        return User::query()->create(['name' => $name, 'email' => strtolower($name).'@example.org', 'password' => 'x', 'person_id' => $person->id]);
    }

    private function claim(int $cents = 6170, string $purpose = 'Craft material'): ExpenseClaim
    {
        return app(ExpenseClaims::class)->submit($this->kim, ['date' => now()->toDateString(), 'amount_cents' => $cents, 'purpose' => $purpose]);
    }

    public function test_the_pages_open_for_finance_and_auditors_only_read(): void
    {
        $this->claim();
        $auditor = $this->user('auditor', 'Audrey');
        $counsellor = $this->user('counsellor', 'Cara');
        $this->actingAs($this->treasurer)->withSession(['two_factor_passed' => true]);
        $this->get('/admin/expense-claims')->assertOk()->assertSee('Craft material')->assertSee('Enter expense claim');
        $this->get('/admin/advances')->assertOk()->assertSee('Hand out advance');

        $this->actingAs($auditor)->withSession(['two_factor_passed' => true]);
        $this->get('/admin/expense-claims')->assertOk()->assertSee('Craft material')->assertDontSee('Enter expense claim');
        $this->actingAs($counsellor)->get('/admin/expense-claims')->assertForbidden();
    }

    public function test_enter_approve_reject_and_pay_out_with_a_sepa_file(): void
    {
        Storage::fake('local');
        $this->actingAs($this->treasurer)->withSession(['two_factor_passed' => true]);
        Livewire::test(ManageExpenseClaims::class)->callAction('enter', [
            'person_id' => $this->kim->id, 'date' => now()->toDateString(), 'amount_cents' => '25.00', 'purpose' => 'Train ticket',
            'account_id' => Account::query()->where('name', 'Travel')->value('id'), 'iban' => 'AT48 3200 0000 1234 5864',
        ])->assertHasNoActionErrors();
        $entered = ExpenseClaim::query()->sole();
        $this->assertSame([2500, 'Travel', $this->treasurer->id], [$entered->amount_cents, $entered->account?->name, $entered->submitted_by_id]);
        $this->assertSame('AT483200000012345864', $this->kim->refresh()->payout_iban);

        $other = $this->claim(1000, 'Private snacks');
        Livewire::test(ManageExpenseClaims::class)->callTableAction('reject', $other, ['reason' => 'Not for the camp'])->assertHasNoTableActionErrors();
        $this->assertSame('rejected', $other->refresh()->status);

        Livewire::test(ManageExpenseClaims::class)->callTableAction('approve', $entered, ['account_id' => Account::query()->where('name', 'Travel')->value('id')])->assertHasNoTableActionErrors();
        $this->assertSame('approved', $entered->refresh()->status);
        Livewire::test(ManageExpenseClaims::class)->assertTableActionVisible('qr', $entered);

        Livewire::test(ManageExpenseClaims::class)->callTableBulkAction('pay_selected', [$entered], ['account_id' => Account::system('bank')->id, 'paid_on' => now()->toDateString()])
            ->assertHasNoTableBulkActionErrors()->assertFileDownloaded();
        $this->assertSame('paid', $entered->refresh()->status);
        $this->assertSame(-2500, app(Balances::class)->account(Account::system('bank'), now()));
    }

    public function test_four_eyes_in_the_admin_interface(): void
    {
        Settings::set('expense_four_eyes', '1');
        $this->actingAs($this->treasurer)->withSession(['two_factor_passed' => true]);
        $claim = app(ExpenseClaims::class)->submit($this->kim, ['date' => now()->toDateString(), 'amount_cents' => 900, 'purpose' => 'Glue'], $this->treasurer);
        Livewire::test(ManageExpenseClaims::class)->callTableAction('approve', $claim, ['account_id' => ExpenseClaims::defaultAccount()?->id]);
        $this->assertSame('submitted', $claim->refresh()->status);
        $this->assertSame(0, JournalEntry::query()->count());
    }

    public function test_an_advance_is_handed_out_and_settled_in_the_admin_interface(): void
    {
        $this->actingAs($this->treasurer)->withSession(['two_factor_passed' => true]);
        Livewire::test(ManageAdvances::class)->callAction('hand_out', [
            'person_id' => $this->kim->id, 'amount_cents' => '300.00', 'account_id' => Account::system('cash')->id, 'paid_on' => now()->toDateString(), 'purpose' => 'Camp shopping',
        ])->assertHasNoActionErrors();
        $advance = Advance::query()->sole();
        $receipt = app(ExpenseClaims::class)->submit($this->kim, ['date' => now()->toDateString(), 'amount_cents' => 22750, 'purpose' => 'Food', 'advance_id' => $advance->id]);
        app(ExpenseClaims::class)->approve($receipt, $this->treasurer);
        Livewire::test(ManageAdvances::class)->callTableAction('settle', $advance, ['returned' => '72.50', 'account_id' => Account::system('cash')->id, 'date' => now()->toDateString()])
            ->assertHasNoTableActionErrors();
        $this->assertTrue($advance->refresh()->isSettled());
        $this->assertSame(7250, $advance->returned_cents);
        $this->assertSame(-22750, app(Balances::class)->account(Account::system('cash'), now()));
    }

    public function test_the_receipt_is_only_for_finance_roles(): void
    {
        Storage::fake('local');
        $claim = app(ExpenseClaims::class)->submit($this->kim, ['date' => now()->toDateString(), 'amount_cents' => 900, 'purpose' => 'Glue', 'receipt' => UploadedFile::fake()->image('r.jpg')]);
        $counsellor = $this->user('counsellor', 'Cara');
        $this->actingAs($this->treasurer)->withSession(['two_factor_passed' => true])->get(route('admin.expense-claims.receipt', $claim->id))->assertOk();
        $this->actingAs($counsellor)->get(route('admin.expense-claims.receipt', $claim->id))->assertForbidden();
    }

    public function test_the_api_lists_enters_approves_and_pays_with_the_sepa_file_and_masks_the_iban(): void
    {
        $admin = $this->user('administrator', 'Ada');
        $write = ApiToken::issue($admin, 'test', ['finance:read', 'finance:write'])['plain'];
        $read = ApiToken::issue($admin, 'read', ['finance:read'])['plain'];
        $created = $this->withToken($write)->postJson('/api/v1/expense-claims', ['person' => $this->kim->public_id, 'date' => now()->toDateString(), 'amount' => 61.7, 'purpose' => 'Craft material', 'iban' => 'AT483200000012345864'])
            ->assertCreated()->json('data');
        $this->assertSame(['submitted', 61.7, 'AT** **** **** **** 5864', true], [$created['status'], $created['amount'], $created['iban'], $created['entered_centrally']]);
        $this->withToken($read)->postJson('/api/v1/expense-claims/'.$created['id'].'/approve')->assertForbidden();
        $this->withToken($write)->postJson('/api/v1/expense-claims/'.$created['id'].'/approve')->assertOk()->assertJsonPath('data.status', 'approved');
        $this->withToken($write)->postJson('/api/v1/expense-claims/'.$created['id'].'/approve')->assertStatus(422);
        $this->withToken($read)->getJson('/api/v1/expense-claims?status=approved')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonMissing(['iban' => 'AT483200000012345864']);

        $paid = $this->withToken($write)->postJson('/api/v1/expense-claims/pay', ['claims' => [$created['id']], 'account' => 'bank', 'date' => now()->toDateString()])->assertOk();
        $this->assertSame('paid', $paid->json('data.0.status'));
        $this->assertStringContainsString('<NbOfTxs>1</NbOfTxs>', (string) $paid->json('sepa_xml'));

        $advance = $this->withToken($write)->postJson('/api/v1/advances', ['person' => $this->kim->public_id, 'amount' => 50, 'account' => 'cash', 'date' => now()->toDateString()])->assertCreated()->json('data');
        $this->assertSame([50, 50, 'cash'], [$advance['amount'], $advance['open'], $advance['kind']]);
        $this->withToken($write)->postJson('/api/v1/advances/'.$advance['id'].'/settle', ['returned' => 20, 'account' => 'cash', 'date' => now()->toDateString()])->assertStatus(422);
        $this->withToken($write)->postJson('/api/v1/advances/'.$advance['id'].'/settle', ['returned' => 50, 'account' => 'cash', 'date' => now()->toDateString()])->assertOk()->assertJsonPath('data.open', 0);
        $this->withToken($read)->getJson('/api/v1/advances?settled=1')->assertOk()->assertJsonPath('meta.total', 1);
    }
}
