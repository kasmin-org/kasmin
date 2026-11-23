<?php

namespace App\Support\System;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * End-of-support dates of PHP branches (SN02): fetched from endoflife.date (cached for a day), with a bundled
 * fallback table for servers without internet access.
 */
class PhpSupport
{
    /** Fallback, security support until (php.net, as of 2026-11). */
    private const FALLBACK = ['8.2' => '2026-12-31', '8.3' => '2027-12-31', '8.4' => '2028-12-31', '8.5' => '2029-12-31', '8.6' => '2030-12-31'];

    /** @return array<string, string> branch => security support end (Y-m-d) */
    public function branches(): array
    {
        return Cache::remember('kasmin.php_support', now()->addDay(), function () {
            try {
                $data = Http::timeout(5)->acceptJson()->get('https://endoflife.date/api/php.json')->throw()->json();
                $result = [];
                foreach ((array) $data as $row) {
                    if (is_array($row) && isset($row['cycle'], $row['eol']) && is_string($row['eol'])) {
                        $result[(string) $row['cycle']] = $row['eol'];
                    }
                }

                return $result !== [] ? $result : self::FALLBACK;
            } catch (Throwable) {
                return self::FALLBACK;
            }
        });
    }

    public function currentBranch(): string
    {
        return PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
    }

    public function endOfSupport(?string $branch = null): ?Carbon
    {
        $date = $this->branches()[$branch ?? $this->currentBranch()] ?? null;

        return $date !== null ? Carbon::parse($date) : null;
    }

    /** True if the running PHP branch is the lowest one that is still supported. */
    public function isLowestSupported(): bool
    {
        $supported = array_keys(array_filter($this->branches(), fn (string $eol) => Carbon::parse($eol)->isFuture()));
        usort($supported, 'version_compare');

        return ($supported[0] ?? null) === $this->currentBranch();
    }

    public function isUnsupported(): bool
    {
        $eol = $this->endOfSupport();

        return $eol !== null && $eol->isPast();
    }
}
