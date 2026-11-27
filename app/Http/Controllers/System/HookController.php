<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Support\Audit\Audit;
use App\Support\Demo\DemoData;
use App\Support\Install\Installer;
use App\Support\Schema\SchemaGuard;
use App\Support\Settings;
use App\Support\System\Releases;
use App\Support\System\Updater;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * HTTP endpoints for hosting without a command line (e.g. shared hosting whose cron jobs can only call URLs):
 *
 * - POST /system/hooks/upgrade   – runs kasmin:upgrade (used by deployment pipelines after uploading a new release)
 * - POST /system/hooks/update    – the installation updates itself to the latest or the named version; the answer
 *                                  names the address that finishes the update
 * - POST /system/hooks/demo      – demo instances only: installs Kasmin with demo data if the database is empty;
 *                                  with ?reset=1 everything is deleted first
 * - GET|POST /system/cron        – runs the scheduler once (web cron, P03)
 *
 * All endpoints are disabled unless KASMIN_HOOK_TOKEN is set (at least 32 characters). The token is sent as
 * "Authorization: Bearer <token>" (or ?token= for cron services that cannot send headers).
 */
class HookController extends Controller
{
    public function upgrade(Request $request): JsonResponse
    {
        $this->authorizeHook($request);
        $code = Artisan::call('kasmin:upgrade');

        return response()->json(['ok' => $code === 0, 'output' => trim(Artisan::output())], $code === 0 ? 200 : 500);
    }

    /**
     * Lets the installation update itself (UP01) – what the "Update" button of the system status does, for deployment
     * pipelines: download and verify the release, back up, replace the files. ?version=0.29.0 names the version;
     * without it the latest one is installed. The answer holds the address that finishes the update (database) with the
     * new code; call it next. Already on that version: ok with "updated": false.
     */
    public function update(Request $request, Updater $updater, Releases $releases): JsonResponse
    {
        $this->authorizeHook($request);
        $installed = (string) preg_replace('/[-+].*$/', '', (string) config('kasmin.version'));
        $version = ltrim((string) $request->input('version', ''), 'v');
        if ($version !== '' && preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
            return response()->json(['ok' => false, 'message' => 'version must look like 1.2.3'], 422);
        }
        if (! $updater->available()) {
            return response()->json(['ok' => false, 'message' => (string) __('system.update.docker')], 409);
        }
        Cache::forget('kasmin.latest_release'); // a pipeline calls right after the release: do not answer from the cache
        Cache::forget('kasmin.releases');
        $target = $version !== '' ? $version : (string) ($releases->latest()['version'] ?? '');
        if ($target === '' || ! version_compare($target, $installed, '>')) {
            return response()->json(['ok' => true, 'updated' => false, 'installed' => $installed, 'message' => 'Nothing newer to install.']);
        }
        Audit::record('system.update_started', null, null, ['from' => $installed, 'to' => $target, 'by' => 'hook'], 'system');
        try {
            $finish = $updater->run($target);
        } catch (Throwable $e) {
            Audit::record('system.update_failed', null, null, ['error' => $e->getMessage()], 'system');

            return response()->json(['ok' => false, 'installed' => $installed, 'message' => $e->getMessage()], 500);
        }

        return response()->json(['ok' => true, 'updated' => true, 'from' => $installed, 'version' => $target, 'finish' => $finish]);
    }

    public function demo(Request $request, Installer $installer, DemoData $demo): JsonResponse
    {
        $this->authorizeHook($request);
        abort_unless(app()->environment('demo'), 404);

        // Demo instances can be emptied and filled again (never anywhere else).
        if ($request->boolean('reset') && $installer->isInstalled()) {
            rescue(fn () => Cache::flush(), report: false); // before the tables go: the cache may live in the database
            Schema::dropAllTables();
        }
        if ($installer->isInstalled()) {
            Settings::set('auto_update_days', '1'); // demos follow a release after one day, also without a pipeline

            return response()->json(['ok' => true, 'installed' => false, 'message' => 'Already installed.']);
        }
        $locale = (string) config('kasmin.demo.locale', 'en') === 'de' ? 'de' : 'en';
        $installer->install(
            $locale === 'de' ? 'Kasmin Demo (Deutsch)' : 'Kasmin Demo (English)',
            $locale,
            $locale,
            'Demo Admin',
            (string) config('kasmin.demo.admin_email'),
        );
        Settings::set('auto_update_days', '1');
        $result = $demo->generate($locale, 30, 15, 2026, (string) config('kasmin.demo.email_domain'));

        return response()->json(['ok' => true, 'installed' => true] + $result);
    }

    public function cron(Request $request): JsonResponse
    {
        $this->authorizeHook($request);
        if (! Schema::hasTable('migrations')) {
            return response()->json(['ok' => false, 'message' => 'Not installed.'], 503);
        }
        Artisan::call('schedule:run');

        return response()->json(['ok' => true, 'output' => trim(Artisan::output())]);
    }

    public function finishUpdate(Request $request): Response
    {
        $ok = app(Updater::class)->finish((string) $request->query('token'));
        abort_unless($ok, 404);
        Audit::record('system.updated', null, null, ['version' => config('kasmin.version')], 'system');

        return response()->view('system.updated', ['version' => config('kasmin.version')]);
    }

    private function authorizeHook(Request $request): void
    {
        $expected = (string) config('kasmin.hook_token');
        $given = (string) ($request->bearerToken() ?? $request->query('token', ''));
        abort_if(strlen($expected) < 32 || ! hash_equals($expected, $given), 404);
        // The schema check would block the upgrade itself.
        app(SchemaGuard::class)->status(fresh: true);
    }
}
