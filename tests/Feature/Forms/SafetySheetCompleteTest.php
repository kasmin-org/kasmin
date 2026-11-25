<?php

namespace Tests\Feature\Forms;

use App\Filament\Pages\EventListsPage;
use App\Filament\Resources\Forms\Pages\EditForm;
use App\Filament\Resources\Forms\Pages\ListForms;
use App\Models\ApiToken;
use App\Models\Event;
use App\Models\Form;
use App\Models\FormField;
use App\Models\Household;
use App\Models\OutgoingMail;
use App\Models\Participation;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Support\Api\ApiContext;
use App\Support\Forms\Formats;
use App\Support\Forms\FormDefinitions;
use App\Support\Forms\Forms;
use App\Support\Forms\FormValue;
use App\Support\Forms\SummaryPdf;
use App\Support\Install\Defaults;
use App\Support\Install\FormDefaults;
use App\Support\Settings;
use App\Support\Webhooks\Webhooks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Field types, formats, confirmation period, mails, reminders, webhook, exchange of forms (SF02–SF28).
 */
class SafetySheetCompleteTest extends TestCase
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

    /** @return array{0: User, 1: Person, 2: Person, 3: Household} user, guardian, child, household */
    private function family(): array
    {
        $guardian = Person::factory()->create(['first_name' => 'Robin', 'last_name' => 'Example', 'locale' => 'en', 'birth_date' => '1985-05-05']);
        $guardian->contactPoints()->create(['type' => 'email', 'value' => 'robin@example.org', 'is_primary' => true, 'verified_at' => now()]);
        $user = User::query()->create(['name' => 'Robin', 'email' => 'robin@example.org', 'password' => 'x', 'person_id' => $guardian->id]);
        $household = Household::factory()->create();
        $household->members()->attach($guardian->id, ['role' => 'guardian']);

        return [$user, $guardian, $this->child($household, [$guardian], 'Kim'), $household];
    }

    /** @param  list<Person>  $guardians */
    private function child(Household $household, array $guardians, string $name): Person
    {
        $child = Person::factory()->create(['first_name' => $name, 'last_name' => 'Example', 'birth_date' => now()->subYears(10)->subDay()]);
        $household->members()->attach($child->id, ['role' => 'child']);
        foreach ($guardians as $guardian) {
            $child->guardians()->attach($guardian->id);
        }

        return $child;
    }

    /** @return array<string, mixed> */
    private function answers(): array
    {
        return ['diet' => 'vegan', 'intolerances' => FormField::NONE, 'allergies' => 'Peanuts', 'medical_needs' => FormField::NONE, 'tetanus' => '2024', 'tbe' => 'no', 'swimmer' => 'yes', 'photos_internal' => 'yes', 'photos_public' => 'no', 'insurance_number' => '1237010180'];
    }

    private function event(Person $child, ?int $days, int $startsIn = 90): Event
    {
        $event = Event::query()->create(['title' => 'Camp', 'status' => 'open', 'starts_at' => now()->addDays($startsIn), 'ends_at' => now()->addDays($startsIn + 7), 'safety_confirm_days' => $days]);
        Participation::query()->create(['event_id' => $event->id, 'person_id' => $child->id, 'role_key' => 'child', 'status' => 'registered', 'registered_at' => now()]);

        return $event;
    }

    private function login(string $role): User
    {
        auth()->forgetUser();
        $person = Person::factory()->create();
        $person->roleAssignments()->create(['role_id' => Role::query()->where('key', $role)->value('id')]);
        $user = User::query()->create(['name' => $role, 'email' => $role.'@example.org', 'password' => 'x', 'person_id' => $person->id]);
        $this->actingAs($user)->withSession(['two_factor_passed' => true]);

        return $user;
    }

    public function test_formats_check_the_check_digit(): void
    {
        $this->assertTrue(Formats::valid('at_social_insurance', '1237 010180'));
        $this->assertFalse(Formats::valid('at_social_insurance', '1238 010180'));
        $this->assertTrue(Formats::valid('de_health_insurance', 'A123456780'));
        $this->assertFalse(Formats::valid('de_health_insurance', 'A123456789'));
        $this->assertTrue(Formats::valid('ch_ahv', '756.9217.0769.85'));
        $this->assertFalse(Formats::valid('ch_ahv', '756.9217.0769.84'));
        Formats::register('test', fn (string $v) => $v === 'ABC');
        $this->assertTrue(Formats::valid('test', 'a-b c'));
        $this->assertContains('test', Formats::keys());
    }

    public function test_choices_with_other_numbers_dates_and_formats(): void
    {
        [$user, , $child] = $this->family();
        $form = Form::query()->create(['key' => 'extra', 'name' => ['de' => 'Extra', 'en' => 'Extra']]);
        $form->fields()->createMany([
            ['key' => 'carrier', 'type' => 'select', 'label' => ['en' => 'Carrier'], 'options' => ['list' => 'carriers_at', 'other' => true], 'required' => true, 'sort' => 1],
            ['key' => 'sports', 'type' => 'multiselect', 'label' => ['en' => 'Sports'], 'options' => ['choices' => ['run' => ['en' => 'Running']], 'other' => true], 'sort' => 2],
            ['key' => 'height', 'type' => 'number', 'label' => ['en' => 'Height'], 'options' => ['min' => 50, 'max' => 230], 'sort' => 3],
            ['key' => 'since', 'type' => 'date', 'label' => ['en' => 'Since'], 'options' => ['min' => '2020-01-01'], 'sort' => 4],
            ['key' => 'number', 'type' => 'text', 'label' => ['en' => 'Number'], 'options' => ['format' => 'at_social_insurance'], 'sort' => 5],
        ]);
        $url = '/portal/children/'.$child->public_id.'/forms/extra';
        $this->actingAs($user)->get($url)->assertOk()->assertSee('ÖGK')->assertSee('Other');

        $this->post($url, ['values' => ['carrier' => FormField::OTHER, 'height' => '20', 'since' => '2019-12-31', 'number' => '1238010180']])
            ->assertSessionHasErrors(['values.carrier', 'values.height', 'values.since', 'values.number']);
        $this->post($url, ['values' => ['carrier' => 'unknown']])->assertSessionHasErrors('values.carrier');
        $this->post($url, ['values' => ['carrier' => FormField::OTHER, 'sports' => ['run', FormField::OTHER], 'height' => '140', 'since' => '2020-01-01', 'number' => '1237010180'], 'other' => ['carrier' => 'World Health', 'sports' => 'Chess']])->assertSessionHasNoErrors();

        $values = app(Forms::class)->current($form, $child)->values ?? [];
        $this->assertSame(FormField::OTHER.'World Health', $values['carrier']);
        $texts = $form->fields()->get()->mapWithKeys(fn (FormField $f) => [$f->key => FormValue::text($f, $values[$f->key])]);
        $this->assertSame('World Health', $texts['carrier']);
        $this->assertSame('Running; Chess', $texts['sports']);
        $this->get($url)->assertSee('World Health')->assertSee('Chess');
    }

    public function test_co_insured_person_is_a_guardian_or_free_and_the_number_is_taken_over(): void
    {
        [$user, $guardian, $child, $household] = $this->family();
        $sibling = $this->child($household, [$guardian], 'Lou');
        $url = fn (Person $p) => '/portal/children/'.$p->public_id.'/forms/safety_sheet';
        $stranger = Person::factory()->create();

        $this->actingAs($user)->post($url($child), ['values' => ['co_insured_with' => ['person' => $stranger->public_id]] + $this->answers()])->assertSessionHasErrors('values.co_insured_with.person');
        $this->post($url($child), ['values' => ['co_insured_with' => ['person' => $guardian->public_id, 'number' => '1237010180']] + $this->answers()])->assertSessionHasNoErrors();
        $field = $this->sheet->fields->firstWhere('key', 'co_insured_with');
        $this->assertNotNull($field);
        $value = app(Forms::class)->current($this->sheet, $child)->values['co_insured_with'] ?? null;
        $this->assertSame('Robin Example (1237010180)', FormValue::text($field, $value));

        // The number already given for a sibling is offered.
        $this->get($url($sibling))->assertSee('data-number="1237010180"', false);

        // Another guardian of the sibling who is not responsible for this child does not get the number.
        $other = Person::factory()->create();
        $sibling->guardians()->attach($other->id);
        $this->assertNull(app(Forms::class)->personOptions($this->sheet, $field, $sibling, $other)[0]['number']);

        $this->post($url($sibling), ['values' => ['co_insured_with' => ['person' => FormField::OTHER]] + $this->answers(), 'other' => ['co_insured_with' => 'Alex Aunt']])->assertSessionHasNoErrors();
        $this->assertSame(['name' => 'Alex Aunt'], app(Forms::class)->current($this->sheet, $sibling)->values['co_insured_with'] ?? null);
    }

    public function test_vaccination_years_accept_the_words_of_the_language(): void
    {
        [$user, $guardian, $child] = $this->family();
        $guardian->update(['locale' => 'de']);
        $url = '/portal/children/'.$child->public_id.'/forms/safety_sheet';
        $this->actingAs($user)->withSession(['locale' => 'de'])->get($url)->assertSee('„nein“ oder „unbekannt“', false);
        $this->post($url, ['values' => ['tetanus' => 'Nein', 'tbe' => 'unbekannt'] + $this->answers()])->assertSessionHasNoErrors();
        $values = app(Forms::class)->current($this->sheet, $child)->values ?? [];
        $this->assertSame(['no', 'unknown'], [$values['tetanus'], $values['tbe']]);
    }

    public function test_default_form_has_carriers_and_number_format_of_the_country(): void
    {
        $this->assertSame('text', $this->sheet->fields->firstWhere('key', 'insurance_carrier')?->type);
        $this->sheet->delete();
        Settings::set('timezone', 'Europe/Vienna');
        app(FormDefaults::class)->install();
        $sheet = Form::query()->where('key', 'safety_sheet')->with('fields')->firstOrFail();
        $this->assertContains('ÖGK', $sheet->fields->firstWhere('key', 'insurance_carrier')?->choices() ?? []);
        $this->assertSame('at_social_insurance', $sheet->fields->firstWhere('key', 'insurance_number')->options['format'] ?? null);
    }

    public function test_guardians_choose_the_order_of_the_emergency_contacts(): void
    {
        [$user, $guardian, $child, $household] = $this->family();
        $second = Person::factory()->create(['first_name' => 'Sam', 'last_name' => 'Second']);
        $household->members()->attach($second->id, ['role' => 'guardian']);
        $child->guardians()->attach($second->id);
        $forms = app(Forms::class);
        $this->assertSame('Robin Example', $forms->emergencyContacts($child)[0]['name']);

        $url = '/portal/families/'.$household->public_id.'/emergency-contacts';
        $this->actingAs($user)->get($url)->assertOk()->assertSee('Sam Second');
        $this->post($url, ['order' => [$guardian->public_id => 2, $second->public_id => 1]])->assertRedirect('/portal');
        $this->assertSame(['Sam Second', 'Robin Example'], array_column($forms->emergencyContacts($child), 'name'));
        $this->assertSame('Sam', $forms->orderedGuardians($household)[0]->first_name);
    }

    public function test_confirmation_period_per_event(): void
    {
        [, , $child] = $this->family();
        app(Forms::class)->save($this->sheet, $child, $this->answers(), null);
        $event = $this->event($child, 60);
        $seen = [];
        $look = function () use (&$seen, $child): void {
            $seen[] = app(Forms::class)->status($this->sheet, $child);
        };
        $look(); // the period has not started yet
        $this->travelTo($event->starts_at->copy()->subDays(59));
        $look();
        app(Forms::class)->save($this->sheet, $child, $this->answers(), null);
        $look();
        $this->travelTo($event->ends_at->copy()->addDay());
        $look();
        $this->assertSame(['confirmed', 'expired', 'confirmed', 'confirmed'], $seen);
    }

    public function test_thank_you_mail_and_summary_only_on_request_without_numbers_and_never_stored(): void
    {
        [$user, $guardian, $child] = $this->family();
        $url = '/portal/children/'.$child->public_id.'/forms/safety_sheet';
        $this->actingAs($user)->post($url, ['values' => $this->answers()])->assertSessionHasNoErrors();
        $mail = OutgoingMail::query()->where('subject', 'Thank you: safety sheet confirmed')->sole();
        $this->assertNull($mail->attachments);
        $this->assertStringNotContainsString('Peanuts', $mail->html.$mail->text);

        $this->post($url, ['values' => $this->answers(), 'summary' => 'without_numbers']);
        $mail = OutgoingMail::query()->where('subject', 'Thank you: safety sheet confirmed')->latest('id')->firstOrFail();
        $this->assertSame('robin@example.org', $mail->to_address);
        $this->assertStringNotContainsString('Peanuts', (string) json_encode($mail->getAttributes()));
        $id = (int) ($mail->attachments[0]['form_response_id'] ?? 0);
        $summary = app(SummaryPdf::class);
        $this->assertStringStartsWith('%PDF', (string) ($summary->for($mail, $id)['content'] ?? ''));

        $rows = view('pdf.form-summary', ['form' => $this->sheet, 'person' => $child, 'response' => app(Forms::class)->current($this->sheet, $child), 'contacts' => [], 'rows' => $this->sheet->fields->reject(fn (FormField $f) => $f->sensitivity === 'identity')->map(fn (FormField $f) => ['label' => $f->labelText(), 'value' => 'v'])->all()])->render();
        $this->assertStringNotContainsString('Insurance number', $rows);

        // Never to another address, a person who is no longer a guardian, or later.
        $this->assertNull($summary->for((clone $mail)->forceFill(['to_address' => 'else@example.org']), $id));
        $this->assertNull($summary->for((clone $mail)->forceFill(['person_id' => Person::factory()->create()->id]), $id));
        $child->guardians()->updateExistingPivot($guardian->id, ['ends_on' => now()->subDay()]);
        $this->assertNull($summary->for($mail, $id));
        $child->guardians()->newPivotStatement()->update(['ends_on' => null]);
        $this->travel(3)->days();
        $this->assertNull($summary->for($mail, $id));
    }

    public function test_one_click_reminder_to_selected_families(): void
    {
        [, $guardian, $child] = $this->family();
        $event = $this->event($child, null);
        $this->login('camp_leader')->person?->roleAssignments()->toBase()->update(['event_id' => $event->id]);
        $key = 'household:'.$child->households()->firstOrFail()->id;
        $page = Livewire::test(EventListsPage::class)->set('event', $event->id)->set('list', 'missing')->assertSee('Kim Example')->set('selected', [$key])->call('remind');
        $this->assertSame(1, OutgoingMail::query()->where('person_id', $guardian->id)->where('subject', 'like', 'Reminder: safety sheet%')->count());
        $page->set('selected', [$key])->call('remind');
        $this->assertSame(1, OutgoingMail::query()->where('person_id', $guardian->id)->count(), 'not twice within hours');

        $this->login('auditor');
        Livewire::test(EventListsPage::class)->assertForbidden();
    }

    public function test_webhook_tells_that_a_sheet_was_confirmed_without_values(): void
    {
        [, , $child] = $this->family();
        Webhook::query()->create(['name' => 'x', 'url' => 'https://example.org/hook', 'secret' => 's', 'events' => ['form.confirmed'], 'active' => true]);
        Webhooks::flush();
        app(Forms::class)->save($this->sheet, $child, $this->answers(), null, confirm: false);
        $this->assertSame(0, WebhookDelivery::query()->count());
        app(Forms::class)->save($this->sheet, $child, $this->answers(), null);
        $payload = (string) json_encode(WebhookDelivery::query()->sole()->payload);
        $this->assertStringContainsString($child->public_id, $payload);
        $this->assertStringNotContainsString('Peanuts', $payload);
        $this->assertStringNotContainsString('allergies', $payload);
        Webhooks::flush();
    }

    public function test_forms_can_be_exported_and_imported(): void
    {
        [, , $child] = $this->family();
        app(Forms::class)->save($this->sheet, $child, $this->answers(), null);
        $definitions = app(FormDefinitions::class);
        $export = $definitions->export($this->sheet);
        $this->assertStringNotContainsString('Peanuts', (string) json_encode($export));

        $copy = $definitions->import(['key' => 'copy'] + $export);
        $this->assertSame($this->sheet->fields->pluck('key')->all(), $copy->fields->pluck('key')->all());
        $this->assertSame($this->sheet->fields->firstWhere('key', 'diet')?->options, $copy->fields->firstWhere('key', 'diet')?->options);

        $version = $this->sheet->version;
        $export['fields'][0]['label']['en'] = 'Insurer';
        $definitions->import($export);
        $this->assertSame($version + 1, $this->sheet->fresh()?->version);
        $this->assertSame('Peanuts', app(Forms::class)->current($this->sheet, $child)->values['allergies'] ?? null);

        $this->expectException(ValidationException::class);
        $definitions->import(['fields' => [['key' => 'x', 'type' => 'script', 'label' => ['en' => 'x'], 'sensitivity' => 'normal']]] + $export);
    }

    public function test_form_rights_do_not_open_health_data(): void
    {
        [, , $child] = $this->family();
        $allergies = FormField::query()->where('form_id', $this->sheet->id)->where('key', 'allergies')->firstOrFail();
        $allergies->update(['sensitivity' => 'identity']); // still confidential
        app(Forms::class)->save($this->sheet, $child, $this->answers(), null);
        $this->login('board');
        foreach ([
            fn () => $allergies->fresh()?->update(['sensitivity' => 'normal']),
            fn () => $allergies->fresh()?->update(['key' => 'renamed']),
            fn () => $allergies->fresh()?->delete(),
            fn () => $this->sheet->fresh()?->update(['family_emergency_contacts' => false]),
            fn () => $this->sheet->fresh()?->delete(),
        ] as $i => $attempt) {
            try {
                $attempt();
                $this->fail('attempt '.$i.' was allowed');
            } catch (ValidationException) {
                $this->assertSame('identity', $allergies->fresh()?->sensitivity);
            }
        }
        FormField::query()->findOrFail($allergies->id)->update(['label' => ['de' => 'Allergien', 'en' => 'Allergies!']]); // ordinary changes stay possible
        $this->login('safety_officer');
        FormField::query()->findOrFail($allergies->id)->update(['sensitivity' => 'normal']);
        $this->assertSame('normal', FormField::query()->whereKey($allergies->id)->value('sensitivity'));
    }

    public function test_builder_keeps_both_languages_and_all_settings_of_a_field(): void
    {
        $this->login('administrator');
        $before = $this->sheet->fields->mapWithKeys(fn (FormField $f) => [$f->key => $f->options])->all();
        Livewire::test(EditForm::class, ['record' => $this->sheet->id])->assertOk()->call('save')->assertHasNoFormErrors();
        $after = FormField::query()->where('form_id', $this->sheet->id)->get()->keyBy('key');
        $this->assertEquals($before['diet']['choices'] ?? null, $after['diet']->options['choices'] ?? null);
        $this->assertEquals($before['medication']['fields'] ?? null, $after['medication']->options['fields'] ?? null);
        $this->assertTrue((bool) ($after['co_insured_with']->options['number'] ?? false));
        Livewire::test(ListForms::class)->assertOk()->assertActionVisible('import')->assertTableActionVisible('export', $this->sheet);
    }

    public function test_definitions_by_api_need_the_right_scopes(): void
    {
        [, , $child] = $this->family();
        app(Forms::class)->save($this->sheet, $child, $this->answers(), null);
        $person = Person::factory()->create();
        $person->roleAssignments()->create(['role_id' => Role::query()->where('key', 'administrator')->value('id')]);
        $admin = User::query()->create(['name' => 'Admin', 'email' => 'admin@example.org', 'password' => 'x', 'person_id' => $person->id]);
        $token = fn (array $scopes): string => ApiToken::issue($admin, 't', array_values(array_map('strval', $scopes)))['plain'];
        $read = $token(['people:read']);
        $definition = $this->withToken($read)->getJson('/api/v1/forms/safety_sheet/definition')->assertOk()->json('data');
        $this->withToken($read)->putJson('/api/v1/forms/safety_sheet/definition', $definition)->assertForbidden();
        $settings = $token(['settings:write']);
        $this->withToken($settings)->putJson('/api/v1/forms/safety_sheet/definition', $definition)->assertOk();
        $this->assertNull(ApiContext::token(), 'the token is only known during the request');
        foreach ($definition['fields'] as $i => $field) {
            $definition['fields'][$i]['sensitivity'] = 'normal';
        }
        $this->withToken($settings)->putJson('/api/v1/forms/safety_sheet/definition', $definition)->assertUnprocessable();
        $this->assertSame('health', FormField::query()->where('form_id', $this->sheet->id)->where('key', 'allergies')->value('sensitivity'));
    }
}
