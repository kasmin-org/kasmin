<?php

namespace App\Support\Backup;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Writes a gzip-compressed SQL dump of the database using PHP only (no mysqldump needed on shared hosting).
 */
class DatabaseBackup
{
    public function create(string $label): string
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        $relative = trim((string) config('kasmin.backup_path'), '/').'/kasmin-'.$label.'-'.now()->format('Ymd-His').'.sql.gz';
        $absolute = Storage::disk('local')->path($relative);
        @mkdir(dirname($absolute), 0700, true);

        $gz = gzopen($absolute, 'wb6');
        if ($gz === false) {
            throw new RuntimeException("Cannot write backup file {$absolute}");
        }

        gzwrite($gz, "-- Kasmin database backup ({$driver}), ".now()->toIso8601String()."\n");

        foreach ($this->tables($driver) as $table) {
            gzwrite($gz, "\n".$this->createStatement($driver, $table).";\n");
            DB::table($table)->orderByRaw('1')->chunk(500, function ($rows) use ($gz, $table, $connection) {
                foreach ($rows as $row) {
                    $values = array_map(
                        fn ($v) => $v === null ? 'NULL' : $connection->getPdo()->quote((string) $v),
                        (array) $row,
                    );
                    $columns = implode(',', array_map(fn ($c) => '`'.$c.'`', array_keys((array) $row)));
                    gzwrite($gz, "INSERT INTO `{$table}` ({$columns}) VALUES (".implode(',', $values).");\n");
                }
            });
        }

        gzclose($gz);
        @chmod($absolute, 0600);

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

    private function createStatement(string $driver, string $table): string
    {
        if ($driver === 'sqlite') {
            return (string) DB::table('sqlite_master')->where('name', $table)->value('sql');
        }
        $row = (array) DB::selectOne("SHOW CREATE TABLE `{$table}`");

        return 'DROP TABLE IF EXISTS `'.$table."`;\n".(string) array_values($row)[1];
    }
}
