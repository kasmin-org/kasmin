<?php

namespace Tests\Feature\System;

use App\Casts\OneText;
use App\Models\Form;
use App\Models\MailTemplate;
use App\Models\MembershipType;
use App\Models\Person;
use App\Models\User;
use App\Support\Install\Defaults;
use App\Support\Language;
use App\Support\RuntimeSettings;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LocaleAndTimezoneTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        date_default_timezone_set('UTC');
        parent::tearDown();
    }

    private function user(string $locale): User
    {
        app(Defaults::class)->install('en');
        $person = Person::factory()->create(['locale' => $locale]);

        return User::query()->create(['name' => 'x', 'email' => 'x@example.org', 'password' => 'x', 'person_id' => $person->id]);
    }

    public function test_the_installation_has_one_language_for_everybody(): void
    {
        $user = $this->user('de'); // what is stored for the person no longer counts
        Settings::set('default_locale', 'en');
        $this->get('/login', ['Accept-Language' => 'de-AT,de;q=0.9'])->assertSee('<html lang="en"', false);
        $this->actingAs($user)->get('/portal')->assertOk()->assertSee('lang="en"', false);
        $this->assertSame('en', Person::query()->findOrFail($user->person_id)->locale);

        Settings::set('default_locale', 'de');
        $this->get('/portal')->assertOk()->assertSee('lang="de"', false);
        $this->assertSame('de', Person::query()->findOrFail($user->person_id)->locale);
    }

    public function test_nobody_can_switch_the_language_for_themselves(): void
    {
        $user = $this->user('en');
        Settings::set('default_locale', 'de');
        $this->get('/login')->assertOk()->assertDontSee('English')->assertDontSee('name="locale"', false);
        $this->post('/language', ['locale' => 'en'])->assertNotFound();
        $this->actingAs($user)->withSession(['locale' => 'en'])->get('/portal')->assertSee('lang="de"', false)->assertDontSee('name="locale"', false);
        $this->openSignUp();
        $this->followingRedirects()->get('/register')->assertOk()->assertDontSee('name="locale"', false);
    }

    public function test_only_administrators_change_the_language_and_entered_texts_stay(): void
    {
        $this->user('en');
        $form = Form::query()->where('key', 'safety_sheet')->firstOrFail();
        $name = $form->label();
        $subject = MailTemplate::query()->where('key', 'invoice')->firstOrFail()->content()?->subject;
        $this->assertSame(1, MailTemplate::query()->where('key', 'invoice')->firstOrFail()->contents()->count(), 'a template has one text');

        Settings::set('default_locale', 'de');
        Language::apply();
        $this->assertSame('de', app()->getLocale());
        $this->assertSame($name, $form->fresh()?->label(), 'what was entered is not translated');
        $this->assertSame($subject, MailTemplate::query()->where('key', 'invoice')->firstOrFail()->content()?->subject);
    }

    public function test_texts_per_language_of_older_versions_become_one_text(): void
    {
        $this->user('en');
        Settings::set('default_locale', 'de');
        $form = Form::query()->where('key', 'safety_sheet')->firstOrFail();
        $field = $form->fields()->where('key', 'diet')->firstOrFail();
        // As the versions up to 0.27 stored it.
        DB::table('forms')->where('id', $form->id)->update(['name' => json_encode(['de' => 'Sicherheitsblatt', 'en' => 'Safety sheet']), 'description' => json_encode(['de' => '', 'en' => 'Only English'])]);
        DB::table('form_fields')->where('id', $field->id)->update(['label' => json_encode(['de' => 'Ernährung', 'en' => 'Diet']), 'options' => json_encode(['choices' => ['vegan' => ['de' => 'Vegan (DE)', 'en' => 'Vegan (EN)'], 'plain' => 'Plain'], 'other' => true])]);
        $template = MailTemplate::query()->where('key', 'invoice')->firstOrFail();
        $template->contents()->delete();
        DB::table('mail_template_contents')->insert([
            ['mail_template_id' => $template->id, 'locale' => 'de', 'subject' => 'Rechnung', 'html' => '<p>Deutsch</p>', 'created_at' => now(), 'updated_at' => now()],
            ['mail_template_id' => $template->id, 'locale' => 'en', 'subject' => 'Invoice', 'html' => '<p>English</p>', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $template->versions()->delete();

        (require database_path('migrations/2026_12_20_000000_one_language_per_installation.php'))->up();

        $this->assertSame('"Sicherheitsblatt"', DB::table('forms')->where('id', $form->id)->value('name'));
        $this->assertSame(['Sicherheitsblatt', 'Only English'], [$form->fresh()?->label(), $form->fresh()?->descriptionText()], 'an empty text falls back to the other language');
        $field->refresh();
        $this->assertSame('Ernährung', $field->labelText());
        $this->assertSame(['vegan' => 'Vegan (DE)', 'plain' => 'Plain'], $field->options['choices'] ?? null);
        $this->assertTrue($field->allowsOther());
        $this->assertSame(['Rechnung'], $template->contents()->pluck('subject')->all());
        $kept = $template->versions()->sole()->snapshot['contents'] ?? [];
        $this->assertSame(['Rechnung', 'Invoice'], array_column($kept, 'subject'), 'the text of the other language is kept in the history');
        // Running it again changes nothing.
        (require database_path('migrations/2026_12_20_000000_one_language_per_installation.php'))->up();
        $this->assertSame('Ernährung', $field->fresh()?->labelText());
    }

    public function test_one_text_is_stored_and_two_language_input_is_still_understood(): void
    {
        $this->user('en');
        $type = MembershipType::query()->create(['key' => 'friend', 'name' => ['de' => 'Freund:in', 'en' => 'Friend'], 'pays_fee' => false, 'sort' => 99]);
        $this->assertSame('Friend', $type->refresh()->name);
        $type->update(['name' => 'Supporter & friend']);
        $this->assertSame('"Supporter & friend"', DB::table('membership_types')->where('id', $type->id)->value('name'));
        $this->assertSame('Supporter & friend', $type->refresh()->label());
        $this->assertSame(['name' => 'B', 'fields' => [['label' => 'D', 'options' => ['choices' => ['x' => 'F', 'y' => 'plain']]]]], OneText::collapse(
            ['name' => ['de' => 'A', 'en' => 'B'], 'fields' => [['label' => ['de' => 'C', 'en' => 'D'], 'options' => ['choices' => ['x' => ['de' => 'E', 'en' => 'F'], 'y' => 'plain']]]]],
            ['name', 'fields.*.label', 'fields.*.options.choices.*'],
        ));
        // A second text for a template is not kept: the one in the language of the installation stays.
        $template = MailTemplate::query()->where('key', 'invoice')->firstOrFail();
        $template->contents()->create(['locale' => 'de', 'subject' => 'Zweite', 'html' => '<p>x</p>']);
        $this->assertSame(['en'], $template->contents()->pluck('locale')->all());
    }

    public function test_time_zone_of_the_settings_is_applied(): void
    {
        Settings::set('timezone', 'Europe/Vienna');
        RuntimeSettings::apply();
        $this->assertSame('Europe/Vienna', now()->getTimezone()->getName());
        Settings::set('timezone', 'Nowhere/Invalid');
        RuntimeSettings::apply();
        $this->assertSame('Europe/Vienna', date_default_timezone_get());
    }
}
