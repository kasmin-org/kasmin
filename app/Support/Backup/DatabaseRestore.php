<?php

namespace App\Support\Backup;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * Brings a database backup made by Kasmin back, in the browser and without other tools (N07, no shell). Everything in
 * the database is replaced. Before that the current state is saved as a backup of its own, so a wrong click can be
 * undone. Only for MySQL and MariaDB – the databases Kasmin runs on in production.
 */
class DatabaseRestore
{
    public function __construct(private readonly DatabaseBackup $backup) {}

    public static function available(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    /**
     * Checks a backup file without changing anything. Returns what it is, or throws with the reason it cannot be used.
     *
     * @return array{driver: string, made_at: string, statements: int}
     */
    public function inspect(string $gzFile): array
    {
        $first = '';
        $last = '';
        $count = 0;
        foreach ($this->lines($gzFile) as $line) {
            if ($first === '') {
                $first = $line;
            }
            if (trim($line) !== '') {
                $last = trim($line);
            }
            $count += str_starts_with($line, 'INSERT INTO') || str_starts_with($line, 'CREATE TABLE') ? 1 : 0;
        }
        if (preg_match('/^-- Kasmin database backup \((\w+)\), (\S+)/', $first, $m) !== 1) {
            throw new RuntimeException((string) __('system.restore.not_a_backup'));
        }
        if ($last !== DatabaseBackup::END) {
            throw new RuntimeException((string) __('system.restore.incomplete'));
        }
        $driver = DB::connection()->getDriverName();
        if (in_array($m[1], ['mysql', 'mariadb'], true) !== in_array($driver, ['mysql', 'mariadb'], true)) {
            throw new RuntimeException((string) __('system.restore.other_database', ['backup' => $m[1], 'here' => $driver]));
        }

        return ['driver' => $m[1], 'made_at' => $m[2], 'statements' => $count];
    }

    /**
     * Replaces the database with the backup. Returns the name of the backup made of the state before.
     */
    public function restore(string $gzFile): string
    {
        if (! self::available()) {
            throw new RuntimeException((string) __('system.restore.only_mysql'));
        }
        $this->inspect($gzFile);
        $before = basename($this->backup->create('before-restore'));
        @set_time_limit(0);
        rescue(fn () => Cache::flush(), report: false); // the cache may live in the database
        Schema::dropAllTables(); // also tables the backup does not know
        try {
            foreach ($this->statements($gzFile) as $statement) {
                DB::connection()->getPdo()->exec($statement);
            }
        } catch (Throwable $e) {
            throw new RuntimeException((string) __('system.restore.failed', ['backup' => $before, 'error' => mb_substr($e->getMessage(), 0, 200)]), 0, $e);
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        return $before;
    }

    /**
     * The statements of a backup, one by one. A statement ends with ";" at the end of a line outside of a text – texts
     * can hold line breaks and semicolons themselves.
     *
     * @return \Generator<int, string>
     */
    public function statements(string $gzFile): \Generator
    {
        $buffer = '';
        $inText = false;
        foreach ($this->lines($gzFile) as $line) {
            if ($buffer === '' && (str_starts_with($line, '--') || trim($line) === '')) {
                continue;
            }
            $length = strlen($line);
            for ($i = 0; $i < $length; $i++) {
                if ($inText && $line[$i] === '\\') {
                    $i++; // the next character is escaped
                } elseif ($line[$i] === "'") {
                    $inText = ! $inText; // a doubled quote switches twice
                }
            }
            $buffer .= $line;
            if (! $inText && str_ends_with(rtrim($line, "\r\n"), ';')) {
                yield rtrim($buffer);
                $buffer = '';
            }
        }
        if (trim($buffer) !== '') {
            throw new RuntimeException((string) __('system.restore.incomplete'));
        }
    }

    /** @return \Generator<int, string> */
    private function lines(string $gzFile): \Generator
    {
        $gz = @gzopen($gzFile, 'rb');
        if ($gz === false) {
            throw new RuntimeException((string) __('system.restore.not_a_backup'));
        }
        try {
            while (! gzeof($gz)) {
                $line = gzgets($gz);
                if ($line === false) {
                    break;
                }
                yield $line;
            }
        } finally {
            gzclose($gz);
        }
    }
}
