<?php

namespace App\Support\Backup;

use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Daily backups and their status (AD83). Backups made before upgrades are never removed here.
 */
class Backups
{
    public function __construct(private readonly DatabaseBackup $backup) {}

    public function daily(): string
    {
        $file = $this->backup->create('daily');
        $this->prune();

        return basename($file);
    }

    public function prune(): void
    {
        $daily = array_values(array_filter($this->all(), fn (array $b) => str_starts_with($b['name'], 'kasmin-daily-')));
        foreach (array_slice($daily, max(1, Settings::int('backup_keep'))) as $old) {
            Storage::disk('local')->delete($old['path']);
        }
    }

    /** @return list<array{path: string, name: string, size: int, at: Carbon}> newest first */
    public function all(): array
    {
        $disk = Storage::disk('local');
        $files = [];
        foreach ($disk->files(trim((string) config('kasmin.backup_path'), '/')) as $path) {
            if (str_ends_with($path, '.sql.gz')) {
                $files[] = ['path' => $path, 'name' => basename($path), 'size' => $disk->size($path), 'at' => Carbon::createFromTimestamp($disk->lastModified($path), (string) config('app.timezone'))];
            }
        }
        usort($files, fn (array $a, array $b) => [$b['at']->timestamp, $b['name']] <=> [$a['at']->timestamp, $a['name']]);

        return $files;
    }
}
