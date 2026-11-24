<?php

namespace Tests\Unit\System;

use App\Support\System\BootCache;
use PHPUnit\Framework\TestCase;

class BootCacheTest extends TestCase
{
    public function test_cached_package_list_is_removed_when_the_release_or_its_packages_change(): void
    {
        $base = sys_get_temp_dir().'/kasmin-bootcache-'.bin2hex(random_bytes(6));
        mkdir($base.'/bootstrap/cache', 0777, true);
        mkdir($base.'/vendor/composer', 0777, true);
        $stale = $base.'/bootstrap/cache/packages.php';
        $write = function () use ($stale): void {
            file_put_contents($stale, '<?php return [];');
        };
        file_put_contents($base.'/VERSION', "0.7.0\n");
        file_put_contents($base.'/vendor/composer/installed.php', '<?php return 1;');

        $write(); // left over from a release without marker
        $this->assertTrue(BootCache::refresh($base));
        $this->assertFileDoesNotExist($stale);

        $write();
        $this->assertFalse(BootCache::refresh($base), 'unchanged release keeps its cache');
        $this->assertFileExists($stale);

        file_put_contents($base.'/vendor/composer/installed.php', '<?php return 2;');
        $this->assertTrue(BootCache::refresh($base), 'new packages, same version');
        $this->assertFileDoesNotExist($stale);

        $write();
        file_put_contents($base.'/VERSION', "0.8.0\n");
        $this->assertTrue(BootCache::refresh($base), 'new version');
        $this->assertFileDoesNotExist($stale);
    }
}
