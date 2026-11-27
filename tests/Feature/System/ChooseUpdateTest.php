<?php

namespace Tests\Feature\System;

use App\Livewire\SystemUpdate;
use App\Models\AuditEvent;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Install\Defaults;
use App\Support\System\Changelog;
use App\Support\System\Releases;
use App\Support\System\Updater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Choosing the version to update to, the changes up to it, and the update started by a deployment pipeline (UP01).
 */
class ChooseUpdateTest extends TestCase
{
    use RefreshDatabase;

    private const CHANGELOG = <<<'MD'
        # Changelog

        ## [Unreleased]

        ### Added

        - Not released yet.

        ## [1.2.0] - 2027-03-01

        ### Added

        - **Bus lists** with `seats` and a *note* <script>alert(1)</script>, see [the docs](https://example.org).

        ## [1.1.1] - 2027-02-10

        ### Fixed

        - Reminder mails were sent twice.

        ## [1.1.0] - 2027-02-01

        ### Changed

        - New start page.

        ## [1.0.0] - 2027-01-01

        ### Added

        - First version.

        [Unreleased]: https://example.org/compare
        MD;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        app(Defaults::class)->install('en');
        Cache::flush();
        config(['kasmin.version' => '1.0.0', 'kasmin.runtime' => null]);
        $this->token = str_repeat('a1B2', 10);
        $release = fn (string $version, array $more = []) => $more + ['tag_name' => 'v'.$version, 'html_url' => 'https://example.org/'.$version, 'published_at' => '2027-02-01T10:00:00Z',
            'assets' => [['name' => "kasmin-v{$version}.zip", 'browser_download_url' => "https://example.org/{$version}.zip"], ['name' => "kasmin-v{$version}.zip.sha256", 'browser_download_url' => "https://example.org/{$version}.sha256"]]];
        Http::fake([
            'api.github.com/repos/kasmin-org/kasmin/releases/latest' => Http::response($release('1.2.0')),
            'api.github.com/repos/kasmin-org/kasmin/releases?*' => Http::response([$release('1.1.0'), $release('1.2.0'), $release('1.3.0-rc1', ['prerelease' => true]), $release('1.4.0', ['draft' => true]), $release('1.1.1'), $release('1.0.0'), $release('0.9.0')]),
            'raw.githubusercontent.com/*' => Http::response(preg_replace('/^ {8}/m', '', self::CHANGELOG)),
        ]);
    }

    private function admin(): User
    {
        $person = Person::factory()->create();
        $person->roleAssignments()->create(['role_id' => Role::query()->where('key', 'administrator')->value('id')]);

        return User::query()->create(['name' => 'Admin', 'email' => 'admin@example.org', 'password' => 'x', 'person_id' => $person->id]);
    }

    public function test_all_newer_versions_are_offered_newest_first_without_pre_releases(): void
    {
        $releases = app(Releases::class);
        $this->assertSame(['1.2.0', '1.1.1', '1.1.0'], array_column($releases->newer(), 'version'));
        $this->assertSame('https://example.org/1.1.1.zip', $releases->find('v1.1.1')['zip'] ?? null);
        $this->assertNull($releases->find('0.9.0'), 'Kasmin only goes forward');
        $this->assertNull($releases->find('1.3.0-rc1'));
        config(['kasmin.version' => '1.1.1-dev']);
        $this->assertSame(['1.2.0'], array_column($releases->newer(), 'version'));
    }

    public function test_the_changes_between_the_installed_and_the_chosen_version_are_shown_in_full(): void
    {
        $all = app(Releases::class)->changes('1.2.0');
        foreach (['data-changelog-version="1.2.0"', 'data-changelog-version="1.1.1"', 'data-changelog-version="1.1.0"', 'Reminder mails were sent twice.', 'New start page.', '<strong>Bus lists</strong>', '<code>seats</code>', '<em>note</em>', 'see the docs.'] as $part) {
            $this->assertStringContainsString($part, $all);
        }
        foreach (['First version', 'Not released yet', '<script>', 'example.org'] as $part) {
            $this->assertStringNotContainsString($part, $all);
        }
        $this->assertLessThan(strpos($all, 'New start page.'), strpos($all, 'Bus lists'), 'newest first');

        $one = app(Releases::class)->changes('1.1.0');
        $this->assertStringContainsString('New start page.', $one);
        $this->assertStringNotContainsString('Bus lists', $one);
        $this->assertStringNotContainsString('Reminder mails', $one);
        $this->assertSame('', Changelog::between('no changelog', '1.0.0', '2.0.0'));
    }

    public function test_an_administrator_chooses_the_version_and_reads_the_changes_before_updating(): void
    {
        $this->actingAs($this->admin());
        $updater = Mockery::mock(Updater::class)->makePartial();
        $updater->shouldReceive('precheck')->andReturnUsing(fn (?string $version = null) => ['release' => app(Releases::class)->find((string) $version), 'checks' => [['label' => 'PHP', 'ok' => true]], 'passes' => true]);
        $updater->shouldReceive('run')->once()->with('1.1.1')->andReturn('https://kasmin.test/system/update/finish?token=x');
        $this->app->instance(Updater::class, $updater);

        $page = Livewire::test(SystemUpdate::class)->assertSee('Check for updates')->call('check')
            ->assertSet('target', '1.2.0')->assertSee('3 newer versions are available')->assertSee('1.2.0')->assertSee('(newest)')
            ->assertSee('What changes from 1.0.0 to 1.2.0')->assertSee('Reminder mails were sent twice.')->assertSee('Bus lists')->assertSee('Update to 1.2.0 now');
        $page->set('target', '1.1.1')->assertSee('What changes from 1.0.0 to 1.1.1')->assertSee('Reminder mails were sent twice.')->assertDontSee('Bus lists')->assertSee('Update to 1.1.1 now');
        $page->set('target', '0.9.0')->assertSet('target', '1.2.0'); // only what is offered can be chosen
        $page->set('target', '1.1.1')->call('update')->assertRedirect('https://kasmin.test/system/update/finish?token=x');
        $this->assertSame('1.1.1', AuditEvent::query()->where('action', 'system.update_started')->latest('id')->firstOrFail()->meta['to'] ?? null);

        auth()->forgetUser();
        $other = User::query()->create(['name' => 'x', 'email' => 'x@example.org', 'password' => 'x', 'person_id' => Person::factory()->create()->id]);
        Livewire::actingAs($other)->test(SystemUpdate::class)->call('check')->assertForbidden();
    }

    public function test_nothing_is_offered_when_kasmin_is_up_to_date_and_older_versions_are_refused(): void
    {
        config(['kasmin.version' => '1.2.0']);
        $this->actingAs($this->admin());
        Livewire::test(SystemUpdate::class)->call('check')->assertSee('Kasmin is up to date.')->assertDontSee('Update to');
        $this->expectException(RuntimeException::class);
        app(Updater::class)->run('1.1.0');
    }

    public function test_a_pipeline_lets_the_installation_update_itself_and_gets_the_address_that_finishes_it(): void
    {
        $headers = ['Authorization' => 'Bearer '.$this->token];
        $this->postJson('/system/hooks/update', ['version' => '1.2.0'], $headers)->assertNotFound(); // no token configured
        config(['kasmin.hook_token' => $this->token]);
        $this->postJson('/system/hooks/update', ['version' => '1.2.0'], ['Authorization' => 'Bearer wrong'])->assertNotFound();
        $this->postJson('/system/hooks/update', ['version' => '../1.2'], $headers)->assertStatus(422);

        $updater = Mockery::mock(Updater::class);
        $updater->shouldReceive('available')->andReturn(true);
        $updater->shouldReceive('run')->once()->with('1.1.1')->andReturn('https://kasmin.test/system/update/finish?token=abc');
        $updater->shouldReceive('run')->once()->with('1.2.0')->andThrow(new RuntimeException('Checksum mismatch'));
        $this->app->instance(Updater::class, $updater);

        $this->postJson('/system/hooks/update', ['version' => 'v1.1.1'], $headers)->assertOk()
            ->assertJson(['ok' => true, 'updated' => true, 'from' => '1.0.0', 'version' => '1.1.1', 'finish' => 'https://kasmin.test/system/update/finish?token=abc']);
        $this->assertSame('hook', AuditEvent::query()->where('action', 'system.update_started')->sole()->meta['by'] ?? null);
        // Without a version: the latest one; a failure is reported as such.
        $this->postJson('/system/hooks/update', [], $headers)->assertStatus(500)->assertJson(['ok' => false, 'message' => 'Checksum mismatch']);
        $this->assertTrue(AuditEvent::query()->where('action', 'system.update_failed')->exists());

        $this->postJson('/system/hooks/update', ['version' => '1.0.0'], $headers)->assertOk()->assertJson(['ok' => true, 'updated' => false, 'installed' => '1.0.0']);
        config(['kasmin.version' => '1.2.0']);
        $this->postJson('/system/hooks/update', [], $headers)->assertOk()->assertJson(['updated' => false]);
    }

    public function test_the_update_hook_is_refused_in_docker(): void
    {
        config(['kasmin.hook_token' => $this->token, 'kasmin.runtime' => 'docker']);
        $this->postJson('/system/hooks/update', ['version' => '1.2.0'], ['Authorization' => 'Bearer '.$this->token])->assertStatus(409)->assertJson(['ok' => false]);
    }
}
