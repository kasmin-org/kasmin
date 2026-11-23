<?php

namespace App\Livewire;

use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\System\SystemNotifications;
use App\Support\System\Updater;
use Illuminate\View\View;
use Livewire\Component;
use Throwable;

/**
 * Update section of the system page (UP01): any administrator can check and start the self-update.
 */
class SystemUpdate extends Component
{
    /** @var array{release: ?array<string, mixed>, checks: list<array{label: string, ok: bool}>, passes: bool}|null */
    public ?array $precheck = null;

    public ?string $error = null;

    public function check(): void
    {
        $this->authorizeAdmin();
        $this->precheck = app(Updater::class)->precheck();
    }

    public function update(): void
    {
        $this->authorizeAdmin();
        Audit::record('system.update_started', null, null, ['from' => config('kasmin.version')]);
        try {
            $this->redirect(app(Updater::class)->run());
        } catch (Throwable $e) {
            $this->error = $e->getMessage();
            Audit::record('system.update_failed', null, null, ['error' => $e->getMessage()]);
        }
    }

    public function render(): View
    {
        return view('livewire.system-update', ['current' => config('kasmin.version')]);
    }

    private function authorizeAdmin(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && app(SystemNotifications::class)->administrators()->contains('id', $user->id), 403);
    }
}
