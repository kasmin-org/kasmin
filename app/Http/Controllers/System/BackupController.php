<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Support\Audit\Audit;
use App\Support\Auth\Permissions;
use App\Support\Backup\BackupCrypt;
use App\Support\Backup\Backups;
use App\Support\Backup\RemoteBackup;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Backups in the browser, for administrators (AD83, N07): make one now, download one (plain or encrypted with the
 * passphrase), send one to the storage outside the server, and open an encrypted one again. No shell is needed.
 */
class BackupController extends Controller
{
    public function create(Backups $backups): RedirectResponse
    {
        $this->authorizeAdministrator();
        $name = $backups->daily();
        Audit::record('backup.created', null, null, ['file' => $name]);
        $sent = RemoteBackup::configured() ? app(RemoteBackup::class)->upload($this->path($backups, $name)) : null;

        $note = Notification::make()->title(__('system.backup.created', ['name' => $name]))->body($sent === null ? null : (string) __($sent ? 'system.backup.sent' : 'system.backup.send_failed', ['error' => (string) RemoteBackup::state()['error']]));
        ($sent === false ? $note->warning() : $note->success())->send();

        return back();
    }

    public function download(Request $request, Backups $backups, string $name): Response
    {
        $this->authorizeAdministrator();
        $path = $this->path($backups, $name);
        $encrypted = $request->boolean('encrypted') && RemoteBackup::secret('backup_passphrase') !== '';
        Audit::record('backup.downloaded', null, null, ['file' => $name, 'encrypted' => $encrypted]);
        $data = (string) Storage::disk('local')->get($path);

        return response($encrypted ? BackupCrypt::encrypt($data, RemoteBackup::secret('backup_passphrase')) : $data, 200, [
            'Content-Type' => 'application/octet-stream', 'Content-Disposition' => 'attachment; filename="'.$name.($encrypted ? '.enc' : '').'"', 'Cache-Control' => 'private, no-store',
        ]);
    }

    /** Opens an encrypted backup again: upload the file, get the database backup that can be imported. */
    public function decrypt(Request $request): Response|RedirectResponse
    {
        $this->authorizeAdministrator();
        $data = $request->validate(['file' => ['required', 'file', 'max:204800'], 'passphrase' => ['nullable', 'string', 'max:200']]);
        $passphrase = (string) ($data['passphrase'] ?? '') !== '' ? (string) $data['passphrase'] : RemoteBackup::secret('backup_passphrase');
        $plain = BackupCrypt::decrypt((string) file_get_contents($request->file('file')->getRealPath()), $passphrase);
        if ($plain === null) {
            Notification::make()->title(__('system.backup.wrong_passphrase'))->danger()->send();

            return back();
        }
        Audit::record('backup.decrypted');

        return response($plain, 200, [
            'Content-Type' => 'application/gzip', 'Cache-Control' => 'private, no-store',
            'Content-Disposition' => 'attachment; filename="'.preg_replace('/[^A-Za-z0-9_.-]+/', '-', (string) preg_replace('/\.enc$/', '', $request->file('file')->getClientOriginalName())).'"',
        ]);
    }

    private function path(Backups $backups, string $name): string
    {
        foreach ($backups->all() as $backup) {
            if ($backup['name'] === $name) {
                return $backup['path'];
            }
        }
        abort(404);
    }

    private function authorizeAdministrator(): void
    {
        abort_unless(Permissions::administrator(), 403);
    }
}
