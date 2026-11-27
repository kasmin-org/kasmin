<?php

use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\AutomationsController;
use App\Http\Controllers\Api\ContactPointsController;
use App\Http\Controllers\Api\DivisionsController;
use App\Http\Controllers\Api\DuplicatesController;
use App\Http\Controllers\Api\EventListsController;
use App\Http\Controllers\Api\EventPartsController;
use App\Http\Controllers\Api\EventsController;
use App\Http\Controllers\Api\EventSeriesController;
use App\Http\Controllers\Api\FinanceController;
use App\Http\Controllers\Api\FormsController;
use App\Http\Controllers\Api\GroupsController;
use App\Http\Controllers\Api\GuardiansController;
use App\Http\Controllers\Api\HouseholdsController;
use App\Http\Controllers\Api\ImportsController;
use App\Http\Controllers\Api\MailController;
use App\Http\Controllers\Api\MailLogController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\MembershipsController;
use App\Http\Controllers\Api\MembershipTypesController;
use App\Http\Controllers\Api\PeopleController;
use App\Http\Controllers\Api\PrivacyController;
use App\Http\Controllers\Api\PublicController;
use App\Http\Controllers\Api\PublicFormsController;
use App\Http\Controllers\Api\ReportsController;
use App\Http\Controllers\Api\RolesController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\WebhooksController;
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
    Route::get('/people/{person}/memberships', [MembershipsController::class, 'index']);
    Route::post('/people/{person}/memberships', [MembershipsController::class, 'store']);
    Route::patch('/people/{person}/memberships/{membership}', [MembershipsController::class, 'update'])->whereNumber('membership');
    Route::get('/roles', [RolesController::class, 'index']);
    Route::get('/people/{person}/roles', [RolesController::class, 'ofPerson']);
    Route::post('/people/{person}/roles', [RolesController::class, 'store']);
    Route::delete('/people/{person}/roles/{assignment}', [RolesController::class, 'destroy'])->whereNumber('assignment');
    Route::get('/duplicates', [DuplicatesController::class, 'index']);
    Route::post('/duplicates/merge', [DuplicatesController::class, 'merge']);
    Route::post('/duplicates/dismiss', [DuplicatesController::class, 'dismiss']);
    Route::get('/households', [PeopleController::class, 'households']);
    Route::get('/households/{household}', [PeopleController::class, 'household']);
    Route::post('/households', [HouseholdsController::class, 'store']);
    Route::patch('/households/{household}', [HouseholdsController::class, 'update']);
    Route::post('/households/{household}/members/{person}', [HouseholdsController::class, 'addMember']);
    Route::delete('/households/{household}/members/{person}', [HouseholdsController::class, 'removeMember']);

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
    Route::post('/participations/{participation}/reserve-place', [EventsController::class, 'reservePlace'])->whereNumber('participation');
    Route::post('/events/{event}/offers', [EventsController::class, 'offer']);
    Route::post('/events/{event}/reserve-places/release', [EventsController::class, 'releaseReserve']);
    Route::get('/events/{event}/schedule', [EventsController::class, 'schedule']);
    Route::get('/event-series', [EventSeriesController::class, 'index']);
    Route::post('/event-series', [EventSeriesController::class, 'store']);
    Route::patch('/event-series/{series}', [EventSeriesController::class, 'update'])->whereNumber('series');
    Route::get('/event-templates', [EventsController::class, 'templates']);
    Route::post('/event-templates', [EventsController::class, 'storeTemplate']);
    Route::patch('/event-templates/{template}', [EventsController::class, 'updateTemplate']);
    Route::delete('/event-templates/{template}', [EventsController::class, 'destroyTemplate']);
    Route::get('/events/{event}/rounds', [EventPartsController::class, 'rounds']);
    Route::put('/events/{event}/rounds', [EventPartsController::class, 'saveRounds']);
    Route::get('/events/{event}/costs', [EventPartsController::class, 'costs']);
    Route::post('/events/{event}/costs', [EventPartsController::class, 'storeCost']);
    Route::patch('/costs/{cost}', [EventPartsController::class, 'updateCost'])->whereNumber('cost');
    Route::delete('/costs/{cost}', [EventPartsController::class, 'destroyCost'])->whereNumber('cost');
    Route::get('/events/{event}/documents', [EventPartsController::class, 'documents']);
    Route::post('/events/{event}/documents', [EventPartsController::class, 'storeDocument']);
    Route::get('/event-documents/{document}/download', [EventPartsController::class, 'downloadDocument'])->whereNumber('document');
    Route::delete('/event-documents/{document}', [EventPartsController::class, 'destroyDocument'])->whereNumber('document');

    Route::get('/events/{event}/divisions', [DivisionsController::class, 'index']);
    Route::post('/events/{event}/divisions', [DivisionsController::class, 'store']);
    Route::delete('/divisions/{division}', [DivisionsController::class, 'destroy'])->whereNumber('division');
    Route::post('/divisions/{division}/propose', [DivisionsController::class, 'propose'])->whereNumber('division');
    Route::put('/divisions/{division}/members/{participation}', [DivisionsController::class, 'move'])->whereNumber(['division', 'participation']);
    Route::post('/divisions/{division}/units', [DivisionsController::class, 'addUnit'])->whereNumber('division');
    Route::patch('/units/{unit}', [DivisionsController::class, 'updateUnit'])->whereNumber('unit');
    Route::delete('/units/{unit}', [DivisionsController::class, 'destroyUnit'])->whereNumber('unit');
    Route::get('/events/{event}/lists', [EventListsController::class, 'index']);
    Route::get('/events/{event}/lists/{key}', [EventListsController::class, 'show'])->where('key', '[a-z_]+');

    Route::get('/invoices', [FinanceController::class, 'index']);
    Route::get('/invoices/{invoice}', [FinanceController::class, 'show'])->whereNumber('invoice');
    Route::get('/invoices/{invoice}/pdf', [FinanceController::class, 'pdf'])->whereNumber('invoice');
    Route::post('/invoices/{invoice}/payments', [FinanceController::class, 'pay'])->whereNumber('invoice');
    Route::post('/invoices/{invoice}/issue', [FinanceController::class, 'issue'])->whereNumber('invoice');
    Route::post('/invoices/{invoice}/send', [FinanceController::class, 'send'])->whereNumber('invoice');
    Route::post('/invoices/{invoice}/cancel', [FinanceController::class, 'cancel'])->whereNumber('invoice');
    Route::get('/payments', [FinanceController::class, 'payments']);
    Route::post('/payments/{payment}/assign', [FinanceController::class, 'assignPayment'])->whereNumber('payment');
    Route::get('/refunds', [FinanceController::class, 'refunds']);
    Route::post('/refunds/{refund}/done', [FinanceController::class, 'refundDone'])->whereNumber('refund');
    Route::post('/refunds/{refund}/keep', [FinanceController::class, 'refundKeep'])->whereNumber('refund');
    Route::get('/invoice-archive', [FinanceController::class, 'archive']);

    Route::get('/automations', [AutomationsController::class, 'index']);
    Route::post('/automations', [AutomationsController::class, 'store']);
    Route::get('/automations/{automation}', [AutomationsController::class, 'show']);
    Route::patch('/automations/{automation}', [AutomationsController::class, 'update']);
    Route::delete('/automations/{automation}', [AutomationsController::class, 'destroy']);
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
    Route::get('/mail-templates/{template}/versions', [MailLogController::class, 'versions']);
    Route::post('/mail-templates/{template}/versions/{version}/restore', [MailLogController::class, 'restoreVersion'])->whereNumber('version');
    Route::get('/mailings', [MailController::class, 'mailings']);
    Route::post('/mailings', [MailController::class, 'createMailing']);
    Route::get('/mailings/{mailing}', [MailController::class, 'mailing'])->whereNumber('mailing');
    Route::post('/mailings/{mailing}/prepare', [MailController::class, 'prepareMailing'])->whereNumber('mailing');
    Route::post('/mailings/{mailing}/release', [MailController::class, 'releaseMailing'])->whereNumber('mailing');
    Route::get('/outgoing-mails', [MailLogController::class, 'outgoing']);
    Route::get('/bad-addresses', [MailLogController::class, 'badAddresses']);
    Route::post('/bad-addresses/{address}/clear', [MailLogController::class, 'clearBadAddress'])->whereNumber('address');
    Route::get('/sender-profiles', [MailLogController::class, 'senderProfiles']);
    Route::post('/sender-profiles', [MailLogController::class, 'createSenderProfile']);
    Route::patch('/sender-profiles/{profile}', [MailLogController::class, 'updateSenderProfile'])->whereNumber('profile');

    Route::get('/public-forms', [PublicFormsController::class, 'index']);
    Route::get('/public-submissions', [PublicFormsController::class, 'submissions']);
    Route::post('/public-submissions/{submission}/accept', [PublicFormsController::class, 'accept'])->whereNumber('submission');
    Route::post('/public-submissions/{submission}/reject', [PublicFormsController::class, 'reject'])->whereNumber('submission');

    Route::get('/consents', [PrivacyController::class, 'consents']);
    Route::get('/deletion-requests', [PrivacyController::class, 'deletionRequests']);
    Route::get('/people/{person}/export', [PrivacyController::class, 'export']);
    Route::post('/people/{person}/anonymise', [PrivacyController::class, 'anonymise']);

    Route::get('/statistics', [ReportsController::class, 'statistics']);
    Route::get('/statistics/age-groups', [ReportsController::class, 'ageGroups']);
    Route::get('/dashboard', [ReportsController::class, 'dashboard']);

    Route::get('/webhooks', [WebhooksController::class, 'index']);
    Route::post('/webhooks', [WebhooksController::class, 'store']);
    Route::patch('/webhooks/{webhook}', [WebhooksController::class, 'update'])->whereNumber('webhook');
    Route::post('/webhooks/{webhook}/secret', [WebhooksController::class, 'newSecret'])->whereNumber('webhook');
    Route::delete('/webhooks/{webhook}', [WebhooksController::class, 'destroy'])->whereNumber('webhook');

    Route::get('/settings', [SettingsController::class, 'index']);
    Route::patch('/settings', [SettingsController::class, 'update']);

    Route::get('/audit', [AuditController::class, 'index']);
});
