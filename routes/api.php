<?php

use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\AutomationsController;
use App\Http\Controllers\Api\EventsController;
use App\Http\Controllers\Api\FinanceController;
use App\Http\Controllers\Api\ImportsController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\PeopleController;
use App\Http\Middleware\AuthenticateApiToken;
use Illuminate\Support\Facades\Route;

// REST API v1 (X01–X05). Bearer token with scopes; see docs/admin/api.md and /api/openapi.json.
Route::prefix('v1')->middleware([AuthenticateApiToken::class, 'throttle:api'])->group(function () {
    Route::get('/me', [MeController::class, 'show']);

    Route::get('/people', [PeopleController::class, 'index']);
    Route::post('/people', [PeopleController::class, 'store']);
    Route::get('/people/{person}', [PeopleController::class, 'show']);
    Route::patch('/people/{person}', [PeopleController::class, 'update']);
    Route::get('/households', [PeopleController::class, 'households']);
    Route::get('/households/{household}', [PeopleController::class, 'household']);

    Route::get('/events', [EventsController::class, 'index']);
    Route::get('/events/{event}', [EventsController::class, 'show']);
    Route::get('/events/{event}/participations', [EventsController::class, 'participations']);
    Route::post('/events/{event}/registrations', [EventsController::class, 'register']);
    Route::patch('/participations/{participation}', [EventsController::class, 'updateParticipation'])->whereNumber('participation');

    Route::get('/invoices', [FinanceController::class, 'index']);
    Route::get('/invoices/{invoice}', [FinanceController::class, 'show'])->whereNumber('invoice');
    Route::get('/invoices/{invoice}/pdf', [FinanceController::class, 'pdf'])->whereNumber('invoice');
    Route::post('/invoices/{invoice}/payments', [FinanceController::class, 'pay'])->whereNumber('invoice');

    Route::get('/automations', [AutomationsController::class, 'index']);
    Route::post('/automations/{automation}/preview', [AutomationsController::class, 'preview']);
    Route::post('/automations/{automation}/run', [AutomationsController::class, 'run']);
    Route::get('/automation-runs', [AutomationsController::class, 'runs']);
    Route::get('/automation-runs/{run}', [AutomationsController::class, 'showRun'])->whereNumber('run');
    Route::post('/automation-runs/{run}/decision', [AutomationsController::class, 'decide'])->whereNumber('run');

    Route::get('/imports', [ImportsController::class, 'index']);
    Route::post('/imports/people/preview', [ImportsController::class, 'preview']);
    Route::post('/imports/people/families', [ImportsController::class, 'families']);
    Route::post('/imports/people', [ImportsController::class, 'store']);
    Route::post('/imports/{batch}/rollback', [ImportsController::class, 'rollback'])->whereNumber('batch');

    Route::get('/audit', [AuditController::class, 'index']);
});
