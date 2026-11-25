<?php

namespace App\Support\Forms;

/**
 * Pluggable formats of text fields, e.g. insurance numbers with check digit (SF04). Further formats can be added with
 * Formats::register() in a service provider.
 */
class Formats
{
    /** @var array<string, \Closure(string): bool> */
    private static array $custom = [];

    /** @param  \Closure(string): bool  $check  receives the value without spaces, dots and hyphens, in upper case */
    public static function register(string $key, \Closure $check): void
    {
        self::$custom[$key] = $check;
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return ['at_social_insurance', 'de_health_insurance', 'ch_ahv', ...array_keys(self::$custom)];
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::keys())->mapWithKeys(fn (string $key) => [$key => trans()->has('forms.formats.'.$key) ? (string) __('forms.formats.'.$key) : $key])->all();
    }

    /** Unknown formats accept everything (e.g. of an extension that is no longer installed). */
    public static function valid(string $key, string $value): bool
    {
        $value = mb_strtoupper((string) preg_replace('/[\s.\-\/]/u', '', $value));

        return match ($key) {
            'at_social_insurance' => self::austria($value),
            'de_health_insurance' => self::germany($value),
            'ch_ahv' => self::switzerland($value),
            default => isset(self::$custom[$key]) ? (self::$custom[$key])($value) : true,
        };
    }

    /** Ten digits; the fourth is the check digit (weights 3, 7, 9, 5, 8, 4, 2, 1, 6, modulo 11). */
    private static function austria(string $value): bool
    {
        if (preg_match('/^[1-9]\d{9}$/', $value) !== 1) {
            return false;
        }
        $sum = 0;
        foreach ([0 => 3, 1 => 7, 2 => 9, 4 => 5, 5 => 8, 6 => 4, 7 => 2, 8 => 1, 9 => 6] as $position => $weight) {
            $sum += (int) $value[$position] * $weight;
        }

        return $sum % 11 === (int) $value[3];
    }

    /** One letter and nine digits; the last is the check digit (letter as two digits, weights 1 and 2, digit sum, modulo 10). */
    private static function germany(string $value): bool
    {
        if (preg_match('/^[A-Z]\d{9}$/', $value) !== 1) {
            return false;
        }
        $digits = sprintf('%02d', ord($value[0]) - 64).substr($value, 1, 8);
        $sum = 0;
        foreach (str_split($digits) as $i => $digit) {
            $product = (int) $digit * ($i % 2 === 0 ? 1 : 2);
            $sum += intdiv($product, 10) + $product % 10;
        }

        return $sum % 10 === (int) $value[9];
    }

    /** 13 digits starting with 756, EAN-13 check digit. */
    private static function switzerland(string $value): bool
    {
        if (preg_match('/^756\d{10}$/', $value) !== 1) {
            return false;
        }
        $sum = 0;
        foreach (str_split(substr($value, 0, 12)) as $i => $digit) {
            $sum += (int) $digit * ($i % 2 === 0 ? 1 : 3);
        }

        return (10 - $sum % 10) % 10 === (int) $value[12];
    }
}
