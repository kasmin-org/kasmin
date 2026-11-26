<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Support\Audit\Audit;
use App\Support\Auth\Permissions;
use App\Support\Backup\BackupCrypt;
use App\Support\Backup\Backups;
use App\Support\Backup\DatabaseRestore;
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

    /**
     * Brings a backup back: one of the list, or an uploaded file (also an encrypted one). Replaces everything in the
     * database; the state before is saved as a backup first. Afterwards everybody has to log in again.
     */
    public function restore(Request $request, Backups $backups, DatabaseRestore $restore): RedirectResponse
    {
        $this->authorizeAdministrator();
        abort_unless(DatabaseRestore::available(), 404);
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:200', 'required_without:file'], 'file' => ['nullable', 'file', 'max:204800', 'required_without:name'],
            'passphrase' => ['nullable', 'string', 'max:200'], 'confirm' => ['required', 'in:'.__('system.restore.word')],
        ], ['confirm.in' => __('system.restore.word_needed', ['word' => __('system.restore.word')])]);
        $temporary = null;
        try {
            if ($request->hasFile('file')) {
                $bytes = (string) file_get_contents($request->file('file')->getRealPath());
                if (str_starts_with($bytes, 'Salted__')) {
                    $passphrase = (string) ($data['passphrase'] ?? '') !== '' ? (string) $data['passphrase'] : RemoteBackup::secret('backup_passphrase');
                    $bytes = BackupCrypt::decrypt($bytes, $passphrase) ?? throw new \RuntimeException((string) __('system.backup.wrong_passphrase'));
                }
                $temporary = (string) tempnam(sys_get_temp_dir(), 'kasmin-restore');
                file_put_contents($temporary, $bytes);
                $file = $temporary;
            } else {
                $file = Storage::disk('local')->path($this->path($backups, (string) $data['name']));
            }
            $user = $request->user();
            $before = $restore->restore($file);
            Audit::record('backup.restored', null, null, ['before' => $before, 'by' => $user?->email], 'system');
        } catch (\RuntimeException $e) {
            Notification::make()->title(__('system.restore.refused'))->body($e->getMessage())->danger()->persistent()->send();

            return back();
        } finally {
            if ($temporary !== null) {
                @unlink($temporary);
            }
        }
        // The logins of the backup count now: this session is gone with the old database.
        auth()->guard()->logout();
        $request->session()->invalidate();

        return redirect()->route('login')->with('status', __('system.restore.done', ['backup' => $before]));
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
