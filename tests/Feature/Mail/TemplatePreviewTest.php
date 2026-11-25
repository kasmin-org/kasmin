<?php

namespace Tests\Feature\Mail;

use App\Filament\Resources\MailTemplates\Pages\ListMailTemplates;
use App\Models\MailTemplate;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Install\Defaults;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TemplatePreviewTest extends TestCase
{
    use RefreshDatabase;

    /** Whoever may edit templates must not be able to run scripts in the session of somebody who looks at the preview. */
    public function test_the_preview_never_runs_scripts_of_a_template(): void
    {
        app(Defaults::class)->install('en');
        $person = Person::factory()->create();
        $person->roleAssignments()->create(['role_id' => Role::query()->where('key', 'administrator')->value('id')]);
        $user = User::query()->create(['name' => 'Admin', 'email' => 'admin@example.org', 'password' => 'x', 'person_id' => $person->id]);
        $this->actingAs($user)->withSession(['two_factor_passed' => true]);
        $template = MailTemplate::query()->where('key', 'registration_confirmation')->firstOrFail();
        $template->contents()->update(['html' => '<p>Hello</p><script>alert("x")</script><img src="x" onerror="alert(1)">']);

        $page = Livewire::test(ListMailTemplates::class)->mountTableAction('preview', $template)->instance();
        $this->assertInstanceOf(ListMailTemplates::class, $page);
        $content = $page->getMountedAction()?->getModalContent();
        $this->assertInstanceOf(Htmlable::class, $content);
        $html = $content->toHtml();
        $this->assertStringContainsString('Hello', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('sandbox=""', $html);
    }
}
