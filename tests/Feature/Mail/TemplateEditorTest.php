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
        $key = (string) collect((array) $page->get('data.contents'))->search(fn ($c) => $c['locale'] === 'en');
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
        $html = Livewire::test(PreviewMailTemplate::class, ['record' => $template->id])->assertOk()->set('search', 'Exam')->assertSee('Robin Example')->set('person', $person->id)->html();
        $this->assertStringContainsString('sandbox=""', $html);
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringNotContainsString('made-when-sending', $html);
        $this->assertStringNotContainsString('signature=', $html);
    }

    public function test_test_mail_goes_only_to_oneself(): void
    {
        $user = $this->login();
        $template = $this->template();
        Livewire::test(PreviewMailTemplate::class, ['record' => $template->id])->call('test', 'en')->assertOk();
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
}
