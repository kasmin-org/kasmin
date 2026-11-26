<?php

namespace App\Support\Install;

/**
 * Checks the server before installation.
 */
class Requirements
{
    public const MIN_PHP = '8.3.0';

    public const EXTENSIONS = ['pdo_mysql', 'intl', 'mbstring', 'openssl', 'zip', 'gd', 'bcmath', 'fileinfo', 'tokenizer', 'xml', 'dom', 'xmlreader', 'ctype', 'json', 'iconv', 'zlib'];

    /** Useful, but Kasmin works without them (what is lost is told in the system status). */
    public const RECOMMENDED_EXTENSIONS = ['curl'];

    /** PHP functions that some hosters switch off. */
    public const RECOMMENDED_FUNCTIONS = ['proc_open', 'set_time_limit', 'dns_get_record'];

    public const DIRECTORIES = ['storage', 'bootstrap/cache'];

    public const MEMORY_MB = 128;

    /**
     * Everything Kasmin needs from the server, one by one, with what it is used for (system status, documentation).
     *
     * @return list<array{kind: string, name: string, ok: bool, required: bool, purpose: string}>
     */
    public function details(): array
    {
        $items = [['kind' => 'php', 'name' => 'PHP '.self::MIN_PHP.'+', 'ok' => version_compare(PHP_VERSION, self::MIN_PHP, '>='), 'required' => true, 'purpose' => (string) __('system.requirements.php', ['version' => PHP_VERSION])]];
        foreach (self::EXTENSIONS as $extension) {
            $items[] = ['kind' => 'extension', 'name' => $extension, 'ok' => extension_loaded($extension), 'required' => true, 'purpose' => (string) __('system.requirements.extensions.'.$extension)];
        }
        foreach (self::RECOMMENDED_EXTENSIONS as $extension) {
            $items[] = ['kind' => 'extension', 'name' => $extension, 'ok' => extension_loaded($extension), 'required' => false, 'purpose' => (string) __('system.requirements.extensions.'.$extension)];
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        foreach (self::RECOMMENDED_FUNCTIONS as $function) {
            $items[] = ['kind' => 'function', 'name' => $function.'()', 'ok' => function_exists($function) && ! in_array($function, $disabled, true), 'required' => false, 'purpose' => (string) __('system.requirements.functions.'.$function)];
        }
        foreach (self::DIRECTORIES as $dir) {
            $items[] = ['kind' => 'directory', 'name' => $dir.'/', 'ok' => is_writable(base_path($dir)), 'required' => true, 'purpose' => (string) __('system.requirements.directories.'.$dir)];
        }

        return $items;
    }

    /** The memory one PHP process may use, in MB; null = no limit. */
    public static function memoryLimitMb(): ?int
    {
        $value = trim((string) ini_get('memory_limit'));
        if ($value === '' || $value === '-1') {
            return null;
        }
        $number = (float) $value;

        return (int) round(match (strtolower(substr($value, -1))) {
            'g' => $number * 1024, 'm' => $number, 'k' => $number / 1024, default => $number / 1048576,
        });
    }

    /**
     * Large PDFs (camp folder, long lists) need more than the usual 128 MB for a moment: ask for it for this request
     * only. Returns whether the server allowed it.
     */
    public static function moreMemory(int $mb = 256): bool
    {
        $limit = self::memoryLimitMb();

        return $limit === null || $limit >= $mb || @ini_set('memory_limit', $mb.'M') !== false;
    }

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
