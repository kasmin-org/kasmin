<?php

namespace App\Support\Auth;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Spam protection without third parties (RG08): the browser has to find a number so that the hash starts with zero bits. For
 * people it takes about a second, for mass mailings it gets expensive. Every challenge works once.
 */
class ProofOfWork
{
    public const BITS = 14;

    public const MINUTES = 60;

    public static function enabled(): bool
    {
        return (bool) config('kasmin.proof_of_work', true);
    }

    public static function challenge(): string
    {
        $body = now()->addMinutes(self::MINUTES)->timestamp.'.'.self::BITS.'.'.Str::random(16);

        return $body.'.'.self::sign($body);
    }

    /** @param  mixed  $solution  "<challenge>:<number>" */
    public static function valid(mixed $solution): bool
    {
        if (! self::enabled()) {
            return true;
        }
        if (! is_string($solution) || preg_match('/^((\d+)\.(\d+)\.[A-Za-z0-9]{16})\.([a-f0-9]{64}):(\d{1,12})$/', $solution, $m) !== 1) {
            return false;
        }
        [, $body, $expires, $bits, $signature] = $m;
        if (! hash_equals(self::sign($body), $signature) || (int) $expires < now()->timestamp || ! self::leadingZeros($solution, (int) $bits)) {
            return false;
        }

        return Cache::add('pow:'.hash('sha256', $body), 1, now()->addMinutes(self::MINUTES + 5));
    }

    public static function leadingZeros(string $text, int $bits): bool
    {
        $hash = hash('sha256', $text, true);
        for ($i = 0; $i < $bits; $i++) {
            if ((ord($hash[intdiv($i, 8)]) >> (7 - $i % 8) & 1) !== 0) {
                return false;
            }
        }

        return true;
    }

    private static function sign(string $body): string
    {
        return hash_hmac('sha256', 'pow|'.$body, (string) config('app.key'));
    }
}
