<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Support\Audit\Audit;
use App\Support\Demo\DemoData;
use App\Support\Install\Installer;
use App\Support\Schema\SchemaGuard;
use App\Support\System\Updater;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * HTTP endpoints for hosting without a command line (e.g. shared hosting whose cron jobs can only call URLs):
 *
 * - POST /system/hooks/upgrade   – runs kasmin:upgrade (used by deployment pipelines after uploading a new release)
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

    public function demo(Request $request, Installer $installer, DemoData $demo): JsonResponse
    {
        $this->authorizeHook($request);
        abort_unless(app()->environment('demo'), 404);

        // Demo instances can be emptied and filled again (never anywhere else).
        if ($request->boolean('reset') && $installer->isInstalled()) {
            Schema::dropAllTables();
            Cache::flush();
        }
        if ($installer->isInstalled()) {
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
