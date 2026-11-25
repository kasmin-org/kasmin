<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Schema\SchemaGuard;
use App\Support\Schema\SchemaStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Upgrade of the database in the browser: after new files were installed, administrators log in and press a button.
 * No command line is needed for anything in Kasmin.
 */
class UpgradeController extends Controller
{
    public function __construct(private readonly SchemaGuard $guard) {}

    public function show(Request $request): View|RedirectResponse
    {
        if ($this->guard->status() === SchemaStatus::Ok) {
            return redirect($request->user() !== null ? '/admin' : route('login'));
        }

        return $this->refuse($request) ?? view('system.upgrade', $this->data());
    }

    public function run(Request $request): View|RedirectResponse
    {
        if ($this->guard->status() === SchemaStatus::Ok) {
            return redirect('/admin');
        }
        if (($refused = $this->refuse($request)) !== null) {
            return $refused;
        }
        // Shared hosting often stops requests after 30 seconds; the upgrade must not be cut off.
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        ignore_user_abort(true);
        $before = $this->guard->databaseVersion();
        try {
            $code = Artisan::call('kasmin:upgrade');
            $output = trim(Artisan::output());
        } catch (\Throwable $e) {
            report($e);
            [$code, $output] = [1, $e->getMessage()];
        }
        $ok = $code === 0 && $this->guard->status(fresh: true) === SchemaStatus::Ok;
        if ($ok) {
            rescue(fn () => Audit::record('system.upgraded', null, null, ['from' => $before, 'to' => $this->guard->codeVersion(), 'by' => 'web']), report: false);
        }

        return view('system.upgrade', ['done' => $ok, 'failed' => ! $ok, 'output' => $output] + $this->data());
    }

    /** Only administrators with a full login (not a link from an older mail). */
    private function refuse(Request $request): ?RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return redirect()->route('login');
        }
        if (! $user->hasAnyRole(['administrator']) || $request->session()->get('login_scope') !== 'full') {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => __('system.upgrade.only_administrators')]);
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function data(): array
    {
        return [
            'codeVersion' => $this->guard->codeVersion(), 'databaseVersion' => $this->guard->databaseVersion(),
            'pending' => count($this->guard->pendingMigrations()), 'done' => false, 'failed' => false, 'output' => '',
        ];
    }
}
