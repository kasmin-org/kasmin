<?php

namespace Tests\Feature\System;

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\NotificationsPage;
use App\Filament\Support\Areas;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Install\Defaults;
use Filament\Facades\Filament;
use Filament\Http\Middleware\AuthenticateSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Five areas at the top, the navigation on the left only of the open area (B11). */
class AreasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(Defaults::class)->install('en');
        app()->setLocale('en');
        $this->withoutMiddleware(AuthenticateSession::class);
    }

    private function user(string $role): User
    {
        $person = Person::factory()->create(['locale' => 'en']);
        $person->roleAssignments()->create(['role_id' => Role::query()->where('key', $role)->value('id')]);

        return User::query()->create(['name' => $role, 'email' => $role.'@example.org', 'password' => 'x', 'person_id' => $person->id]);
    }

    public function test_every_page_of_the_admin_interface_belongs_to_an_area(): void
    {
        $panel = Filament::getPanel('admin');
        $this->assertNotNull($panel);
        $classes = [...$panel->getResources(), ...$panel->getPages()];
        $outside = array_values(array_diff($classes, array_keys(Areas::ITEMS), [Dashboard::class, NotificationsPage::class, \Filament\Pages\Dashboard::class]));
        $this->assertSame([], $outside, 'pages without an area');
        foreach (array_keys(Areas::ITEMS) as $class) {
            $this->assertTrue(class_exists($class), $class);
        }
    }

    /** @return array<string, array{0: string, 1: list<string>, 2: list<string>}> page, links in the navigation, links not in it */
    public static function areaPages(): array
    {
        return [
            'people' => ['/admin/people', ['/admin/households', '/admin/duplicates', '/admin/membership-types'], ['/admin/roles', '/admin/refunds']],
            'events' => ['/admin/events', ['/admin/event-series', '/admin/forms', '/admin/automations', '/admin/event-templates', '/admin/reports'], ['/admin/refunds']],
            'communication' => ['/admin/mailings', ['/admin/mail-templates', '/admin/outgoing-mails', '/admin/consents'], ['/admin/event-series']],
            'finance' => ['/admin/invoices', ['/admin/refunds', '/admin/settings/invoices'], ['/admin/households']],
            'administration' => ['/admin/roles', ['/admin/settings/global', '/admin/settings/organisation', '/admin/system', '/admin/backups', '/admin/updates', '/admin/api-tokens'], ['/admin/refunds']],
        ];
    }

    /**
     * @param  list<string>  $in
     * @param  list<string>  $out
     */
    #[DataProvider('areaPages')]
    public function test_the_left_navigation_shows_only_the_open_area(string $page, array $in, array $out): void
    {
        $html = (string) $this->actingAs($this->user('administrator'))->withSession(['two_factor_passed' => true])->get($page)->assertOk()->getContent();
        foreach (array_keys(Areas::AREAS) as $area) {
            $this->assertStringContainsString('data-area="'.$area.'"', $html, $area);
        }
        foreach ($in as $link) {
            $this->assertStringContainsString('href="'.url($link).'"', $html, $link);
        }
        foreach ($out as $link) {
            $this->assertStringNotContainsString('href="'.url($link).'"', $html, $link);
        }
    }

    public function test_the_dashboard_shows_the_areas_and_the_notifications_are_in_the_user_menu(): void
    {
        $html = (string) $this->actingAs($this->user('administrator'))->withSession(['two_factor_passed' => true])->get('/admin')->assertOk()->getContent();
        $this->assertStringContainsString('data-areas-menu', $html);
        $this->assertStringContainsString(url('/admin/notifications'), $html);
    }

    public function test_administration_is_not_shown_to_roles_without_any_page_of_it(): void
    {
        $treasurer = $this->user('treasurer');
        $this->actingAs($treasurer)->withSession(['two_factor_passed' => true]);
        $areas = array_keys(Areas::visible());
        $this->assertContains('finance', $areas);
        $this->assertNotContains('administration', $areas);
        $html = (string) $this->withSession(['two_factor_passed' => true])->get('/admin/invoices')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-area="administration"', $html);
        $this->assertStringContainsString('data-area="finance"', $html);
    }
}
