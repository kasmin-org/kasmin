<?php

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\ApiToken;
use App\Models\AuditEvent;
use App\Models\CostCenter;
use App\Models\Fund;
use App\Models\JournalEntry;
use App\Models\Period;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Accounting\Balances;
use App\Support\Accounting\Journal;
use App\Support\Accounting\Money;
use App\Support\Accounting\Periods;
use App\Support\Install\Defaults;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * X01 for the accounting (E1): accounts, cost centres, funds, periods with keys, closing and reopening, bookings and
 * reports via REST, with the rules of the admin interface.
 */
class AccountingApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        app(Defaults::class)->install('en');
        app()->setLocale('en');
        $person = Person::factory()->create(['locale' => 'en', 'birth_date' => '1980-01-01']);
        $person->roleAssignments()->create(['role_id' => Role::query()->where('key', 'administrator')->value('id')]);
        $this->user = User::query()->create(['name' => 'Owner', 'email' => 'owner@example.org', 'password' => 'x', 'person_id' => $person->id]);
    }

    /** @param  list<string>  $scopes */
    private function token(array $scopes = ['finance:read', 'finance:write']): string
    {
        return ApiToken::issue($this->user, 'test', $scopes)['plain'];
    }

    private function url(string $path): string
    {
        return '/api/v1/accounting'.$path;
    }

    /**
     * Books a typed-in booking via REST.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return TestResponse<JsonResponse>
     */
    private function book(string $token, string $date, array $lines, string $text = 'Booking'): TestResponse
    {
        return $this->withToken($token)->postJson($this->url('/bookings'), ['date' => $date, 'text' => $text, 'lines' => $lines]);
    }

    public function test_every_endpoint_needs_its_scope_and_reading_is_not_writing(): void
    {
        $none = $this->token(['people:read', 'people:write', 'events:read']);
        $read = $this->token(['finance:read']);
        $routes = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/v1/accounting/')) {
                foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                    $routes[] = [$method, '/'.preg_replace('/\{[a-z]+\}/', '1', $route->uri())];
                }
            }
        }
        $this->assertCount(32, $routes);
        foreach ($routes as [$method, $path]) {
            $this->withToken($none)->json($method, $path)->assertForbidden();
            if ($method !== 'GET') {
                $this->withToken($read)->json($method, $path)->assertForbidden();
            }
        }
        $this->assertSame(0, Period::query()->count(), 'nothing was created');
    }

    public function test_accounts_with_the_rules_of_the_chart(): void
    {
        $this->travelTo('2028-01-15 10:00'); // the balance is the one of today
        $token = $this->token();
        $bank = collect((array) $this->withToken($token)->getJson($this->url('/accounts'))->assertOk()->json('data'))->firstWhere('name', 'Bank');
        $this->assertSame(['asset', 'bank', true, true, false, 0], [$bank['type'], $bank['money'], $bank['system'], $bank['type_locked'], $bank['deletable'], $bank['balance']]);
        $this->assertSame([], $bank['funds']);

        $shop = $this->withToken($token)->postJson($this->url('/accounts'), ['name' => 'Camp shop', 'type' => 'income', 'number' => '4900', 'default_cost_center' => 'General'])
            ->assertCreated()->assertJsonPath('data.default_cost_center.name', 'General')->assertJsonPath('data.active', true)->assertJsonPath('data.deletable', true);
        $this->withToken($token)->postJson($this->url('/accounts'), ['name' => 'Camp shop', 'type' => 'income'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->withToken($token)->postJson($this->url('/accounts'), ['name' => 'Petty', 'type' => 'expense', 'money' => 'cash'])->assertUnprocessable();
        $this->withToken($token)->postJson($this->url('/accounts'), ['name' => 'Petty', 'type' => 'asset', 'default_cost_center' => 'General'])->assertUnprocessable();
        $this->withToken($token)->postJson($this->url('/accounts'), ['name' => 'Petty', 'type' => 'income', 'default_cost_center' => 'Nowhere'])->assertJsonValidationErrors('default_cost_center');
        $this->withToken($token)->postJson($this->url('/accounts'), ['name' => 'Savings', 'type' => 'asset', 'money' => 'bank', 'iban' => 'at61 1904 3002 3457 3201'])
            ->assertCreated()->assertJsonPath('data.iban', 'AT611904300234573201');

        // References: id, exact name or account number; a name that is also an id is ambiguous.
        $this->withToken($token)->patchJson($this->url('/accounts/4900'), ['name' => 'Kiosk'])->assertOk()->assertJsonPath('data.name', 'Kiosk');
        $this->withToken($token)->patchJson($this->url('/accounts/'.$shop->json('data.id')), ['active' => false])->assertOk()->assertJsonPath('data.active', false);
        $this->withToken($token)->patchJson($this->url('/accounts/Nowhere'), ['sort' => 1])->assertNotFound();
        $bankId = Account::system('bank')->id;
        $this->withToken($token)->postJson($this->url('/accounts'), ['name' => (string) $bankId, 'type' => 'expense'])->assertCreated();
        $this->withToken($token)->patchJson($this->url('/accounts/'.$bankId), ['sort' => 1])->assertUnprocessable();

        // System accounts keep their type; an account with bookings too.
        $this->withToken($token)->patchJson($this->url('/accounts/Bank'), ['type' => 'liability'])->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->withToken($token)->patchJson($this->url('/accounts/Bank'), ['type' => 'asset', 'sort' => 5])->assertOk()->assertJsonPath('data.sort', 5);
        app(Periods::class)->create('2027', '2027-01-01', '2027-12-31');
        $this->withToken($token)->patchJson($this->url('/accounts/Kiosk'), ['active' => true])->assertOk();
        $this->book($token, '2027-02-01', [['account' => 'Bank', 'debit' => 12.5], ['account' => 'Kiosk', 'credit' => 12.5]])->assertCreated();
        $this->withToken($token)->patchJson($this->url('/accounts/Kiosk'), ['type' => 'expense'])->assertUnprocessable();
        $this->withToken($token)->deleteJson($this->url('/accounts/Kiosk'))->assertUnprocessable();
        $this->withToken($token)->deleteJson($this->url('/accounts/Bank'))->assertUnprocessable();
        $this->withToken($token)->deleteJson($this->url('/accounts/Savings'))->assertOk()->assertJsonPath('data', null);
        $this->assertFalse(Account::query()->where('name', 'Savings')->exists());

        $money = (array) $this->withToken($token)->getJson($this->url('/accounts?money=any'))->json('data');
        $this->assertSame(['Bank', 'Cash'], array_column($money, 'name'));
        $this->assertSame(12.5, $money[0]['balance']);
        $this->assertContains('Kiosk', array_column((array) $this->withToken($token)->getJson($this->url('/accounts?type=income&active=1'))->json('data'), 'name'));
        $this->assertSame([], (array) $this->withToken($token)->getJson($this->url('/accounts?active=false'))->json('data'));
        $this->assertSame(12.5, collect((array) $this->withToken($token)->getJson($this->url('/accounts?type=income'))->json('data'))->firstWhere('name', 'Kiosk')['balance']);
    }

    public function test_cost_centers_with_kind_periods_default_and_deleting(): void
    {
        $token = $this->token();
        $period = app(Periods::class)->create('2027', '2027-01-01', '2027-12-31');
        $this->withToken($token)->postJson($this->url('/cost-centers'), ['name' => 'Camp'])->assertUnprocessable();
        Settings::set('use_cost_centers', '1');
        $this->withToken($token)->postJson($this->url('/cost-centers'), ['name' => 'Camp'])->assertCreated()
            ->assertJsonPath('data.kind', 'carries')->assertJsonPath('data.fund.name', 'General fund')->assertJsonPath('data.active_periods.0.name', '2027');
        $this->withToken($token)->postJson($this->url('/cost-centers'), ['name' => 'Administration', 'kind' => 'allocated'])->assertCreated()->assertJsonPath('data.fund', null);
        $this->withToken($token)->postJson($this->url('/cost-centers'), ['name' => 'Office', 'kind' => 'allocated', 'fund' => 'General fund'])->assertUnprocessable();
        $this->withToken($token)->postJson($this->url('/cost-centers'), ['name' => 'Camp'])->assertUnprocessable();
        $list = (array) $this->withToken($token)->getJson($this->url('/cost-centers'))->assertOk()->json('data');
        $this->assertSame(['Administration', 'Camp', 'General'], array_column($list, 'name'));
        $this->assertSame([false, false, true], array_column($list, 'default'));
        $this->assertNotSame([], $list[2]['cannot_delete'], 'the standard one cannot be deleted');

        $this->withToken($token)->patchJson($this->url('/cost-centers/Camp'), ['name' => 'Summer camp'])->assertOk()->assertJsonPath('data.name', 'Summer camp');
        $this->withToken($token)->patchJson($this->url('/cost-centers/Administration'), ['fund' => 'General fund'])->assertUnprocessable();

        // Kind: an allocated one becomes carrying and back; the last carrying one stays.
        $this->withToken($token)->postJson($this->url('/cost-centers/Administration/kind'), ['kind' => 'carries'])->assertOk()->assertJsonPath('data.kind', 'carries');
        $this->withToken($token)->postJson($this->url('/cost-centers/Administration/kind'), ['kind' => 'allocated'])->assertOk()->assertJsonPath('data.kind', 'allocated');
        $this->withToken($token)->postJson($this->url('/cost-centers/Summer%20camp/kind'), ['kind' => 'allocated'])->assertOk();
        $this->withToken($token)->postJson($this->url('/cost-centers/General/kind'), ['kind' => 'allocated'])->assertUnprocessable()->assertJsonPath('message', __('accounting.errors.one_must_carry'));
        $this->withToken($token)->postJson($this->url('/cost-centers/Summer%20camp/kind'), ['kind' => 'carries', 'fund' => 'General fund'])->assertOk();

        // Active per period.
        $this->withToken($token)->putJson($this->url('/cost-centers/Summer%20camp/periods/2027'), ['active' => false])->assertOk()
            ->assertJsonPath('data.active_periods', [])->assertJsonPath('warnings', []);
        $this->book($token, '2027-03-01', [['account' => 'Bank', 'debit' => 10], ['account' => 'Donations', 'credit' => 10, 'cost_center' => 'Summer camp']])
            ->assertUnprocessable()->assertJsonPath('message', __('accounting.errors.inactive_cost_center', ['name' => 'Summer camp', 'period' => '2027']));
        $this->withToken($token)->putJson($this->url('/cost-centers/Summer%20camp/periods/'.$period->id), ['active' => true])->assertOk()->assertJsonPath('data.active_periods.0.id', $period->id);
        $this->withToken($token)->putJson($this->url('/cost-centers/General/periods/2027'), ['active' => false])->assertUnprocessable();

        // Standard.
        $this->withToken($token)->postJson($this->url('/cost-centers/Summer%20camp/default'))->assertOk()->assertJsonPath('data.default', true);
        $this->assertFalse(CostCenter::query()->where('name', 'General')->sole()->is_default);

        // Deleting: bookings are moved, or – typed in only – deleted with the number as confirmation.
        $this->book($token, '2027-03-01', [['account' => 'Bank', 'debit' => 10], ['account' => 'Donations', 'credit' => 10, 'cost_center' => 'General']])->assertCreated();
        $this->withToken($token)->postJson($this->url('/cost-centers'), ['name' => 'Hike'])->assertCreated();
        $this->book($token, '2027-04-01', [['account' => 'Food', 'debit' => 20, 'cost_center' => 'Hike'], ['account' => 'Cash', 'credit' => 20]])->assertCreated();
        $this->withToken($token)->deleteJson($this->url('/cost-centers/General'))->assertUnprocessable()->assertJsonPath('message', __('accounting.errors.cost_center_bookings'));
        $this->withToken($token)->deleteJson($this->url('/cost-centers/General'), ['delete_bookings' => true])->assertUnprocessable()->assertJsonValidationErrors('confirm_count');
        $this->withToken($token)->deleteJson($this->url('/cost-centers/General'), ['delete_bookings' => true, 'confirm_count' => 2])->assertUnprocessable()->assertJsonPath('message', __('accounting.errors.confirmation'));
        $this->withToken($token)->deleteJson($this->url('/cost-centers/General'), ['delete_bookings' => true, 'confirm_count' => 1, 'move_to' => 'Hike'])->assertUnprocessable();
        $this->withToken($token)->deleteJson($this->url('/cost-centers/General'), ['delete_bookings' => true, 'confirm_count' => 1])->assertOk()->assertJsonPath('deleted_bookings', 1);
        $this->assertSame(1, JournalEntry::query()->count());
        $this->withToken($token)->deleteJson($this->url('/cost-centers/Hike'), ['move_to' => 'Summer camp'])->assertOk()->assertJsonPath('moved_to.name', 'Summer camp')->assertJsonPath('deleted_bookings', 0);
        $this->assertSame(CostCenter::query()->where('name', 'Summer camp')->value('id'), JournalEntry::query()->sole()->lines()->whereNotNull('cost_center_id')->value('cost_center_id'));
        $this->withToken($token)->deleteJson($this->url('/cost-centers/Summer%20camp'))->assertUnprocessable();
    }

    public function test_funds_with_transfers(): void
    {
        $this->travelTo('2028-01-15 10:00');
        $token = $this->token();
        app(Periods::class)->create('2027', '2027-01-01', '2027-12-31');
        Settings::set('use_cost_centers', '1');
        $this->withToken($token)->postJson($this->url('/funds'), ['name' => 'Tents'])->assertUnprocessable();
        Settings::set('use_funds', '1');
        $this->withToken($token)->postJson($this->url('/funds'), ['name' => 'Tents', 'account' => 'Bank'])->assertCreated()
            ->assertJsonPath('data.account.name', 'Bank')->assertJsonPath('data.balance', 0)->assertJsonPath('data.deletable', true);
        $this->withToken($token)->postJson($this->url('/funds'), ['name' => 'Other', 'account' => 'Food'])->assertUnprocessable();
        $this->withToken($token)->patchJson($this->url('/funds/Tents'), ['note' => 'For new tents'])->assertOk()->assertJsonPath('data.note', 'For new tents');

        $this->book($token, '2027-02-01', [['account' => 'Bank', 'debit' => 100], ['account' => 'Funds (equity)', 'credit' => 100]], 'Opening')->assertCreated()
            ->assertJsonPath('data.lines.1.fund.name', 'General fund');
        $general = rawurlencode('General fund');
        $this->withToken($token)->postJson($this->url("/funds/$general/transfers"), ['to' => 'General fund', 'amount' => 10, 'text' => 'Nothing'])->assertUnprocessable();
        $transfer = $this->withToken($token)->postJson($this->url("/funds/$general/transfers"), ['to' => 'Tents', 'amount' => 40, 'date' => '2027-03-01', 'text' => 'Board decision'])
            ->assertCreated()->assertJsonPath('data.kind', 'fund_transfer')->assertJsonPath('data.total', 40);
        $this->assertSame('Board decision', $transfer->json('data.text'));
        $funds = collect((array) $this->withToken($token)->getJson($this->url('/funds'))->assertOk()->json('data'))->keyBy('name');
        $this->assertSame([60, 40, 60], [$funds['General fund']['balance'], $funds['Tents']['balance'], $funds['Tents']['account_difference']]);
        $this->assertSame(['Tents'], array_column((array) collect((array) $this->withToken($token)->getJson($this->url('/accounts?money=bank'))->json('data'))->first()['funds'], 'name'));

        $this->withToken($token)->deleteJson($this->url('/funds/Tents'))->assertUnprocessable();
        $this->withToken($token)->deleteJson($this->url("/funds/$general"))->assertUnprocessable();
        $this->withToken($token)->postJson($this->url('/funds'), ['name' => 'Empty'])->assertCreated();
        $this->withToken($token)->deleteJson($this->url('/funds/Empty'))->assertOk();
        $this->assertFalse(Fund::query()->where('name', 'Empty')->exists());
    }

    public function test_a_year_from_the_first_period_to_closing_reopening_and_reports(): void
    {
        $token = $this->token();
        $this->withToken($token)->postJson($this->url('/periods'), ['name' => '2027', 'ends_on' => '2027-12-31'])->assertUnprocessable()->assertJsonValidationErrors('starts_on');
        $first = (int) $this->withToken($token)->postJson($this->url('/periods'), ['name' => '2027', 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31'])->assertCreated()
            ->assertJsonPath('data.status', 'open')->assertJsonPath('data.active_cost_centers.0.name', 'General')->json('data.id');
        $second = (int) $this->withToken($token)->postJson($this->url('/periods'), ['name' => '2028', 'ends_on' => '2028-12-31'])->assertCreated()->assertJsonPath('data.starts_on', '2028-01-01')->json('data.id');
        $this->withToken($token)->postJson($this->url('/periods'), ['name' => '2030', 'starts_on' => '2030-01-01', 'ends_on' => '2030-12-31'])->assertUnprocessable();
        $this->assertSame(['2028', '2027'], array_column((array) $this->withToken($token)->getJson($this->url('/periods'))->json('data'), 'name'));

        // Bookings: balanced, in an open period, references by name.
        $donation = $this->book($token, '2027-05-01', [['account' => 'Bank', 'debit' => 100], ['account' => 'Donations', 'credit' => 100]], 'Donation')->assertCreated()
            ->assertJsonPath('data.number', 1)->assertJsonPath('data.total', 100)->assertJsonPath('data.lines.1.cost_center.name', 'General')->assertJsonPath('data.period.name', '2027');
        $this->book($token, '2027-05-02', [['account' => 'Bank', 'debit' => 100], ['account' => 'Donations', 'credit' => 90]])->assertUnprocessable()->assertJsonPath('message', fn (string $m) => str_contains($m, 'must be equal'));
        $this->book($token, '2027-05-02', [['account' => 'Bank', 'debit' => 1], ['account' => 'Nowhere', 'credit' => 1]])->assertJsonValidationErrors('lines.1.account');
        $this->book($token, '2026-05-02', [['account' => 'Bank', 'debit' => 1], ['account' => 'Donations', 'credit' => 1]])->assertUnprocessable();
        $this->book($token, '2027-05-02', [['account' => 'Bank', 'debit' => 1]])->assertJsonValidationErrors('lines');
        $shop = (int) $this->withToken($token)->postJson($this->url('/bookings'), ['date' => '2027-06-01', 'text' => 'Shop', 'receipt' => 'R-7', 'lines' => [
            ['account' => 'Food', 'debit' => 30], ['account' => 'Material', 'debit' => 12.5, 'text' => 'Glue'], ['account' => 'Cash', 'credit' => 42.5],
        ]])->assertCreated()->assertJsonPath('data.total', 42.5)->assertJsonPath('data.receipt', 'R-7')->json('data.id');
        $wrong = (int) $this->book($token, '2027-06-02', [['account' => 'Food', 'debit' => 5], ['account' => 'Cash', 'credit' => 5]], 'Typo')->json('data.id');

        $this->assertSame(1, $this->withToken($token)->getJson($this->url('/bookings?account=Material'))->json('meta.total'));
        $this->assertSame(3, $this->withToken($token)->getJson($this->url('/bookings?period=2027'))->json('meta.total'));
        $this->assertSame([$shop], array_column((array) $this->withToken($token)->getJson($this->url('/bookings?from=2027-06-01&to=2027-06-01&kind=manual'))->json('data'), 'id'));
        $this->assertSame('Glue', $this->withToken($token)->getJson($this->url("/bookings/$shop"))->assertOk()->json('data.lines.1.text'));

        // Reverse once; a reversal is not reversed.
        $reversal = (int) $this->withToken($token)->postJson($this->url("/bookings/$wrong/reverse"))->assertCreated()->assertJsonPath('data.reverses', $wrong)->assertJsonPath('data.date', '2027-06-02')->json('data.id');
        $this->withToken($token)->postJson($this->url("/bookings/$wrong/reverse"))->assertUnprocessable()->assertJsonPath('message', __('accounting.errors.reversed'));
        $this->withToken($token)->postJson($this->url("/bookings/$reversal/reverse"))->assertUnprocessable();
        $this->assertSame($reversal, $this->withToken($token)->getJson($this->url("/bookings/$wrong"))->json('data.reversed_by'));

        // Dates of an open period: a dry run counts, then the bookings move along.
        $this->withToken($token)->patchJson($this->url("/periods/$first"), ['ends_on' => '2027-05-31', 'dry_run' => true])->assertOk()->assertJsonPath('moved', 3)->assertJsonPath('data.ends_on', '2027-12-31');
        $this->withToken($token)->patchJson($this->url("/periods/$first"), ['ends_on' => '2027-05-31'])->assertOk()->assertJsonPath('moved', 3)->assertJsonPath('data.ends_on', '2027-05-31');
        $this->assertSame('2027-06-01', Period::query()->findOrFail($second)->starts_on->toDateString());
        $this->withToken($token)->patchJson($this->url("/periods/$first"), ['name' => 'Year 2027', 'ends_on' => '2027-12-31'])->assertOk()->assertJsonPath('moved', 3)->assertJsonPath('data.name', 'Year 2027');

        // Preview and closing; in order only.
        $preview = $this->withToken($token)->getJson($this->url("/periods/$first/closing"))->assertOk()->assertJsonPath('data.can_close', true);
        $this->assertSame([['cost_center' => ['id' => CostCenter::default()->id, 'name' => 'General'], 'kind' => 'carries', 'before' => 57.5, 'allocation' => 0, 'after' => 57.5]], $preview->json('data.rows'));
        $this->assertSame(57.5, $preview->json('data.funds.0.after'));
        $this->withToken($token)->postJson($this->url("/periods/$second/close"))->assertUnprocessable()->assertJsonPath('message', __('accounting.errors.close_in_order'));
        $this->assertFalse((bool) $this->withToken($token)->getJson($this->url("/periods/$second/closing"))->json('data.can_close'));
        $closed = $this->withToken($token)->postJson($this->url("/periods/$first/close"))->assertOk()->assertJsonPath('data.period.status', 'closed');
        $this->assertCount(1, (array) $closed->json('data.bookings'));
        $this->assertSame(5750, app(Balances::class)->fund(Fund::default(), '2027-12-31'));
        $this->withToken($token)->postJson($this->url("/periods/$first/close"))->assertUnprocessable();
        $this->book($token, '2027-07-01', [['account' => 'Bank', 'debit' => 1], ['account' => 'Donations', 'credit' => 1]])->assertUnprocessable()->assertJsonPath('message', __('accounting.errors.closed', ['period' => 'Year 2027']));
        $this->withToken($token)->patchJson($this->url("/periods/$first"), ['name' => 'Locked'])->assertUnprocessable();
        $closing = JournalEntry::query()->where('kind', 'closing')->sole();
        $this->withToken($token)->postJson($this->url("/bookings/{$closing->id}/reverse"))->assertUnprocessable();
        $this->withToken($token)->postJson($this->url("/periods/$second/close"))->assertOk();

        // Reopening reopens the later ones too and needs confirm.
        $this->withToken($token)->postJson($this->url("/periods/$first/reopen"))->assertJsonValidationErrors('reason');
        $this->withToken($token)->postJson($this->url("/periods/$first/reopen"), ['reason' => 'Late receipt'])->assertUnprocessable()
            ->assertJsonPath('periods', [['id' => $first, 'name' => 'Year 2027'], ['id' => $second, 'name' => '2028']]);
        $this->assertTrue(Period::query()->findOrFail($first)->isClosed());
        $reopened = $this->withToken($token)->postJson($this->url("/periods/$first/reopen"), ['reason' => 'Late receipt', 'confirm' => true])->assertOk();
        $this->assertSame([['open', 'Late receipt'], ['open', 'Late receipt']], array_map(fn (array $p) => [$p['status'], $p['reopened_reason']], (array) $reopened->json('data')));
        $this->assertSame(0, JournalEntry::query()->where('kind', 'closing')->count());
        $this->withToken($token)->postJson($this->url("/periods/$first/reopen"), ['reason' => 'Again', 'confirm' => true])->assertUnprocessable();

        // Reports as JSON and as the file of the page.
        $income = $this->withToken($token)->getJson($this->url('/reports/income?period=Year%202027'))->assertOk()->assertJsonPath('data.title', 'Income statement Year 2027');
        $this->assertSame([(string) __('accounting.reports.result'), Money::format(5750)], collect((array) $income->json('data.rows'))->last());
        $csv = $this->withToken($token)->get($this->url('/reports/balance?date=2027-12-31&format=csv'))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('Bank', (string) $csv->getContent());
        $this->assertStringContainsString('attachment', (string) $csv->headers->get('Content-Disposition'));
        $this->assertSame(1, AuditEvent::query()->where('action', 'accounting.report_exported')->count());
        $this->assertSame($donation->json('data.id'), JournalEntry::query()->where('number', 1)->value('id'));
    }

    public function test_allocation_keys_are_checked_and_used_by_the_closing(): void
    {
        $token = $this->token();
        Settings::set('use_cost_centers', '1');
        $period = app(Periods::class)->create('2027', '2027-01-01', '2027-12-31');
        foreach ([['Camp', 'carries'], ['Weekend', 'carries'], ['Administration', 'allocated']] as [$name, $kind]) {
            $this->withToken($token)->postJson($this->url('/cost-centers'), ['name' => $name, 'kind' => $kind])->assertCreated();
        }
        $key = fn (array $rules, string $of = 'Administration') => $this->withToken($token)->putJson($this->url("/periods/2027/allocation-keys/$of"), ['rules' => $rules]);
        $key([['target' => 'Weekend', 'kind' => 'fixed', 'amount' => 300], ['target' => 'Camp', 'kind' => 'percent', 'percent' => 60]])
            ->assertUnprocessable()->assertJsonPath('errors.rules.0', __('accounting.allocation.unassigned'));
        $key([['target' => 'Administration', 'kind' => 'rest']])->assertJsonValidationErrors('rules.0.target');
        $key([['target' => 'Weekend', 'kind' => 'rest'], ['target' => 'Camp', 'kind' => 'rest']])->assertJsonPath('errors.rules.0', __('accounting.allocation.one_rest'));
        $key([['target' => 'Weekend', 'kind' => 'rest']], 'Camp')->assertUnprocessable();
        $key([['target' => 'Weekend', 'kind' => 'fixed', 'amount' => 300], ['target' => 'Camp', 'kind' => 'rest']])->assertOk()
            ->assertJsonPath('data.cost_center.name', 'Administration')->assertJsonPath('data.rules.0.amount', 300)->assertJsonPath('data.rules.1.kind', 'rest');
        $keys = (array) $this->withToken($token)->getJson($this->url('/periods/2027/allocation-keys'))->assertOk()->json('data');
        $this->assertSame(['Administration'], array_column(array_column($keys, 'cost_center'), 'name'));
        $this->assertCount(2, $keys[0]['rules']);

        $this->book($token, '2027-03-01', [['account' => 'Software and website', 'debit' => 2000, 'cost_center' => 'Administration'], ['account' => 'Bank', 'credit' => 2000]])->assertCreated();
        $this->book($token, '2027-07-01', [['account' => 'Bank', 'debit' => 5000], ['account' => 'Income from events', 'credit' => 5000, 'cost_center' => 'Camp']])->assertCreated();
        $preview = $this->withToken($token)->getJson($this->url("/periods/{$period->id}/closing"))->assertOk()->assertJsonPath('data.can_close', true);
        $this->assertSame(['Camp' => 1700, 'Weekend' => 300], collect((array) $preview->json('data.allocations.0.amounts'))->mapWithKeys(fn (array $a) => [$a['cost_center']['name'] => $a['amount']])->sortKeys()->all());
        $rows = collect((array) $preview->json('data.rows'))->keyBy('cost_center.name');
        $this->assertSame([-2000, 2000, 0], [$rows['Administration']['before'], $rows['Administration']['allocation'], $rows['Administration']['after']]);
        $this->assertSame(3300, $rows['Camp']['after']);
        $this->withToken($token)->postJson($this->url("/periods/{$period->id}/close"))->assertOk();
        $this->assertCount(1, (array) $this->withToken($token)->getJson($this->url('/bookings?kind=allocation'))->json('data'));
        $key([['target' => 'Camp', 'kind' => 'rest']])->assertUnprocessable()->assertJsonPath('message', __('accounting.errors.closed', ['period' => '2027']));

        $centers = $this->withToken($token)->getJson($this->url('/reports/cost_centers?period=2027'))->assertOk();
        $this->assertSame(['Administration', 'Camp', 'Weekend'], array_column((array) $centers->json('data.rows'), 0));
    }

    public function test_every_report_and_every_file_format(): void
    {
        $token = $this->token();
        Settings::set('use_cost_centers', '1');
        Settings::set('use_funds', '1');
        app(Periods::class)->create('2027', '2027-01-01', '2027-12-31');
        $this->book($token, '2027-05-01', [['account' => 'Bank', 'debit' => 80], ['account' => 'Membership fees', 'credit' => 80]])->assertCreated();
        foreach (['balance' => 'Balance sheet', 'income' => 'Income statement', 'cost_centers' => 'Cost centres', 'funds' => 'Statement of funds', 'open_items' => 'Open items'] as $report => $title) {
            $answer = $this->withToken($token)->getJson($this->url("/reports/$report?period=2027&date=2027-12-31"))->assertOk()->assertJsonPath('data.report', $report);
            $this->assertStringStartsWith($title, (string) $answer->json('data.title'));
            $this->assertNotSame([], $answer->json('data.headers'));
        }
        $this->withToken($token)->getJson($this->url('/reports/income?period=2027&cost_center=General'))->assertOk()->assertJsonPath('data.title', 'Income statement 2027 – General');
        $this->withToken($token)->getJson($this->url('/reports/ledger'))->assertJsonValidationErrors('account');
        $ledger = $this->withToken($token)->getJson($this->url('/reports/ledger?account=Bank&from=2027-01-01&to=2027-12-31'))->assertOk();
        $this->assertCount(2, (array) $ledger->json('data.rows'), 'opening and one line');
        $this->withToken($token)->getJson($this->url('/reports/budget'))->assertNotFound();
        $this->withToken($token)->getJson($this->url('/reports/balance?format=docx'))->assertJsonValidationErrors('format');
        foreach (['csv' => 'text/csv; charset=UTF-8', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'pdf' => 'application/pdf'] as $format => $type) {
            $this->withToken($token)->get($this->url("/reports/ledger?account=Bank&format=$format"))->assertOk()->assertHeader('Content-Type', $type);
        }
        $this->assertSame(3, AuditEvent::query()->where('action', 'accounting.report_exported')->count());
    }

    public function test_the_switches_of_the_accounting_follow_the_rules_of_the_global_settings(): void
    {
        $token = $this->token(['settings:write']);
        $this->withToken($token)->patchJson('/api/v1/settings', ['use_funds' => true])->assertJsonValidationErrors('use_funds');
        $this->withToken($token)->patchJson('/api/v1/settings', ['use_cost_centers' => true, 'use_funds' => true])->assertOk()
            ->assertJsonPath('data.use_cost_centers', '1')->assertJsonPath('data.use_funds', '1');
        Fund::query()->create(['name' => 'Tents']);
        $this->withToken($token)->patchJson('/api/v1/settings', ['use_funds' => false])->assertJsonValidationErrors('use_funds');
        $this->withToken($token)->patchJson('/api/v1/settings', ['use_cost_centers' => false])->assertJsonValidationErrors('use_cost_centers');
        Fund::query()->where('name', 'Tents')->delete();
        $this->withToken($token)->patchJson('/api/v1/settings', ['use_funds' => false])->assertOk()->assertJsonPath('data.use_funds', '0');
        CostCenter::query()->create(['name' => 'Camp']);
        $this->withToken($token)->patchJson('/api/v1/settings', ['use_cost_centers' => false])->assertJsonValidationErrors('use_cost_centers');
        CostCenter::query()->where('name', 'Camp')->delete();
        $this->withToken($token)->patchJson('/api/v1/settings', ['use_cost_centers' => false])->assertOk();
        $this->assertFalse(Settings::bool('use_cost_centers'));
        $this->withToken($this->token())->patchJson('/api/v1/settings', ['use_cost_centers' => true])->assertForbidden();
    }

    public function test_bookings_of_documents_are_corrected_through_the_document_not_reversed(): void
    {
        app(Periods::class)->create('2027', '2027-01-01', '2027-12-31');
        $token = $this->token();
        $entry = app(Journal::class)->post(['date' => '2027-03-01', 'text' => 'Payment', 'kind' => 'payment'], [
            ['account' => Account::system('bank')->id, 'debit' => 5000], ['account' => Account::system('receivables')->id, 'credit' => 5000],
        ]);
        $this->withToken($token)->postJson($this->url('/bookings/'.$entry->id.'/reverse'))->assertUnprocessable()->assertJsonPath('message', __('accounting.errors.not_reversible'));
        $this->assertFalse(Journal::reversible($entry));
        $this->assertSame(1, JournalEntry::query()->count());
    }

    public function test_a_booking_with_an_idempotency_key_is_booked_once(): void
    {
        $token = $this->token();
        app(Periods::class)->create('2027', '2027-01-01', '2027-12-31');
        $send = fn () => $this->withToken($token)->withHeader('Idempotency-Key', 'shop-7')->postJson($this->url('/bookings'), [
            'date' => '2027-05-01', 'text' => 'Shop', 'lines' => [['account' => 'Food', 'debit' => 3], ['account' => 'Cash', 'credit' => 3]],
        ]);
        $first = $send()->assertCreated();
        $send()->assertCreated()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertSame(1, JournalEntry::query()->count());
    }
}
