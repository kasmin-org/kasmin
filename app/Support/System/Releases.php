<?php

namespace App\Support\System;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Latest Kasmin release from GitHub (SN03, UP01); cached for six hours.
 */
class Releases
{
    public const REPOSITORY = 'kasmin-org/kasmin';

    /** @return array{tag: string, version: string, url: string, zip: ?string, sha256: ?string, requirements: ?string, published_at: ?string}|null */
    public function latest(): ?array
    {
        return Cache::remember('kasmin.latest_release', now()->addHours(6), function () {
            try {
                $r = Http::timeout(8)->withHeaders(['User-Agent' => 'kasmin'])->acceptJson()
                    ->get('https://api.github.com/repos/'.self::REPOSITORY.'/releases/latest')->throw()->json();
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
            } catch (Throwable) {
                return null;
            }
        });
    }

    public function updateAvailable(): bool
    {
        $latest = $this->latest();
        $current = (string) config('kasmin.version');

        return $latest !== null && version_compare($latest['version'], preg_replace('/[-+].*$/', '', $current) ?? $current, '>');
    }
}
