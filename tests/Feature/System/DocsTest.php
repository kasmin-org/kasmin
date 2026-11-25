<?php

namespace Tests\Feature\System;

use App\Support\System\Docs;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Context help (H03, V04): every admin page links to the documentation of the installed version.
 */
class DocsTest extends TestCase
{
    public function test_the_version_decides_the_documentation(): void
    {
        $this->assertSame('0.22', Docs::versionSlug('0.22.3'));
        $this->assertSame('dev', Docs::versionSlug('0.23.0-dev'));
        $this->assertSame('dev', Docs::versionSlug('unknown'));
        $this->assertStringEndsWith('/admin/mail/', Docs::url('admin/mail'));
    }

    public function test_every_admin_page_has_a_help_page_that_exists(): void
    {
        $missing = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $name = (string) $route->getName();
            if (! str_starts_with($name, 'filament.admin.pages.') && ! str_starts_with($name, 'filament.admin.resources.')) {
                continue;
            }
            $url = Docs::forRoute($name);
            if ($url === null) {
                $missing[] = $name;

                continue;
            }
            $page = trim(substr($url, strlen(Docs::BASE.Docs::versionSlug())), '/');
            $this->assertFileExists(base_path('docs/'.$page.'.md'), $name);
        }
        $this->assertSame([], $missing, 'admin pages without a help page');
    }
}
