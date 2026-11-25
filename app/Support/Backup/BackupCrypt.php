<?php

namespace App\Support\Backup;

use RuntimeException;

/**
 * Encrypts backups with a passphrase (N07): AES-256-CBC with a key made by PBKDF2 (SHA-256, 100000 rounds), in the
 * file format of OpenSSL. A backup can therefore also be opened without Kasmin:
 * openssl enc -d -aes-256-cbc -pbkdf2 -iter 100000 -md sha256 -in file.sql.gz.enc -out file.sql.gz
 */
class BackupCrypt
{
    private const ROUNDS = 100000;

    public static function encrypt(string $data, string $passphrase): string
    {
        $salt = random_bytes(8);
        [$key, $iv] = self::keys($passphrase, $salt);
        $cipher = openssl_encrypt($data, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) {
            throw new RuntimeException('The backup could not be encrypted.');
        }

        return 'Salted__'.$salt.$cipher;
    }

    /** Null if the passphrase is wrong or the file is not an encrypted backup. */
    public static function decrypt(string $data, string $passphrase): ?string
    {
        if (! str_starts_with($data, 'Salted__') || strlen($data) < 32) {
            return null;
        }
        [$key, $iv] = self::keys($passphrase, substr($data, 8, 8));
        $plain = openssl_decrypt(substr($data, 16), 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

        return $plain === false || ! str_starts_with($plain, "\x1f\x8b") ? null : $plain; // a backup is a gzip file
    }

    /** @return array{string, string} */
    private static function keys(string $passphrase, string $salt): array
    {
        $bytes = hash_pbkdf2('sha256', $passphrase, $salt, self::ROUNDS, 48, true);

        return [substr($bytes, 0, 32), substr($bytes, 32, 16)];
    }
}
