<?php

namespace App\Support\Finance;

/**
 * IBANs: the check digits (ISO 13616, modulo 97), the stored form without spaces and a masked form for places where
 * the whole number must not appear (lists, the API, the portal after saving).
 */
final class Iban
{
    public static function normalise(string $iban): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $iban));
    }

    public static function valid(string $iban): bool
    {
        $iban = self::normalise($iban);
        if (preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $iban) !== 1) {
            return false;
        }
        $numeric = '';
        foreach (str_split(substr($iban, 4).substr($iban, 0, 4)) as $char) {
            $numeric .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }

        return bcmod($numeric, '97') === '1';
    }

    /** In groups of four, as printed on cards and statements. */
    public static function format(string $iban): string
    {
        return trim(chunk_split(self::normalise($iban), 4, ' '));
    }

    /** Country and the last four characters, e.g. "AT** **** **** **** 3201". */
    public static function mask(?string $iban): ?string
    {
        if ($iban === null || $iban === '') {
            return null;
        }
        $iban = self::normalise($iban);
        $masked = substr($iban, 0, 2).str_repeat('*', max(0, strlen($iban) - 6)).substr($iban, -4);

        return self::format($masked);
    }
}
