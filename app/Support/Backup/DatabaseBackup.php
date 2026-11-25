<?php

namespace App\Support\Backup;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Writes a gzip-compressed SQL dump of the database using PHP only (no mysqldump needed on shared hosting).
 */
class DatabaseBackup
{
    /** Last line of a complete backup. */
    public const END = '-- End of backup';

    public function create(string $label): string
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        $relative = trim((string) config('kasmin.backup_path'), '/').'/kasmin-'.$label.'-'.now()->format('Ymd-His').'.sql.gz';
        $absolute = Storage::disk('local')->path($relative);
        @mkdir(dirname($absolute), 0700, true);

        // Written under another name first, so an interrupted backup is never mistaken for a complete one.
        $part = $absolute.'.part';
        $gz = gzopen($part, 'wb6');
        if ($gz === false) {
            throw new RuntimeException("Cannot write backup file {$absolute}");
        }
        @chmod($part, 0600);

        $write = function (string $sql) use ($gz, $absolute): void {
            if (gzwrite($gz, $sql) !== strlen($sql)) {
                throw new RuntimeException("Cannot write backup file {$absolute}"); // e.g. disk full
            }
        };
        $mysql = $driver !== 'sqlite';
        $write("-- Kasmin database backup ({$driver}), ".now()->toIso8601String()."\n");
        // Without this, restoring stops at the first table that refers to a later one.
        $write($mysql ? "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n" : "PRAGMA foreign_keys=OFF;\n");

        try {
            // One transaction: all tables are read at the same point in time.
            $connection->transaction(function () use ($driver, $write, $connection) {
                foreach ($this->tables($driver) as $table) {
                    $write("\n".$this->createStatement($driver, $table).";\n");
                    $query = DB::table($table);
                    $columns = Schema::getColumnListing($table);
                    // A complete order, otherwise rows could be missed or doubled between two chunks.
                    foreach (in_array('id', $columns, true) ? ['id'] : $columns as $column) {
                        $query->orderBy($column);
                    }
                    $query->chunk(500, function ($rows) use ($write, $table, $connection) {
                        foreach ($rows as $row) {
                            $values = array_map(
                                fn ($v) => $v === null ? 'NULL' : $connection->getPdo()->quote((string) $v),
                                (array) $row,
                            );
                            $columns = implode(',', array_map(fn ($c) => '`'.$c.'`', array_keys((array) $row)));
                            $write("INSERT INTO `{$table}` ({$columns}) VALUES (".implode(',', $values).");\n");
                        }
                    });
                }
            });
            $write("\n".($mysql ? "SET FOREIGN_KEY_CHECKS=1;\n" : "PRAGMA foreign_keys=ON;\n").self::END."\n");
            gzclose($gz);
        } catch (\Throwable $e) {
            @unlink($part);

            throw $e;
        }
        rename($part, $absolute);

        return $absolute;
    }

    /** @return list<string> */
    private function tables(string $driver): array
    {
        $rows = match ($driver) {
            'sqlite' => DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"),
            default => DB::select('SHOW TABLES'),
        };

        return array_values(array_map(fn ($row) => (string) array_values((array) $row)[0], $rows));
    }

    protected function createStatement(string $driver, string $table): string
    {
        if ($driver === 'sqlite') {
            return (string) DB::table('sqlite_master')->where('name', $table)->value('sql');
        }
        $row = (array) DB::selectOne("SHOW CREATE TABLE `{$table}`");

        return 'DROP TABLE IF EXISTS `'.$table."`;\n".(string) array_values($row)[1];
    }
}
