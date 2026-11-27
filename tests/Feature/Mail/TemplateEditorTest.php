<?php

namespace Tests\Feature\Mail;

use App\Filament\Resources\MailTemplates\Pages\EditMailTemplate;
use App\Filament\Resources\MailTemplates\Pages\ListMailTemplates;
use App\Filament\Resources\MailTemplates\Pages\PreviewMailTemplate;
use App\Models\MailTemplate;
use App\Models\MailTemplateContent;
use App\Models\OutgoingMail;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Install\Defaults;
use App\Support\Mail\Composer;
use App\Support\Mail\MailException;
use App\Support\Mail\Placeholders;
use App\Support\Mail\TemplateCatalogue;
use App\Support\Mail\TemplateHtml;
use App\Support\Mail\TemplateRenderer;
use App\Support\Mail\TemplateStandards;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Editing, versions, standard texts and preview of mail templates (MT01–MT09).
 */
class TemplateEditorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        app(Defaults::class)->install('en');
    }

    private function login(string $role = 'administrator'): User
    {
        auth()->forgetUser();
        $person = Person::factory()->create(['locale' => 'en']);
        $person->contactPoints()->create(['type' => 'email', 'value' => $role.'@example.org', 'is_primary' => true, 'verified_at' => now()]);
        $person->roleAssignments()->create(['role_id' => Role::query()->where('key', $role)->value('id')]);
        $user = User::query()->firstOrCreate(['email' => $role.'@example.org'], ['name' => $role, 'password' => 'x', 'person_id' => $person->id]);
        $this->actingAs($user)->withSession(['two_factor_passed' => true]);

        return $user;
    }

    private function template(string $key = 'safety_sheet_reminder'): MailTemplate
    {
        return MailTemplate::query()->where('key', $key)->firstOrFail();
    }

    private function html(MailTemplate $template, string $locale = 'en'): string
    {
        return (string) MailTemplateContent::query()->where('mail_template_id', $template->id)->where('locale', $locale)->value('html');
    }

    public function test_every_standard_template_survives_saving_in_the_editor(): void
    {
        $this->login();
        $renderer = app(TemplateRenderer::class);
        foreach (MailTemplate::query()->orderBy('id')->get() as $template) {
            $before = $this->html($template);
            Livewire::test(EditMailTemplate::class, ['record' => $template->id])->call('save')->assertHasNoFormErrors();
            $after = $this->html($template);
            $this->assertSame(TemplateHtml::toEditor($before), TemplateHtml::toEditor($after), $template->key);
            $this->assertSame([], $renderer->validate('x', $after, null), $template->key);
            $this->assertFalse(app(TemplateStandards::class)->differs($template->fresh() ?? $template), $template->key);
        }
    }

    public function test_links_alone_in_a_paragraph_become_buttons_in_the_colour_of_the_organisation(): void
    {
        $html = TemplateHtml::buttons('<p>Text with <a href="https://example.org/a">link</a>.</p><p><a href="https://example.org/b" style="x">Go</a></p>', '#ffdd00');
        $this->assertStringContainsString('<p>Text with <a href="https://example.org/a">link</a>.</p>', $html);
        $this->assertStringContainsString('background:#ffdd00;color:#000000', $html);
        $this->assertSame('#ffffff', TemplateHtml::textOn('#123456'));
    }

    public function test_html_source_is_saved_as_written_and_checked(): void
    {
        $this->login();
        $template = $this->template();
        $page = Livewire::test(EditMailTemplate::class, ['record' => $template->id]);
        $key = (string) array_key_first((array) $page->get('data.contents'));
        $page->set("data.contents.{$key}.source", true);
        $this->assertStringContainsString('{{ links.login }}', (string) $page->get("data.contents.{$key}.html_source"));
        $page->set("data.contents.{$key}.html_source", '<p style="color:red">{{ unknown.value }}</p>')->call('save')->assertHasFormErrors(["contents.{$key}.html_source"]);
        $page->set("data.contents.{$key}.html_source", '<p style="color:red">{{ salutation }}</p>')->call('save')->assertHasNoFormErrors();
        $this->assertSame('<p style="color:red">{{ salutation }}</p>', $this->html($template));
    }

    public function test_versions_can_be_restored_including_the_first(): void
    {
        $this->login();
        $template = $this->template();
        $original = $this->html($template);
        $template->keepFirstVersion();
        $template->contents()->where('locale', 'en')->update(['html' => '<p>New</p>']);
        $template->newVersion();
        $first = $template->versions()->reorder('version')->firstOrFail();
        Livewire::test(EditMailTemplate::class, ['record' => $template->id])->callAction('versions', ['version' => $first->id])->assertHasNoActionErrors();
        $this->assertSame($original, $this->html($template));
        $this->assertSame(3, $template->fresh()?->version);
    }

    public function test_a_new_standard_is_only_offered_and_never_overwrites(): void
    {
        $this->login();
        $standards = app(TemplateStandards::class);
        $template = $this->template();
        $this->assertFalse($standards->differs($template));

        $template->contents()->where('locale', 'en')->update(['html' => '<p>{{ salutation }}</p><p>Our own text.</p>']);
        $this->assertTrue($standards->differs($template));
        $this->assertFalse($standards->isNew($template), 'changed by the organisation, the standard is the same');
        $template->forceFill(['standard_hash' => str_repeat('0', 64)])->save(); // an update brought another standard
        $this->assertTrue($standards->isNew($template));
        app(Defaults::class)->install('en');
        $this->assertStringContainsString('Our own text', $this->html($template));

        $signs = array_column($standards->diff($template)['en'], 0);
        $this->assertContains('+', $signs);
        $this->assertContains('-', $signs);
        Livewire::test(ListMailTemplates::class)->assertOk()->assertSee('New standard')->callTableAction('standard', $template)->assertHasNoTableActionErrors();
        $this->assertFalse($standards->differs($template->fresh() ?? $template));
        $this->assertTrue($template->versions()->exists(), 'the own text can be restored');
    }

    public function test_preview_with_a_real_person_shows_no_personal_links_and_runs_no_scripts(): void
    {
        $this->login();
        $person = Person::factory()->create(['first_name' => 'Robin', 'last_name' => 'Example', 'locale' => 'en']);
        $person->contactPoints()->create(['type' => 'email', 'value' => 'robin@example.org', 'is_primary' => true]);
        User::query()->create(['name' => 'Robin', 'email' => 'robin@example.org', 'password' => 'x', 'person_id' => $person->id]);
        $template = $this->template();
        $template->contents()->where('locale', 'en')->update(['html' => '<p>{{ recipient.full_name }}</p><script>alert(1)</script><p><a href="{{ links.login }}">x</a></p>']);
        $html = Livewire::test(PreviewMailTemplate::class, ['record' => $template->id])->assertOk()->assertSee('Example, Robin')->assertSee('data-preview-person', false)->set('person', $person->id)->assertSee('Robin Example')->html();
        $this->assertStringContainsString('sandbox=""', $html);
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringNotContainsString('made-when-sending', $html);
        $this->assertStringNotContainsString('signature=', $html);
    }

    public function test_test_mail_goes_only_to_oneself(): void
    {
        $user = $this->login();
        $template = $this->template();
        Livewire::test(PreviewMailTemplate::class, ['record' => $template->id])->call('test')->assertOk();
        $mail = OutgoingMail::query()->sole();
        $this->assertSame($user->email, $mail->to_address);
        $this->assertStringStartsWith('[TEST]', $mail->subject);

        $this->login('auditor');
        $this->get('/admin/mail-templates/'.$template->id.'/preview')->assertForbidden();
    }

    public function test_layout_has_footer_and_colour(): void
    {
        Settings::set('mail_color', '#0055aa');
        Settings::set('mail_footer', "Example Camp\n<b>Street 1</b>");
        $html = app(Composer::class)->layout('S', '<p><a href="https://example.org">Go</a></p>', 'en', null);
        $this->assertStringContainsString('background:#0055aa', $html);
        $this->assertStringContainsString('&lt;b&gt;Street 1&lt;/b&gt;', $html);
        Settings::set('mail_color', 'red;} body{display:none');
        $this->assertSame('#1da029', Composer::colour());
    }

    public function test_the_list_of_people_for_the_preview_is_narrowed_by_typing(): void
    {
        $this->login();
        Person::factory()->create(['first_name' => 'Robin', 'last_name' => 'Example']);
        Person::factory()->create(['first_name' => 'Sam', 'last_name' => 'Other']);
        Livewire::test(PreviewMailTemplate::class, ['record' => $this->template()->id])->assertSee('Example, Robin')->assertSee('Other, Sam')
            ->set('search', 'Oth')->assertSee('Other, Sam')->assertDontSee('Example, Robin');
    }

    public function test_every_placeholder_of_the_insert_list_is_valid_and_has_a_name(): void
    {
        $renderer = app(TemplateRenderer::class);
        $all = array_merge(...array_values(TemplateCatalogue::placeholders()));
        $this->assertArrayHasKey('{{ salutation }}', $all);
        $this->assertArrayHasKey('{{ event.title }}', $all);
        $this->assertArrayHasKey('{{ children[0].first_name }}', $all);
        foreach ($all as $placeholder => $label) {
            $this->assertSame([], $renderer->validate('x', '<p>'.$placeholder.'</p>', null), (string) $placeholder);
            $this->assertStringNotContainsString('mail.placeholders', $label, 'no text for '.$placeholder);
            $this->assertDoesNotMatchRegularExpression('/^[a-z_]+\.[a-z_]+( |$)/', $label, 'no name for '.$placeholder);
        }
        app()->setLocale('de');
        $this->assertContains('Titel – z. B. Summer camp', array_merge(...array_values(TemplateCatalogue::placeholders())));
    }

    public function test_conditions_are_built_from_lists_and_always_valid(): void
    {
        $renderer = app(TemplateRenderer::class);
        foreach (array_keys(array_merge(...array_values(TemplateCatalogue::fields()))) as $field) {
            foreach (TemplateCatalogue::OPERATORS as $operator) {
                $condition = TemplateCatalogue::condition($field, $operator, "O'Brien \"x\" \\", 'Yes {{ salutation }}', 'No');
                $this->assertSame([], $renderer->validate('x', '<p>'.$condition.'</p>', null), $condition);
            }
        }
        $this->assertSame('{% if event.location %}in {{ event.location }}{% endif %}', TemplateCatalogue::condition('event.location', 'filled', null, 'in {{ event.location }}'));
        $this->assertSame('{% if registration.waitlist_position > 3 %}far{% else %}near{% endif %}', TemplateCatalogue::condition('registration.waitlist_position', 'greater', '3', 'far', 'near'));
        $sample = ['children' => [['first_name' => 'Kim'], ['first_name' => 'Sam']]] + Placeholders::sample();
        $this->assertSame('<p>two</p>', $renderer->renderStrings('x', '<p>'.TemplateCatalogue::condition('children|length', 'equals', '2', 'two', 'other').'</p>', null, $sample)['html']);
        $this->expectException(MailException::class);
        TemplateCatalogue::condition('event.title %}{{ secret', 'filled', null, 'x');
    }

    public function test_mistakes_in_a_template_are_named_in_plain_words_and_block_saving(): void
    {
        $find = fn (string $html) => implode(' ', app(TemplateRenderer::class)->validate('x', $html, null));
        $this->assertStringContainsString('is not closed with }}', $find('<p>Hello {{ salutation </p>'));
        $this->assertStringContainsString('{% endif %} is missing', $find('<p>{% if event %}Camp</p>'));
        $this->assertStringContainsString('{% endif %} without a {% if %}', $find('<p>Camp{% endif %}</p>'));
        $this->assertStringContainsString('bracket ( has no matching )', $find('<p>{% if (event.title %}x{% endif %}</p>'));
        $this->assertStringContainsString('Unknown placeholder: unknown', $find('<p>{{ unknown }}</p>'));
        $this->assertStringContainsString('Unknown field "shoe_size"', $find('<p>{{ recipient.shoe_size }}</p>'));
        $this->assertStringContainsString('not available in templates', $find('<p>{% include "x" %}</p>'));
        $this->assertSame('', $find('<p>{{ "a (b" }} {% if event %}{% for child in children %}{{ child.first_name }}{% endfor %}{% else %}-{% endif %}</p>'));
        app()->setLocale('de');
        $this->assertStringContainsString('{% endif %} fehlt', $find('<p>{% if event %}Lager</p>'));
        app()->setLocale('en');

        $this->login();
        $template = $this->template();
        $before = $this->html($template);
        $page = Livewire::test(EditMailTemplate::class, ['record' => $template->id]);
        $key = (string) array_key_first((array) $page->get('data.contents'));
        $page->set("data.contents.{$key}.subject", 'Camp {{ event.title')->call('save')->assertHasFormErrors(["contents.{$key}.subject"])->assertSee('is not closed with }}');
        $this->assertSame($before, $this->html($template), 'nothing is saved while there is a mistake');
        $page->assertSee('data-rich-editor-tool="insertPlaceholder"', false)->assertSee('data-rich-editor-tool="insertCondition"', false)->assertSee('Insert placeholder')->assertSee('Insert condition');
    }

    public function test_every_standard_template_has_its_fixed_group_and_fits_into_it(): void
    {
        $renderer = app(TemplateRenderer::class);
        foreach (MailTemplate::query()->where('is_standard', true)->get() as $template) {
            $this->assertSame(MailTemplate::STANDARD_GROUPS[$template->key] ?? null, $template->groupKey(), $template->key);
            $content = $template->content();
            $this->assertSame([], $renderer->validate((string) $content?->subject, (string) $content?->html, $content?->text, $template->groupKey()), $template->key);
            $template->update(['group' => 'general']);
            $this->assertSame(MailTemplate::STANDARD_GROUPS[$template->key], $template->fresh()?->groupKey(), 'the group of a standard template cannot be changed');
        }
        $this->assertSame(['general', 'event', 'registration', 'invoice', 'membership'], MailTemplate::GROUPS);
    }

    public function test_a_group_offers_and_accepts_only_its_placeholders(): void
    {
        $keys = fn (?string $group) => array_keys(array_merge(...array_values(TemplateCatalogue::placeholders($group))));
        $this->assertContains('{{ invoice.number }}', $keys('invoice'));
        $this->assertContains('{{ event.title }}', $keys('invoice'));
        foreach (['general', 'event', 'registration', 'membership'] as $group) {
            $this->assertNotContains('{{ invoice.number }}', $keys($group), $group);
            $this->assertContains('{{ salutation }}', $keys($group));
            $this->assertContains('{{ children[0].first_name }}', $keys($group), 'children exist in every group');
        }
        $this->assertNotContains('{{ event.title }}', $keys('general'));
        $this->assertContains('{{ group.name }}', $keys('membership'));
        $this->assertNotContains('{{ registration.status }}', $keys('event'));
        $this->assertArrayNotHasKey('invoice.number', array_merge(...array_values(TemplateCatalogue::fields('general'))));

        $renderer = app(TemplateRenderer::class);
        $this->assertSame([], $renderer->validate('x', '<p>{{ invoice.number }}</p>', null, 'invoice'));
        $error = implode(' ', $renderer->validate('x', '<p>Invoice {{ invoice.number }}</p>', null, 'general'));
        $this->assertStringContainsString('"Invoice" does not exist in templates of the group "General"', $error);
        $this->assertSame([], $renderer->validate('x', '<p>{% if event %}{{ event.title }}{% endif %}</p>', null, 'general'), 'asking whether something is there is harmless');
        $this->assertSame([], $renderer->validate('{{ event.title }}', '<p>{{ children[0].first_name }}</p>', null, 'event'));
        try {
            TemplateCatalogue::condition('invoice.overdue', 'filled', null, 'x', null, 'general');
            $this->fail('a condition about the invoice cannot be built for a general template');
        } catch (MailException) {
        }

        // In the editor: the group can be chosen for own templates, and saving checks against it.
        $this->login();
        $own = MailTemplate::query()->create(['key' => 'own_note', 'category' => 'newsletter', 'audience' => 'guardians', 'name' => 'Own note', 'is_standard' => false]);
        $own->setContent('Note', '<p>{{ salutation }}</p>');
        $this->assertSame('general', $own->refresh()->groupKey());
        $page = Livewire::test(EditMailTemplate::class, ['record' => $own->id])->assertFormFieldIsEnabled('group');
        $key = (string) array_key_first((array) $page->get('data.contents'));
        $page->set("data.contents.{$key}.subject", 'Invoice {{ invoice.number }}')->call('save')->assertHasFormErrors(["contents.{$key}.subject"])->assertSee('does not exist in templates of the group');
        $page->set('data.group', 'invoice')->call('save')->assertHasNoFormErrors();
        $own->refresh();
        $this->assertSame(['invoice', 'Invoice {{ invoice.number }}'], [$own->groupKey(), $own->content()?->subject]);
        Livewire::test(EditMailTemplate::class, ['record' => $this->template('invoice')->id])->assertFormFieldIsDisabled('group')->assertSee('The group of a standard template is fixed.');
        Livewire::test(ListMailTemplates::class)->assertOk()->assertSee('Invoice and payment')->assertSee('Registration and waitlist');
    }

    public function test_mailings_offer_only_templates_they_can_fill(): void
    {
        $general = MailTemplate::forMailings(false)->pluck('key')->all();
        $this->assertContains('newsletter', $general);
        foreach (['invoice', 'registration_confirmation', 'event_information', 'group_place_offer', 'guardianship_ended'] as $key) {
            $this->assertNotContains($key, $general, $key);
        }
        $withEvent = MailTemplate::forMailings(true)->pluck('key')->all();
        $this->assertContains('event_information', $withEvent);
        $this->assertContains('newsletter', $withEvent);
        $this->assertNotContains('invoice', $withEvent);
        $this->assertNotContains('guardianship_ended', $withEvent, 'mails of the system are never a mailing');
    }
}
