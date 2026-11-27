<?php

use App\Http\Controllers\System\HookController;
use Illuminate\Support\Facades\Route;

// HTTP hooks for hosting without a command line (disabled without KASMIN_HOOK_TOKEN): no session, no CSRF,
// no cache-based rate limit (works before installation); protected by a constant-time token comparison.
Route::prefix('system')->group(function () {
    Route::post('/hooks/upgrade', [HookController::class, 'upgrade']);
    Route::post('/hooks/update', [HookController::class, 'update']);
    Route::post('/hooks/demo', [HookController::class, 'demo']);
    Route::match(['get', 'post'], '/cron', [HookController::class, 'cron']);
    // Second step of the self-update (one-time token, valid 30 minutes).
    Route::get('/update/finish', [HookController::class, 'finishUpdate']);
});
