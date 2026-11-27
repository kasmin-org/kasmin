<?php

namespace Tests\Feature\Events;

use App\Filament\Resources\PublicForms\Pages\ListPublicForms;
use App\Filament\Resources\PublicForms\PublicFormResource;
use App\Models\Person;
use App\Models\PublicForm;
use App\Models\Role;
use App\Models\User;
use App\Support\Install\Defaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** The address of a public form (B15): normalised, shown in full while typing, earlier addresses forward. */
class PublicFormAddressTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(Defaults::class)->install('en');
        app()->setLocale('en');
    }

    private function admin(): User
    {
        $person = Person::factory()->create(['locale' => 'en']);
        $person->roleAssignments()->create(['role_id' => Role::query()->where('key', 'administrator')->value('id')]);
        $user = User::query()->create(['name' => 'Admin', 'email' => 'admin@example.org', 'password' => 'x', 'person_id' => $person->id]);
        $this->actingAs($user);

        return $user;
    }

    public function test_addresses_are_normalised(): void
    {
        $this->assertSame('anmeldung-fuer-kinder', PublicForm::slugFrom('  Anmeldung für Kinder  '));
        $this->assertSame('grosse-strasse-2027', PublicForm::slugFrom('Große Straße 2027'));
        $this->assertSame('cafe-ole', PublicForm::slugFrom('Café   Olé!'));
        $this->assertSame('team-application', PublicForm::slugFrom('--Team_Application--'));
        $this->assertSame('', PublicForm::slugFrom('!!!'));
        $this->assertSame(64, strlen(PublicForm::slugFrom(str_repeat('a', 100))));
    }

    public function test_saving_normalises_and_the_earlier_address_forwards(): void
    {
        $form = PublicForm::query()->create(['slug' => 'Sommer Lager', 'kind' => 'interest', 'title' => 'Summer', 'active' => true]);
        $this->assertSame('sommer-lager', $form->slug);
        $this->get('/f/sommer-lager')->assertOk();
        $this->get('/f/Sommer-Lager')->assertRedirect('/f/sommer-lager')->assertStatus(301);

        $form->update(['slug' => 'summer-2027']);
        $this->assertSame(['sommer-lager'], $form->refresh()->old_slugs);
        $this->get('/f/sommer-lager?embed=1')->assertStatus(301)->assertRedirect('/f/summer-2027?embed=1');
        $this->get('/f/summer-2027')->assertOk();

        // A new form may take the old address; then it is its own, not a forward.
        PublicForm::query()->create(['slug' => 'sommer-lager', 'kind' => 'interest', 'title' => 'Other', 'active' => true]);
        $this->get('/f/sommer-lager')->assertOk()->assertSee('Other');
    }

    public function test_the_form_shows_the_full_address_and_refuses_taken_or_empty_ones(): void
    {
        $this->admin();
        PublicForm::query()->create(['slug' => 'taken', 'kind' => 'interest', 'title' => 'T']);

        Livewire::test(ListPublicForms::class)
            ->mountAction('create')
            ->fillForm(['kind' => 'interest', 'title' => 'Bleib informiert über Lager'])
            ->assertSchemaStateSet(['slug' => 'bleib-informiert-ueber-lager'])
            ->fillForm(['slug' => 'Taken'])
            ->callMountedAction()
            ->assertHasFormErrors(['slug']);

        Livewire::test(ListPublicForms::class)
            ->callAction('create', ['kind' => 'interest', 'title' => 'X', 'slug' => '!!!'])
            ->assertHasFormErrors(['slug']);

        Livewire::test(ListPublicForms::class)
            ->callAction('create', ['kind' => 'interest', 'title' => 'X', 'slug' => ' Ferien für Kinder '])
            ->assertHasNoFormErrors();
        $this->assertTrue(PublicForm::query()->where('slug', 'ferien-fuer-kinder')->exists());
    }

    public function test_the_preview_shows_the_full_address_and_the_forward(): void
    {
        $this->assertStringContainsString(e(url('/f/ae-oe-ue')), (string) PublicFormResource::addressPreview(' Ä Ö Ü ', null));
        $this->assertStringContainsString('Copy', (string) PublicFormResource::addressPreview('x', null));
        $this->assertStringNotContainsString('Copy', (string) PublicFormResource::addressPreview('!!!', null));
        $form = PublicForm::query()->create(['slug' => 'old', 'kind' => 'interest', 'title' => 'T']);
        $this->assertStringContainsString(e(url('/f/old')), (string) PublicFormResource::addressPreview('new', $form));
    }
}
