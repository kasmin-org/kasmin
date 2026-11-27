<?php

namespace App\Support\System;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Kasmin releases from GitHub (SN03, UP01): the latest one, all newer ones to choose from, and what changes; cached
 * for six hours.
 */
class Releases
{
    public const REPOSITORY = 'kasmin-org/kasmin';

    /** How many releases back the list for choosing a version reaches. */
    private const LISTED = 40;

    /** @return array{tag: string, version: string, url: string, zip: ?string, sha256: ?string, requirements: ?string, published_at: ?string}|null */
    public function latest(): ?array
    {
        return Cache::remember('kasmin.latest_release', now()->addHours(6), function () {
            try {
                return self::present(Http::timeout(8)->withHeaders(['User-Agent' => 'kasmin'])->acceptJson()
                    ->get('https://api.github.com/repos/'.self::REPOSITORY.'/releases/latest')->throw()->json());
            } catch (Throwable) {
                return null;
            }
        });
    }

    /**
     * All published versions that are newer than the installed one, newest first – to choose the version to update to.
     * Pre-releases and drafts are left out. If GitHub cannot be reached, the latest one that is known is offered.
     *
     * @return list<array{tag: string, version: string, url: string, zip: ?string, sha256: ?string, requirements: ?string, published_at: ?string}>
     */
    public function newer(): array
    {
        $current = self::plain((string) config('kasmin.version'));
        $all = Cache::remember('kasmin.releases', now()->addHours(6), function () {
            try {
                $list = Http::timeout(10)->withHeaders(['User-Agent' => 'kasmin'])->acceptJson()
                    ->get('https://api.github.com/repos/'.self::REPOSITORY.'/releases', ['per_page' => self::LISTED])->throw()->json();

                return collect((array) $list)->filter(fn ($r) => is_array($r) && ! ($r['draft'] ?? false) && ! ($r['prerelease'] ?? false) && preg_match('/^v\d+\.\d+\.\d+$/', (string) ($r['tag_name'] ?? '')) === 1)
                    ->map(fn (array $r) => self::present($r))->values()->all();
            } catch (Throwable) {
                return null;
            }
        });
        $all ??= array_filter([$this->latest()]);
        $newer = array_values(array_filter($all, fn (array $r) => version_compare($r['version'], $current, '>')));
        usort($newer, fn (array $a, array $b) => version_compare($b['version'], $a['version']));

        return $newer;
    }

    /** @return array{tag: string, version: string, url: string, zip: ?string, sha256: ?string, requirements: ?string, published_at: ?string}|null */
    public function find(string $version): ?array
    {
        $version = self::plain($version);
        foreach ($this->newer() as $release) {
            if ($release['version'] === $version) {
                return $release;
            }
        }
        $latest = $this->latest();

        return $latest !== null && $latest['version'] === $version ? $latest : null;
    }

    /**
     * What changes between the installed version and $version, as HTML: the changelog of that version (it holds all
     * earlier ones, too). Empty if it cannot be loaded.
     */
    public function changes(string $version): string
    {
        $version = self::plain($version);
        $markdown = Cache::remember('kasmin.changelog.'.$version, now()->addHours(6), function () use ($version) {
            try {
                return Http::timeout(10)->withHeaders(['User-Agent' => 'kasmin'])->get('https://raw.githubusercontent.com/'.self::REPOSITORY.'/v'.$version.'/CHANGELOG.md')->throw()->body();
            } catch (Throwable) {
                return null;
            }
        });

        return $markdown === null ? '' : Changelog::between((string) $markdown, (string) config('kasmin.version'), $version);
    }

    /**
     * @param  array<string, mixed>  $r
     * @return array{tag: string, version: string, url: string, zip: ?string, sha256: ?string, requirements: ?string, published_at: ?string}
     */
    private static function present(array $r): array
    {
        $assets = collect((array) ($r['assets'] ?? []));
        $find = fn (string $suffix) => $assets->first(fn ($a) => str_ends_with((string) ($a['name'] ?? ''), $suffix))['browser_download_url'] ?? null;

        return [
            'tag' => (string) $r['tag_name'],
            'version' => ltrim((string) $r['tag_name'], 'v'),
            'url' => (string) ($r['html_url'] ?? ''),
            'zip' => $find('.zip'),
            'sha256' => $find('.zip.sha256'),
            'requirements' => $find('requirements.json'),
            'published_at' => $r['published_at'] ?? null,
        ];
    }

    private static function plain(string $version): string
    {
        return (string) preg_replace('/[-+].*$/', '', ltrim($version, 'v'));
    }

    public function updateAvailable(): bool
    {
        $latest = $this->latest();
        $current = (string) config('kasmin.version');

        return $latest !== null && version_compare($latest['version'], preg_replace('/[-+].*$/', '', $current) ?? $current, '>');
    }
}
