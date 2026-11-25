<?php

namespace App\Support\Backup;

use App\Support\Settings;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Copy of the daily backup outside the server (N07): encrypted with the passphrase and sent by WebDAV (HTTPS PUT) to
 * a folder of a storage service, e.g. Nextcloud or a storage box. Nothing leaves the server unencrypted.
 */
class RemoteBackup
{
    public static function configured(): bool
    {
        return self::url() !== '' && self::secret('backup_passphrase') !== '';
    }

    public static function url(): string
    {
        return rtrim((string) Settings::get('backup_remote_url'), '/');
    }

    public static function secret(string $key): string
    {
        $value = (string) Settings::get($key);
        try {
            return $value === '' ? '' : Crypt::decryptString($value);
        } catch (Throwable) {
            return '';
        }
    }

    public static function storeSecret(string $key, string $value): void
    {
        Settings::set($key, $value === '' ? '' : Crypt::encryptString($value));
    }

    /** @return array{ok: bool|null, at: string|null, error: string|null, files: list<string>} */
    public static function state(): array
    {
        $state = json_decode((string) Settings::get('backup_remote_state'), true);

        return (is_array($state) ? $state : []) + ['ok' => null, 'at' => null, 'error' => null, 'files' => []];
    }

    /** Sends one backup file; older copies beyond the number of daily backups are removed. */
    public function upload(string $path): bool
    {
        $state = self::state();
        $name = basename($path).'.enc';
        try {
            if (! str_starts_with(self::url(), 'https://')) {
                throw new \RuntimeException('Only https addresses are used.');
            }
            $body = BackupCrypt::encrypt((string) Storage::disk('local')->get($path), self::secret('backup_passphrase'));
            $response = $this->client()->withBody($body, 'application/octet-stream')->put(self::url().'/'.$name);
            if (! $response->successful()) {
                throw new \RuntimeException('The storage answered with status '.$response->status().'.');
            }
            $state['files'] = array_values(array_unique([...$state['files'], $name]));
            foreach (array_splice($state['files'], 0, max(0, count($state['files']) - max(1, Settings::int('backup_keep')))) as $old) {
                rescue(fn () => $this->client()->delete(self::url().'/'.$old), report: false);
            }
            $state = ['ok' => true, 'at' => now()->toIso8601String(), 'error' => null, 'files' => $state['files']];
        } catch (Throwable $e) {
            $state = ['ok' => false, 'at' => now()->toIso8601String(), 'error' => mb_substr($e->getMessage(), 0, 300), 'files' => $state['files']];
        }
        Settings::set('backup_remote_state', (string) json_encode($state));

        return $state['ok'];
    }

    private function client(): PendingRequest
    {
        $client = Http::timeout(120)->connectTimeout(15);
        $user = (string) Settings::get('backup_remote_user');

        return $user === '' ? $client : $client->withBasicAuth($user, self::secret('backup_remote_password'));
    }
}
