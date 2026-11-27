<?php

namespace App\Livewire;

use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\System\Releases;
use App\Support\System\SystemNotifications;
use App\Support\System\Updater;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Livewire\Component;
use Throwable;

/**
 * Update section of the system page (UP01): any administrator can look for newer versions, choose one of them, read
 * what changes up to it and start the self-update.
 */
class SystemUpdate extends Component
{
    /** @var array{release: ?array<string, mixed>, checks: list<array{label: string, ok: bool}>, passes: bool}|null */
    public ?array $precheck = null;

    /** @var array<string, string> version => label, newest first */
    public array $versions = [];

    /** The version that was chosen; the newest one to begin with. */
    public ?string $target = null;

    public ?string $error = null;

    public function check(): void
    {
        $this->authorizeAdmin();
        $this->error = null;
        $this->versions = [];
        foreach (app(Releases::class)->newer() as $i => $release) {
            $date = $release['published_at'] !== null ? ' – '.Carbon::parse($release['published_at'])->isoFormat('LL') : '';
            $this->versions[$release['version']] = $release['version'].$date.($i === 0 ? ' ('.__('system.update.newest').')' : '');
        }
        $this->target = array_key_first($this->versions);
        $this->precheck = app(Updater::class)->precheck($this->target);
    }

    /** Another version was chosen: its requirements and its changes. */
    public function updatedTarget(): void
    {
        $this->authorizeAdmin();
        if (! isset($this->versions[(string) $this->target])) {
            $this->target = array_key_first($this->versions);
        }
        $this->precheck = app(Updater::class)->precheck($this->target);
    }

    public function update(): void
    {
        $this->authorizeAdmin();
        if ($this->target === null || ! isset($this->versions[$this->target])) {
            return;
        }
        Audit::record('system.update_started', null, null, ['from' => config('kasmin.version'), 'to' => $this->target]);
        try {
            $this->redirect(app(Updater::class)->run($this->target));
        } catch (Throwable $e) {
            $this->error = $e->getMessage();
            Audit::record('system.update_failed', null, null, ['error' => $e->getMessage()]);
        }
    }

    public function render(): View
    {
        return view('livewire.system-update', [
            'current' => config('kasmin.version'),
            // Everything between the installed and the chosen version, read from the changelog of the chosen one.
            'changes' => $this->precheck !== null && $this->target !== null ? app(Releases::class)->changes($this->target) : '',
        ]);
    }

    private function authorizeAdmin(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && app(SystemNotifications::class)->administrators()->contains('id', $user->id), 403);
    }
}
