<?php

namespace App\Support\Schema;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Compares the database schema with the code.
 *
 * Kasmin only supports upgrades. If the database was migrated by a newer Kasmin version (it contains migrations
 * this code does not know, or its recorded schema version is higher than the code version), the application
 * refuses to run, to prevent malfunction after an accidental downgrade.
 */
class SchemaGuard
{
    public const VERSION_KEY = 'schema_version';

    private ?SchemaStatus $cached = null;

    public function __construct(private readonly Migrator $migrator) {}

    public function status(bool $fresh = false): SchemaStatus
    {
        if ($this->cached !== null && ! $fresh) {
            return $this->cached;
        }

        return $this->cached = $this->determine();
    }

    public function codeVersion(): string
    {
        return (string) config('kasmin.version');
    }

    public function databaseVersion(): ?string
    {
        try {
            if (! Schema::hasTable('system_meta')) {
                return null;
            }
            $value = DB::table('system_meta')->where('key', self::VERSION_KEY)->value('value');

            return is_string($value) ? $value : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Migrations recorded in the database that do not exist in the code.
     *
     * @return list<string>
     */
    public function unknownMigrations(): array
    {
        return array_values(array_diff($this->ranMigrations(), $this->codeMigrations()));
    }

    /**
     * Migrations in the code that have not run yet.
     *
     * @return list<string>
     */
    public function pendingMigrations(): array
    {
        return array_values(array_diff($this->codeMigrations(), $this->ranMigrations()));
    }

    public function recordCodeVersion(): void
    {
        DB::table('system_meta')->updateOrInsert(
            ['key' => self::VERSION_KEY],
            ['value' => $this->codeVersion(), 'updated_at' => now(), 'created_at' => now()],
        );
        $this->cached = null;
    }

    private function determine(): SchemaStatus
    {
        try {
            if (! $this->migrator->repositoryExists()) {
                return SchemaStatus::NotInstalled;
            }
        } catch (Throwable) {
            return SchemaStatus::NotInstalled;
        }

        $dbVersion = $this->databaseVersion();
        if ($this->unknownMigrations() !== []
            || ($dbVersion !== null && version_compare($dbVersion, $this->codeVersion(), '>'))) {
            return SchemaStatus::DatabaseNewer;
        }

        if ($this->pendingMigrations() !== []) {
            return SchemaStatus::UpgradeRequired;
        }

        return SchemaStatus::Ok;
    }

    /** @return list<string> */
    private function ranMigrations(): array
    {
        /** @var list<string> $ran */
        $ran = $this->migrator->getRepository()->getRan();

        return $ran;
    }

    /** @return list<string> */
    private function codeMigrations(): array
    {
        $paths = array_merge([database_path('migrations')], $this->migrator->paths());
        $files = $this->migrator->getMigrationFiles($paths);

        return array_values(array_map(fn (string $path) => $this->migrator->getMigrationName($path), $files));
    }
}
