<?php

namespace App\Support\Install;

/**
 * Checks the server before installation.
 */
class Requirements
{
    public const MIN_PHP = '8.3.0';

    public const EXTENSIONS = ['pdo_mysql', 'intl', 'mbstring', 'openssl', 'zip', 'gd', 'bcmath', 'fileinfo', 'tokenizer', 'xml', 'ctype', 'json'];

    /**
     * @return list<array{label: string, ok: bool}>
     */
    public function check(): array
    {
        $checks = [['label' => 'PHP >= '.self::MIN_PHP.' ('.PHP_VERSION.')', 'ok' => version_compare(PHP_VERSION, self::MIN_PHP, '>=')]];
        foreach (self::EXTENSIONS as $extension) {
            $checks[] = ['label' => 'PHP extension '.$extension, 'ok' => extension_loaded($extension)];
        }
        foreach (['storage', 'bootstrap/cache'] as $dir) {
            $checks[] = ['label' => 'writable: '.$dir, 'ok' => is_writable(base_path($dir))];
        }

        return $checks;
    }

    public function passes(): bool
    {
        return collect($this->check())->every(fn (array $check) => $check['ok']);
    }
}
