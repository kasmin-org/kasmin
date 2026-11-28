<?php

namespace App\Support\Auth;

/**
 * Base32H (https://base32h.github.io): 32 digits that are easy to read aloud and to type – 0–9 and A–Z without I, O,
 * S and U. Reading is lenient: lower case counts as upper case, O is read as 0, I as 1, S as 5, U as V, and every other
 * character (hyphens, spaces) is ignored. Used for the login code that people type (B12).
 */
final class Base32H
{
    public const DIGITS = '0123456789ABCDEFGHJKLMNPQRTVWXYZ';

    private const ALIASES = ['O' => '0', 'I' => '1', 'S' => '5', 'U' => 'V'];

    /** A random code of the given number of digits, from a cryptographically secure source. */
    public static function random(int $length = 6): string
    {
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= self::DIGITS[random_int(0, 31)];
        }

        return $code;
    }

    /** The canonical digits of what someone typed: upper case, aliases replaced, everything else left out. */
    public static function normalize(string $input): string
    {
        $out = '';
        foreach (str_split(strtoupper($input)) as $char) {
            $char = self::ALIASES[$char] ?? $char;
            if (str_contains(self::DIGITS, $char)) {
                $out .= $char;
            }
        }

        return $out;
    }

    /** For reading: groups of three, joined by a hyphen (K7M-2QX). */
    public static function format(string $code): string
    {
        return implode('-', str_split(self::normalize($code), 3));
    }
}
