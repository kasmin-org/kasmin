<?php

namespace Tests\Feature\System;

use App\Filament\Resources\Automations\Pages\CreateAutomation;
use App\Filament\Resources\Automations\Pages\EditAutomation;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Filament\Resources\Forms\Pages\CreateForm;
use App\Filament\Resources\Forms\Pages\EditForm;
use App\Filament\Resources\MailTemplates\Pages\CreateMailTemplate;
use App\Filament\Resources\MailTemplates\Pages\EditMailTemplate;
use App\Filament\Resources\MembershipTypes\Pages\ManageMembershipTypes;
use App\Filament\Resources\Roles\Pages\ManageRoles;
use App\Models\ApiToken;
use App\Models\Automation;
use App\Models\Event;
use App\Models\EventInstallment;
use App\Models\Form;
use App\Models\Household;
use App\Models\Invoice;
use App\Models\MailTemplate;
use App\Models\Membership;
use App\Models\MembershipType;
use App\Models\Participation;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Automations\Engine;
use App\Support\Automations\InstallmentPosition;
use App\Support\Finance\Invoices;
use App\Support\Import\PeopleImporter;
use App\Support\Install\Defaults;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * B8: records are shown and edited with their name; the key is internal, made by the system, never shown and never
 * edited. References use the database id; REST and MCP accept the id or the exact name.
 */
class NamesInsteadOfKeysTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        app(Defaults::class)->install('en');
        $person = Person::factory()->create(['locale' => 'en']);
        $person->contactPoints()->create(['type' => 'email', 'value' => 'admin@example.org', 'is_primary' => true, 'verified_at' => now()]);
        $person->roleAssignments()->create(['role_id' => Role::query()->where('key', 'administrator')->value('id')]);
        $this->admin = User::query()->firstOrCreate(['person_id' => $person->id], ['name' => 'Admin', 'email' => 'admin@example.org', 'password' => 'x']);
        $this->actingAs($this->admin)->withSession(['two_factor_passed' => true]);
    }

    /**
     * No input of the form is bound to a "key", also not inside a repeater.
     *
     * @param  Testable<Component>  $component
     */
    private function assertNoKeyField(Testable $component): void
    {
        $this->assertDoesNotMatchRegularExpression('/="data\.(?:[^"]*\.)?key"/', $component->html());
    }

    private function event(): Event
    {
        $event = Event::query()->create(['title' => 'Summer camp', 'starts_at' => now()->addMonths(3), 'ends_at' => now()->addMonths(3)->addWeek(), 'status' => 'open']);
        $event->roles()->create(['role_key' => 'child', 'capacity' => 20, 'price_cents' => 40000]);

        return $event;
    }

    public function test_no_form_of_the_admin_interface_has_a_key_field(): void
    {
        Livewire::test(ManageMembershipTypes::class)->mountAction('create')->assertFormFieldExists('name')->assertFormFieldDoesNotExist('key');
        Livewire::test(ManageRoles::class)->mountAction('create')->assertFormFieldExists('name')->assertFormFieldDoesNotExist('key');
        $this->assertNoKeyField(Livewire::test(CreateForm::class)->assertFormFieldDoesNotExist('key'));
        $this->assertNoKeyField(Livewire::test(EditForm::class, ['record' => Form::query()->where('key', 'safety_sheet')->value('id')])->assertFormFieldDoesNotExist('key'));
        $this->assertNoKeyField(Livewire::test(CreateMailTemplate::class)->assertFormFieldDoesNotExist('key'));
        $this->assertNoKeyField(Livewire::test(EditMailTemplate::class, ['record' => MailTemplate::query()->where('key', 'invoice')->value('id')]));
        $this->assertNoKeyField(Livewire::test(CreateAutomation::class));

        $event = $this->event();
        $event->installments()->create(['label' => 'Deposit', 'kind' => 'fixed', 'amount_cents' => 10000, 'sort' => 1]);
        $page = Livewire::test(EditEvent::class, ['record' => $event->id]);
        $this->assertNoKeyField($page);
        $this->assertMatchesRegularExpression('/="data\.installments\.[^"]+\.label"/', $page->html());

        // The invoice run chooses the instalment by its position, the safety sheet reminder its form by name.
        $run = Automation::query()->where('key', 'deposit_invoices')->firstOrFail();
        Livewire::test(EditAutomation::class, ['record' => $run->id])
            ->assertFormFieldExists('config.installment', fn ($field) => $field instanceof Select && array_keys($field->getOptions()) === InstallmentPosition::CHOICES)
            ->assertFormSet(['config.installment' => 'pos:1'])->assertDontSee('(key)');
        $reminder = Automation::query()->where('action', 'safety_sheet_reminder')->firstOrFail();
        Livewire::test(EditAutomation::class, ['record' => $reminder->id])
            ->assertFormFieldExists('config.form', fn ($field) => $field instanceof Select && in_array('Safety sheet', $field->getOptions(), true));
    }

    public function test_a_key_of_an_older_version_is_kept_and_offered_as_previous_setting(): void
    {
        $run = Automation::query()->where('key', 'balance_invoices')->firstOrFail();
        $run->update(['config' => ['installment' => 'balance', 'send' => true]]);
        Livewire::test(EditAutomation::class, ['record' => $run->id])
            ->assertFormSet(['config.installment' => 'balance'])
            ->assertFormFieldExists('config.installment', fn ($field) => $field instanceof Select && ($field->getOptions()['balance'] ?? null) === 'Previous setting (balance)')
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame('balance', $run->refresh()->setting('installment'));
    }

    public function test_keys_are_made_from_the_name_once_and_are_unique(): void
    {
        $this->assertSame('own_supporter', MembershipType::query()->create(['name' => 'Supporter'])->key);
        $this->assertSame('own_supporter_2', MembershipType::query()->create(['name' => 'Supporter!'])->key);
        $role = Role::query()->create(['name' => 'Kitchen team', 'is_admin' => false]);
        $this->assertSame('own_kitchen_team', $role->key);
        $role->update(['name' => 'Kitchen']);
        $this->assertSame('own_kitchen_team', $role->refresh()->key, 'a new name keeps the key');
        $this->assertSame('own_item', Form::query()->create(['name' => '!!!', 'kind' => 'person'])->key);
        $this->assertSame('own_uber_uns', MailTemplate::query()->create(['name' => 'Über uns', 'category' => 'newsletter'])->key);

        // Instalments are unique per event, and the key fits its column.
        [$one, $two] = [$this->event(), $this->event()];
        $long = str_repeat('Very long name of an instalment ', 3);
        $this->assertSame('deposit', $one->installments()->create(['label' => 'Deposit', 'kind' => 'fixed'])->key);
        $this->assertSame('deposit_2', $one->installments()->create(['label' => 'Deposit (2)', 'kind' => 'fixed'])->key);
        $this->assertSame('deposit', $two->installments()->create(['label' => 'Deposit', 'kind' => 'fixed'])->key);
        $this->assertLessThanOrEqual(32, strlen((string) $two->installments()->create(['label' => $long, 'kind' => 'rest'])->key));
        // The software brings its own keys for its records.
        $this->assertTrue(Role::query()->where('key', 'administrator')->exists());
        $this->assertSame('safety_sheet', Form::query()->where('key', 'safety_sheet')->value('key'));
    }

    public function test_names_are_unique_in_the_admin_interface(): void
    {
        $taken = (string) MembershipType::query()->orderBy('id')->firstOrFail()->label();
        Livewire::test(ManageMembershipTypes::class)->callAction('create', data: ['name' => ' '.mb_strtoupper($taken).' ', 'pays_fee' => false])->assertHasActionErrors(['name']);
        Livewire::test(ManageMembershipTypes::class)->callAction('create', data: ['name' => 'Supporter', 'pays_fee' => false])->assertHasNoActionErrors();
        $this->assertSame('own_supporter', MembershipType::query()->where('name', json_encode('Supporter'))->value('key'));

        $treasurer = Role::query()->where('key', 'treasurer')->firstOrFail();
        Livewire::test(ManageRoles::class)->callTableAction('edit', $treasurer, data: ['name' => Role::query()->where('key', 'board')->firstOrFail()->label(), 'is_admin' => true, 'scope' => 'all'])
            ->assertHasTableActionErrors(['name']);
        Livewire::test(ManageRoles::class)->callTableAction('edit', $treasurer, data: ['name' => $treasurer->label(), 'is_admin' => true, 'scope' => 'all'])->assertHasNoTableActionErrors(); // its own name

        Livewire::test(CreateForm::class)->fillForm(['name' => 'safety SHEET', 'kind' => 'person', 'active' => true])->call('create')->assertHasFormErrors(['name']);
        $newsletter = MailTemplate::query()->where('key', 'newsletter')->firstOrFail();
        Livewire::test(EditMailTemplate::class, ['record' => $newsletter->id])->fillForm(['name' => MailTemplate::query()->where('key', 'invoice')->firstOrFail()->label()])->call('save')->assertHasFormErrors(['name']);
        Livewire::test(CreateAutomation::class)->fillForm(['name' => Automation::query()->where('key', 'deposit_invoices')->firstOrFail()->label()])->call('create')->assertHasFormErrors(['name']);

        $event = $this->event();
        Livewire::test(EditEvent::class, ['record' => $event->id])
            ->fillForm(['installments' => [['label' => 'Deposit', 'kind' => 'fixed', 'amount_cents' => '100'], ['label' => 'deposit ', 'kind' => 'rest']]])->call('save')
            ->assertHasFormErrors(['installments.1.label']);
        $this->assertSame(0, $event->installments()->count());
    }

    public function test_the_api_finds_records_by_id_name_or_old_key_and_refuses_ambiguous_names(): void
    {
        $token = ApiToken::issue($this->admin, 'test', ['people:read', 'people:write'])['plain'];
        $ann = Person::factory()->create(['birth_date' => '2015-01-01']);
        $url = "/api/v1/people/{$ann->public_id}/memberships";
        $type = MembershipType::query()->create(['name' => 'Supporter']);
        $this->withToken($token)->postJson($url, ['type' => $type->id])->assertCreated()->assertJsonPath('data.0.type', $type->id)->assertJsonPath('data.0.name', 'Supporter');
        $ann->memberships()->delete();
        $this->withToken($token)->postJson($url, ['type' => '  supporter '])->assertCreated()->assertJsonPath('data.0.type', $type->id);
        $ann->memberships()->delete();
        $this->withToken($token)->postJson($url, ['type' => 'own_supporter'])->assertCreated()->assertJsonPath('data.0.type', $type->id); // the old key
        $ann->memberships()->delete();
        $this->withToken($token)->postJson($url, ['type' => 'Nobody'])->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->withToken($token)->postJson($url, ['type' => 999999])->assertUnprocessable()->assertJsonValidationErrors('type');

        // Two records with the same name (possible in data of older versions): the name is refused, the id works.
        DB::table('membership_types')->insert(['key' => 'old_supporter', 'name' => json_encode('SUPPORTER'), 'pays_fee' => false, 'sort' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $message = $this->withToken($token)->postJson($url, ['type' => 'Supporter'])->assertUnprocessable()->json('errors.type.0');
        $this->assertStringContainsString('not unique', (string) $message);
        $this->withToken($token)->patchJson('/api/v1/membership-types/Supporter', ['fee' => 5])->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->withToken($token)->patchJson("/api/v1/membership-types/{$type->id}", ['fee' => 5])->assertOk()->assertJsonPath('data.fee', 5);
        $this->withToken($token)->postJson($url, ['type' => $type->id])->assertCreated();

        // The lists give id and name, never the key.
        $list = $this->withToken($token)->getJson('/api/v1/membership-types')->assertOk()->json('data');
        $this->assertSame(['id', 'name'], array_slice(array_keys($list[0]), 0, 2));
        $this->assertStringNotContainsString('"key"', (string) json_encode($list));

        // Forms by id or name; their fields keep the key the answers refer to, and can also be named by their id.
        /** @var list<array<string, mixed>> $forms */
        $forms = (array) $this->withToken($token)->getJson('/api/v1/forms')->assertOk()->json('data');
        $sheet = collect($forms)->firstWhere('name', 'General information');
        $this->assertIsArray($sheet);
        $this->assertArrayNotHasKey('key', $sheet);
        $field = $sheet['fields'][0];
        $this->assertSame(['id', 'key', 'type', 'label'], array_slice(array_keys($field), 0, 4));
        $this->withToken($token)->patchJson("/api/v1/people/{$ann->public_id}/forms/".rawurlencode('general INFORMATION'), ['values' => [(string) $field['id'] => 'M']])->assertOk()
            ->assertJsonPath('data.form', $sheet['id'])->assertJsonPath('data.name', 'General information');
        $this->withToken($token)->getJson("/api/v1/people/{$ann->public_id}/forms/{$sheet['id']}")->assertOk()->assertJsonPath('data.values.'.$field['key'], 'M');
    }

    public function test_the_person_import_takes_the_name_of_a_membership_type(): void
    {
        $type = MembershipType::query()->where('key', 'regular')->firstOrFail();
        $rows = [
            ['first_name' => 'Ann', 'last_name' => 'Ash', 'birth_date' => '1990-01-01', 'membership_type' => (string) $type->label()],
            ['first_name' => 'Ben', 'last_name' => 'Ash', 'birth_date' => '1991-01-01', 'membership_type' => 'regular'], // the key of older files
            ['first_name' => 'Cleo', 'last_name' => 'Ash', 'birth_date' => '1992-01-01', 'membership_type' => 'Not a type'],
        ];
        $plan = app(PeopleImporter::class)->plan($rows, 'test', 'en');
        $this->assertSame(['create', 'create', 'error'], array_column($plan['rows'], 'action'));
        $this->assertSame(['unknown membership_type Not a type'], $plan['rows'][2]['messages']);
        app(PeopleImporter::class)->import(array_slice($rows, 0, 2), 'test', 'en');
        $this->assertSame(2, Membership::query()->where('membership_type_id', $type->id)->count());
    }

    public function test_invoices_refer_to_the_instalment_by_its_id(): void
    {
        $event = $this->event();
        $deposit = $event->installments()->create(['key' => 'deposit', 'label' => 'Deposit', 'kind' => 'fixed', 'amount_cents' => 10000, 'due_days' => 14, 'sort' => 1]);
        $balance = $event->installments()->create(['key' => 'balance', 'label' => 'Balance', 'kind' => 'rest', 'due_days' => 14, 'sort' => 2]);
        // Invoices of an older version: only the key. The migration fills in the id; issued invoices keep their content.
        $insert = fn (array $values) => DB::table('invoices')->insertGetId($values + ['type' => 'invoice', 'currency' => 'EUR', 'paid_cents' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $issued = $insert(['number' => 'R1', 'status' => 'issued', 'event_id' => $event->id, 'installment_key' => 'deposit', 'total_cents' => 10000, 'issued_on' => '2027-01-02', 'payer_name' => 'Alex Ash']);
        $draft = $insert(['status' => 'draft', 'event_id' => $event->id, 'installment_key' => 'balance', 'total_cents' => 30000]);
        $gone = $insert(['status' => 'issued', 'number' => 'R2', 'event_id' => $event->id, 'installment_key' => 'removed', 'total_cents' => 100]);
        $fee = $insert(['status' => 'issued', 'number' => 'R3', 'installment_key' => 'membership-2027', 'total_cents' => 2500]);
        $before = (array) DB::table('invoices')->where('id', $issued)->first();
        DB::table('invoices')->update(['installment_id' => null]);
        (require database_path('migrations/2026_12_27_000000_add_installment_id_to_invoices.php'))->up();
        $this->assertSame([$deposit->id, $balance->id, null, null], array_map(fn (int $id) => Invoice::query()->findOrFail($id)->installment_id, [$issued, $draft, $gone, $fee]));
        $after = (array) DB::table('invoices')->where('id', $issued)->first();
        $this->assertSame(array_diff_key($before, ['installment_id' => 1]), array_diff_key($after, ['installment_id' => 1]));
        $this->assertSame(['Deposit', 'Membership fee 2027', null], [Invoice::query()->findOrFail($issued)->installmentName(), Invoice::query()->findOrFail($fee)->installmentName(), Invoice::query()->findOrFail($gone)->installmentName()]);

        // New invoices get the id; a new name of the instalment does not change what an invoice belongs to.
        $household = Household::query()->create(['name' => 'Birch']);
        $guardian = Person::factory()->create(['first_name' => 'Robin', 'last_name' => 'Birch']);
        $child = Person::factory()->create(['first_name' => 'Kim', 'last_name' => 'Birch', 'birth_date' => now()->subYears(10)->toDateString()]);
        $household->members()->attach($guardian->id, ['role' => 'guardian']);
        $household->members()->attach($child->id, ['role' => 'child']);
        $child->guardians()->attach($guardian->id);
        Participation::query()->create(['event_id' => $event->id, 'person_id' => $child->id, 'role_key' => 'child', 'status' => 'registered', 'registered_at' => now()]);
        $invoices = app(Invoices::class);
        $this->assertSame(1, $invoices->draftRun($event, $deposit));
        $made = Invoice::query()->where('household_id', $household->id)->sole();
        $this->assertSame([$deposit->id, 'deposit'], [$made->installment_id, $made->installment_key]);
        $deposit->update(['label' => 'First payment']);
        $this->assertSame(0, $invoices->pendingGroups($event, $deposit->refresh())->count(), 'already invoiced');
        $this->assertSame('First payment', $made->refresh()->installmentName());
        // An invoice of an older version without the id still counts for its instalment.
        $made->forceFill(['installment_id' => null])->save();
        $this->assertSame(0, $invoices->pendingGroups($event, $deposit)->count());
        $this->assertTrue(Invoice::query()->forInstallment($deposit)->whereKey($made->id)->exists());
    }

    public function test_an_invoice_run_takes_the_instalment_by_its_position_in_the_plan(): void
    {
        $event = $this->event();
        $balance = $event->installments()->create(['key' => 'balance', 'label' => 'Balance', 'kind' => 'rest', 'due_days' => 14, 'sort' => 2]);
        $deposit = $event->installments()->create(['key' => 'deposit', 'label' => 'Deposit', 'kind' => 'fixed', 'amount_cents' => 10000, 'due_days' => 14, 'sort' => 1]);
        $member = $event->installments()->create(['key' => 'third', 'label' => 'Third', 'kind' => 'fixed', 'amount_cents' => 500, 'sort' => 3]);
        foreach (['pos:1' => $deposit, 'pos:2' => $balance, 'pos:3' => $member, 'pos:last' => $member, 'pos:4' => null, 'balance' => $balance, '' => $deposit, 'unknown' => null] as $setting => $expected) {
            $this->assertSame($expected?->id, InstallmentPosition::resolve($event, $setting)?->id, "setting {$setting}");
        }
        // The standard automations: the first and the last instalment.
        $this->assertSame(['pos:1', 'pos:last'], [Automation::query()->where('key', 'deposit_invoices')->firstOrFail()->setting('installment'), Automation::query()->where('key', 'balance_invoices')->firstOrFail()->setting('installment')]);

        $child = Person::factory()->create(['birth_date' => now()->subYears(10)->toDateString()]);
        Participation::query()->create(['event_id' => $event->id, 'person_id' => $child->id, 'role_key' => 'child', 'status' => 'registered', 'registered_at' => now()]);
        $run = Automation::query()->where('key', 'deposit_invoices')->firstOrFail();
        $this->assertSame(['Deposit'], array_column(app(Engine::class)->preview($run, $event), 'details'));
        $run->update(['config' => ['installment' => 'pos:last', 'send' => false]]);
        $this->assertSame(['Third'], array_column(app(Engine::class)->preview($run->refresh(), $event), 'details'));
        $run->update(['config' => ['installment' => 'balance', 'send' => false]]); // a key of an older version
        $this->assertSame(['Balance'], array_column(app(Engine::class)->preview($run->refresh(), $event), 'details'));
        $this->assertInstanceOf(EventInstallment::class, InstallmentPosition::resolve($event, 'balance'));
    }
}
