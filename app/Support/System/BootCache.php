<?php

namespace App\Support\System;

/**
 * Laravel caches the list of packages and their service providers in bootstrap/cache. When a new release is uploaded
 * over an old one (FTP, demo deployment), those files stay and packages added by the release would never be loaded.
 * Called before the application boots: removes the cached files whenever the version or the installed packages change.
 */
class BootCache
{
    public static function refresh(string $basePath): bool
    {
        $installed = $basePath.'/vendor/composer/installed.php';
        $cache = $basePath.'/bootstrap/cache';
        $marker = $cache.'/release.txt';
        $current = trim((string) @file_get_contents($basePath.'/VERSION')).' '.(@md5_file($installed) ?: '-');
        if (@file_get_contents($marker) === $current) {
            return false;
        }
        foreach (glob($cache.'/*.php') ?: [] as $file) {
            @unlink($file);
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($file, true);
            }
        }
        @file_put_contents($marker, $current, LOCK_EX);

        return true;
    }
}
