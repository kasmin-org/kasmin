<?php

namespace App\Support\System;

use App\Support\Backup\DatabaseBackup;
use App\Support\Install\Requirements;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Self-update for installations on plain web hosting (UP01). Not available in Docker (UP02).
 *
 * 1. precheck(): requirements of the target version (PHP, extensions, functions, writable directories, disk space)
 * 2. run(): download with SHA-256 check, database and code backup, replace the files (rollback on error)
 * 3. finish(): runs in a new request with the new code – database migrations via kasmin:upgrade
 */
class Updater
{
    /** Directories and files that are replaced by an update; everything else (.env, storage) stays. */
    private const REPLACE = ['app', 'bootstrap/app.php', 'bootstrap/providers.php', 'config', 'database', 'installer', 'lang', 'public', 'resources', 'routes', 'vendor', 'artisan', 'composer.json', 'composer.lock', 'VERSION', 'CHANGELOG.md', 'LICENSE', 'README.md'];

    public const MIN_FREE_BYTES = 400 * 1024 * 1024;

    public function __construct(private readonly Releases $releases) {}

    public function available(): bool
    {
        return ! SystemStatus::runsInDocker();
    }

    /**
     * @return array{release: ?array<string, mixed>, checks: list<array{label: string, ok: bool}>, passes: bool}
     */
    public function precheck(): array
    {
        $release = $this->releases->latest();
        $target = $this->targetRequirements($release);
        $checks = [];
        $checks[] = ['label' => 'PHP '.PHP_VERSION.' >= '.$target['php'], 'ok' => version_compare(PHP_VERSION, $target['php'], '>=')];
        foreach ($target['extensions'] as $ext) {
            $checks[] = ['label' => 'PHP extension '.$ext, 'ok' => extension_loaded($ext)];
        }
        foreach (['ZipArchive' => class_exists(ZipArchive::class), 'hash_file' => function_exists('hash_file'), 'set_time_limit' => function_exists('set_time_limit')] as $name => $ok) {
            $checks[] = ['label' => 'PHP function '.$name, 'ok' => $ok];
        }
        foreach (self::REPLACE as $path) {
            $full = base_path($path);
            $checks[] = ['label' => 'writable: '.$path, 'ok' => is_writable(file_exists($full) ? $full : dirname($full))];
        }
        $free = @disk_free_space(base_path());
        $checks[] = ['label' => 'free disk space >= 400 MB', 'ok' => $free === false || $free >= self::MIN_FREE_BYTES];
        $checks[] = ['label' => 'not running in Docker', 'ok' => $this->available()];
        $checks[] = ['label' => 'release package available', 'ok' => ($release['zip'] ?? null) !== null && ($release['sha256'] ?? null) !== null];

        return ['release' => $release, 'checks' => $checks, 'passes' => collect($checks)->every(fn ($c) => $c['ok'])];
    }

    /**
     * Downloads, verifies and installs the files of the latest release. Returns the URL that finishes the update
     * (database migrations) in a new request.
     */
    public function run(): string
    {
        @set_time_limit(600);
        $pre = $this->precheck();
        if (! $pre['passes'] || $pre['release'] === null) {
            throw new RuntimeException((string) __('system.update.precheck_failed'));
        }
        $release = $pre['release'];
        $dir = storage_path('app/updates');
        File::ensureDirectoryExists($dir, 0700);

        $zip = $dir.'/kasmin-'.$release['tag'].'.zip';
        File::put($zip, Http::timeout(300)->withHeaders(['User-Agent' => 'kasmin'])->get((string) $release['zip'])->throw()->body());
        $expected = strtolower((string) strtok(trim(Http::timeout(30)->withHeaders(['User-Agent' => 'kasmin'])->get((string) $release['sha256'])->throw()->body()), " \t"));
        if (! hash_equals($expected, (string) hash_file('sha256', $zip))) {
            @unlink($zip);
            throw new RuntimeException((string) __('system.update.checksum'));
        }

        app(DatabaseBackup::class)->create('before-update-'.$release['version']);
        $backup = $this->backupCode($dir.'/code-before-'.$release['version'].'-'.now()->format('YmdHis').'.zip');

        $staging = $dir.'/new-'.Str::random(8);
        $archive = new ZipArchive;
        if ($archive->open($zip) !== true) {
            throw new RuntimeException('The package cannot be opened.');
        }
        for ($i = 0; $i < $archive->numFiles; $i++) {
            $name = (string) $archive->getNameIndex($i);
            if (str_contains($name, '..') || str_starts_with($name, '/')) {
                throw new RuntimeException('The package contains an unsafe path.');
            }
        }
        $archive->extractTo($staging);
        $archive->close();

        // While the files are swapped nobody gets a page built from half of the old and half of the new version.
        $maintenance = storage_path('framework/maintenance.php');
        @copy(resource_path('maintenance/updating.php'), $maintenance);
        try {
            foreach (self::REPLACE as $path) {
                if (! file_exists($staging.'/'.$path)) {
                    continue;
                }
                $target = base_path($path);
                if (is_dir($staging.'/'.$path)) {
                    $old = $target.'.old-'.Str::random(6);
                    if (is_dir($target)) {
                        rename($target, $old);
                    }
                    rename($staging.'/'.$path, $target);
                    File::deleteDirectory($old);
                } else {
                    copy($staging.'/'.$path, $target);
                }
            }
            foreach (glob(base_path('bootstrap/cache/*.php')) ?: [] as $cached) {
                @unlink($cached);
            }
        } catch (Throwable $e) {
            $this->restoreCode($backup);
            throw new RuntimeException((string) __('system.update.rolled_back', ['error' => $e->getMessage()]));
        } finally {
            @unlink($maintenance);
            File::deleteDirectory($staging);
            @unlink($zip);
        }

        $token = Str::random(48);
        File::put(storage_path('framework/kasmin-update.json'), (string) json_encode(['token' => hash('sha256', $token), 'expires' => now()->addMinutes(30)->timestamp, 'version' => $release['version']]));

        return url('/system/update/finish?token='.$token);
    }

    /** Second step in a new request: migrations with the new code. */
    public function finish(string $token): bool
    {
        $file = storage_path('framework/kasmin-update.json');
        $state = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (! is_array($state) || ! hash_equals((string) $state['token'], hash('sha256', $token)) || (int) $state['expires'] < time()) {
            return false;
        }
        @unlink($file);

        return Artisan::call('kasmin:upgrade', ['--no-backup' => true]) === 0;
    }

    /**
     * @param  array<string, mixed>|null  $release
     * @return array{php: string, extensions: list<string>}
     */
    private function targetRequirements(?array $release): array
    {
        $fallback = ['php' => Requirements::MIN_PHP, 'extensions' => Requirements::EXTENSIONS];
        if (empty($release['requirements'])) {
            return $fallback;
        }
        try {
            $data = Http::timeout(15)->withHeaders(['User-Agent' => 'kasmin'])->get((string) $release['requirements'])->throw()->json();

            return ['php' => (string) ($data['php'] ?? $fallback['php']), 'extensions' => array_values(array_map('strval', (array) ($data['extensions'] ?? $fallback['extensions'])))];
        } catch (Throwable) {
            return $fallback;
        }
    }

    private function backupCode(string $file): string
    {
        $zip = new ZipArchive;
        $zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach (self::REPLACE as $path) {
            $full = base_path($path);
            if (is_file($full)) {
                $zip->addFile($full, $path);
            } elseif (is_dir($full)) {
                foreach (File::allFiles($full, true) as $f) {
                    $zip->addFile($f->getPathname(), $path.'/'.$f->getRelativePathname());
                }
            }
        }
        $zip->close();

        return $file;
    }

    private function restoreCode(string $backup): void
    {
        $zip = new ZipArchive;
        if ($zip->open($backup) === true) {
            $zip->extractTo(base_path());
            $zip->close();
        }
    }
}
