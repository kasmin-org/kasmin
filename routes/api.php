<?php

use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\AutomationsController;
use App\Http\Controllers\Api\ContactPointsController;
use App\Http\Controllers\Api\EventsController;
use App\Http\Controllers\Api\FinanceController;
use App\Http\Controllers\Api\FormsController;
use App\Http\Controllers\Api\GroupsController;
use App\Http\Controllers\Api\GuardiansController;
use App\Http\Controllers\Api\ImportsController;
use App\Http\Controllers\Api\MailController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\MembershipTypesController;
use App\Http\Controllers\Api\PeopleController;
use App\Http\Controllers\Api\PublicController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\Idempotency;
use Illuminate\Support\Facades\Route;

// REST API v1 (X01–X05). Bearer token with scopes; see docs/admin/api.md and /api/openapi.json.
// Public events and forms for own websites, without a token (PF02, RG09).
Route::prefix('v1/public')->group(function () {
    Route::get('/events', [PublicController::class, 'events'])->middleware('throttle:api');
    Route::get('/forms/{slug}', [PublicController::class, 'form'])->middleware('throttle:api');
    Route::post('/forms/{slug}', [PublicController::class, 'submit'])->middleware('throttle:public-form');
});

Route::prefix('v1')->middleware([AuthenticateApiToken::class, 'throttle:api', Idempotency::class])->group(function () {
    Route::get('/me', [MeController::class, 'show']);

    Route::get('/people', [PeopleController::class, 'index']);
    Route::post('/people', [PeopleController::class, 'store']);
    Route::get('/people/{person}', [PeopleController::class, 'show']);
    Route::patch('/people/{person}', [PeopleController::class, 'update']);
    Route::get('/people/{person}/contact-points', [ContactPointsController::class, 'index']);
    Route::post('/people/{person}/contact-points', [ContactPointsController::class, 'store']);
    Route::delete('/people/{person}/contact-points/{point}', [ContactPointsController::class, 'destroy'])->whereNumber('point');
    Route::get('/people/{person}/guardians', [GuardiansController::class, 'index']);
    Route::post('/people/{person}/guardians', [GuardiansController::class, 'store']);
    Route::delete('/people/{person}/guardians/{guardian}', [GuardiansController::class, 'destroy']);
    Route::get('/households', [PeopleController::class, 'households']);
    Route::get('/households/{household}', [PeopleController::class, 'household']);

    Route::get('/membership-types', [MembershipTypesController::class, 'index']);
    Route::post('/membership-types', [MembershipTypesController::class, 'store']);
    Route::patch('/membership-types/{type}', [MembershipTypesController::class, 'update']);

    Route::get('/events', [EventsController::class, 'index']);
    Route::post('/events', [EventsController::class, 'store']);
    Route::get('/events/{event}', [EventsController::class, 'show']);
    Route::patch('/events/{event}', [EventsController::class, 'update']);
    Route::get('/events/{event}/participations', [EventsController::class, 'participations']);
    Route::post('/events/{event}/registrations', [EventsController::class, 'register']);
    Route::patch('/participations/{participation}', [EventsController::class, 'updateParticipation'])->whereNumber('participation');

    Route::get('/invoices', [FinanceController::class, 'index']);
    Route::get('/invoices/{invoice}', [FinanceController::class, 'show'])->whereNumber('invoice');
    Route::get('/invoices/{invoice}/pdf', [FinanceController::class, 'pdf'])->whereNumber('invoice');
    Route::post('/invoices/{invoice}/payments', [FinanceController::class, 'pay'])->whereNumber('invoice');

    Route::get('/automations', [AutomationsController::class, 'index']);
    Route::get('/automations/{automation}', [AutomationsController::class, 'show']);
    Route::patch('/automations/{automation}', [AutomationsController::class, 'update']);
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

    Route::get('/groups', [GroupsController::class, 'index']);
    Route::post('/groups', [GroupsController::class, 'store']);
    Route::get('/groups/{group}', [GroupsController::class, 'show'])->whereNumber('group');
    Route::patch('/groups/{group}', [GroupsController::class, 'update'])->whereNumber('group');
    Route::post('/groups/{group}/members', [GroupsController::class, 'addMember'])->whereNumber('group');
    Route::delete('/groups/{group}/members/{person}', [GroupsController::class, 'removeMember'])->whereNumber('group');

    Route::get('/forms', [FormsController::class, 'index']);
    Route::get('/forms/{form}/definition', [FormsController::class, 'definition']);
    Route::put('/forms/{form}/definition', [FormsController::class, 'saveDefinition']);
    Route::get('/people/{person}/forms', [FormsController::class, 'status']);
    Route::get('/people/{person}/forms/{form}', [FormsController::class, 'show']);
    Route::patch('/people/{person}/forms/{form}', [FormsController::class, 'update']);

    Route::get('/mail-templates', [MailController::class, 'templates']);
    Route::get('/mail-templates/{template}', [MailController::class, 'template']);
    Route::patch('/mail-templates/{template}', [MailController::class, 'updateTemplate']);
    Route::post('/mail-templates/{template}/preview', [MailController::class, 'preview']);
    Route::get('/mailings', [MailController::class, 'mailings']);
    Route::post('/mailings', [MailController::class, 'createMailing']);
    Route::get('/mailings/{mailing}', [MailController::class, 'mailing'])->whereNumber('mailing');
    Route::post('/mailings/{mailing}/prepare', [MailController::class, 'prepareMailing'])->whereNumber('mailing');
    Route::post('/mailings/{mailing}/release', [MailController::class, 'releaseMailing'])->whereNumber('mailing');

    Route::get('/settings', [SettingsController::class, 'index']);
    Route::patch('/settings', [SettingsController::class, 'update']);

    Route::get('/audit', [AuditController::class, 'index']);
});
