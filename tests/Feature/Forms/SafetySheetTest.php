<?php

namespace Tests\Feature\Forms;

use App\Models\AuditEvent;
use App\Models\Form;
use App\Models\FormField;
use App\Models\Household;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Forms\Forms;
use App\Support\Install\Defaults;
use Filament\Http\Middleware\AuthenticateSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SafetySheetTest extends TestCase
{
    use RefreshDatabase;

    private Form $sheet;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        app(Defaults::class)->install('en');
        $this->sheet = Form::query()->where('key', 'safety_sheet')->with('fields')->firstOrFail();
    }

    /** @return array{0: User, 1: Person, 2: Household} */
    private function family(int $childAge = 10): array
    {
        $guardian = Person::factory()->create(['first_name' => 'Robin', 'last_name' => 'Example']);
        $guardian->contactPoints()->create(['type' => 'phone', 'value' => '+43 660 1234567', 'purposes' => ['emergency']]);
        $user = User::query()->create(['name' => 'Robin', 'email' => 'robin@example.org', 'password' => 'x', 'person_id' => $guardian->id]);
        $child = Person::factory()->create(['first_name' => 'Kim', 'last_name' => 'Example', 'birth_date' => now()->subYears($childAge)->subDay()]);
        $child->guardians()->attach($guardian->id);
        $household = Household::factory()->create();
        $household->members()->attach($guardian->id, ['role' => 'guardian']);
        $household->members()->attach($child->id, ['role' => 'child']);

        return [$user, $child, $household];
    }

    /** @return array<string, mixed> */
    private function answers(): array
    {
        return [
            'diet' => 'vegetarian', 'intolerances' => FormField::NONE, 'allergies' => 'Peanuts', 'medical_needs' => FormField::NONE,
            'tetanus' => '2024', 'tbe' => 'unknown', 'swimmer' => 'yes', 'photos_internal' => 'yes', 'photos_public' => 'no',
            'medication' => [['name' => 'Antihistamine', 'dose' => '1 tablet', 'time' => 'if stung', 'given_by' => 'camp leader']],
            'notes' => 'Only this year',
        ];
    }

    public function test_default_forms_are_installed_with_help_texts(): void
    {
        $this->assertSame(16, $this->sheet->applies_until_age);
        $this->assertGreaterThanOrEqual(15, $this->sheet->fields->count());
        $this->assertTrue($this->sheet->fields->every(fn (FormField $f) => $f->helpText() !== null));
        $general = Form::query()->where('key', 'general_information')->firstOrFail();
        $this->assertTrue($general->fields()->where('key', 'tshirt_size')->exists());
        $this->assertTrue($general->fields()->where('key', 'needs_new_tshirts')->exists());
    }

    public function test_validation_rejects_invalid_vaccination_years(): void
    {
        $rules = app(Forms::class)->rules($this->sheet);
        $this->assertTrue(validator(['values' => $this->answers()], $rules)->passes());
        $this->assertFalse(validator(['values' => ['tetanus' => '13.13.2017'] + $this->answers()], $rules)->passes());
        $this->assertFalse(validator(['values' => array_diff_key($this->answers(), ['allergies' => 1])], $rules)->passes());
    }

    public function test_versions_status_carry_over_and_confidential_audit(): void
    {
        [, $child] = $this->family();
        $forms = app(Forms::class);
        $this->assertSame('missing', $forms->status($this->sheet, $child));

        $forms->save($this->sheet, $child, $this->answers(), null);
        $this->assertSame('confirmed', $forms->status($this->sheet, $child));

        // Values are encrypted in the database and never in the audit log.
        $raw = (string) DB::table('form_responses')->value('values');
        $this->assertStringNotContainsString('Peanuts', $raw);
        $this->assertStringNotContainsString('Peanuts', (string) json_encode(AuditEvent::query()->get()->toArray()));

        $this->travel(366)->days();
        $this->assertSame('expired', $forms->status($this->sheet, $child));
        $prefill = $forms->prefill($this->sheet, $child);
        $this->assertSame('Peanuts', $prefill['allergies']);
        $this->assertArrayNotHasKey('notes', $prefill);

        $forms->save($this->sheet, $child, ['allergies' => 'Peanuts, bee stings'] + $prefill, null);
        $latest = $forms->current($this->sheet, $child);
        $this->assertNotNull($latest);
        $this->assertContains('allergies', $latest->changed_keys ?? []);
        $this->assertSame(2, DB::table('form_responses')->count());
    }

    public function test_safety_sheet_does_not_apply_from_the_age_limit(): void
    {
        [$user, $child] = $this->family(childAge: 16);
        $this->assertSame('not_applicable', app(Forms::class)->status($this->sheet, $child));
        $this->actingAs($user)->get('/portal/children/'.$child->public_id.'/forms/safety_sheet')->assertNotFound();
    }

    public function test_emergency_contacts_start_with_the_legal_guardians_and_are_family_wide(): void
    {
        [$user, $child, $household] = $this->family();
        $this->actingAs($user)->post('/portal/families/'.$household->public_id.'/emergency-contacts', [
            'contacts' => [['name' => 'Alex Grand', 'relation' => 'grandparent', 'phone' => '+43 1 234']],
        ])->assertRedirect('/portal');

        $contacts = app(Forms::class)->emergencyContacts($child);
        $this->assertSame('Robin Example', $contacts[0]['name']);
        $this->assertSame('+43 660 1234567', $contacts[0]['phone']);
        $this->assertSame('Alex Grand', $contacts[1]['name']);
    }

    public function test_guardian_fills_in_and_confirms_in_the_portal(): void
    {
        [$user, $child] = $this->family();
        $url = '/portal/children/'.$child->public_id.'/forms/safety_sheet';
        $this->actingAs($user)->get($url)->assertOk()->assertSee('Known allergies')->assertSee('Robin Example');
        $this->post($url, ['values' => $this->answers()])->assertRedirect('/portal/children/'.$child->public_id);
        $this->get('/portal/children/'.$child->public_id)->assertSee('Confirmed');

        $this->travel(400)->days();
        $this->get($url)->assertSee('Confirm unchanged');
        $this->post($url.'/confirm')->assertRedirect();
        $this->assertSame('confirmed', app(Forms::class)->status($this->sheet, $child));
        $this->assertTrue(AuditEvent::query()->where('action', 'form.confirmed_unchanged')->exists());
    }

    public function test_only_safety_role_and_administrators_see_safety_data_in_the_admin_interface(): void
    {
        [, $child] = $this->family();
        app(Forms::class)->save($this->sheet, $child, $this->answers(), null);

        $make = function (string $role, string $email): User {
            $person = Person::factory()->create();
            $person->roleAssignments()->create(['role_id' => Role::query()->where('key', $role)->value('id')]);

            return User::query()->create(['name' => $role, 'email' => $email, 'password' => 'x', 'person_id' => $person->id]);
        };
        // Filament checks the password hash of the session; these users are logged in directly in the test.
        $this->withoutMiddleware(AuthenticateSession::class);
        $board = $make('board', 'board@example.org');
        $safety = $make('safety_officer', 'safety@example.org');

        $this->actingAs($board)->withSession(['two_factor_passed' => true])
            ->get('/admin/people/'.$child->id)->assertOk()->assertDontSee('Peanuts');
        $this->actingAs($safety)->withSession(['two_factor_passed' => true])
            ->get('/admin/people/'.$child->id)->assertOk()->assertSee('Safety sheet');
        $this->assertTrue(AuditEvent::query()->where('action', 'form.viewed')->exists());
    }
}
