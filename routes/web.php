<?php

use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\StepUpController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\EventListController;
use App\Http\Controllers\InstallController;
use App\Http\Controllers\InvoicePdfController;
use App\Http\Controllers\LanguageController;
use App\Http\Controllers\MailPreferencesController;
use App\Http\Controllers\PortalChildController;
use App\Http\Controllers\PortalController;
use App\Http\Controllers\PortalFormController;
use App\Http\Controllers\PublicRegistrationController;
use App\Http\Controllers\ViewAsController;
use App\Http\Middleware\ReadOnlyPortalPreview;
use App\Http\Middleware\RequireFullLogin;
use App\Http\Middleware\RequireSecondFactor;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check() ? redirect()->route('portal') : view('welcome');
});

Route::post('/language', [LanguageController::class, 'update'])->middleware('throttle:60,1,language')->name('language');

Route::get('/install', [InstallController::class, 'show'])->name('install');
Route::post('/install', [InstallController::class, 'store'])->middleware('throttle:install');

Route::get('/mail/preferences/{person}', [MailPreferencesController::class, 'show'])->middleware('signed')->name('mail.preferences');
Route::post('/mail/preferences/{person}', [MailPreferencesController::class, 'update'])->middleware(['signed', 'throttle:preferences'])->name('mail.preferences.update');

Route::middleware('guest')->group(function () {
    Route::get('/register', [PublicRegistrationController::class, 'show'])->name('register');
    Route::post('/register', [PublicRegistrationController::class, 'store'])->middleware('throttle:register')->name('register.store');
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'send'])->middleware('throttle:login-send')->name('login.send');
    Route::get('/login/code', [LoginController::class, 'showCode'])->name('login.code');
    Route::post('/login/code', [LoginController::class, 'consumeCode'])->middleware('throttle:login-code')->name('login.code.consume');
    Route::get('/login/link/{token}', [LoginController::class, 'showLink'])->name('login.link');
    Route::post('/login/link/{token}', [LoginController::class, 'consumeLink'])->middleware('throttle:login-link')->name('login.link.consume');
});

// Approval links of automations (AT03, AT04): the token is personal, one-time and expiring; GET only shows the plan.
Route::get('/approvals/done', [ApprovalController::class, 'done'])->name('approvals.done');
Route::get('/approvals/{token}', [ApprovalController::class, 'show'])->name('approvals.show');
Route::post('/approvals/{token}', [ApprovalController::class, 'decide'])->middleware('throttle:approvals')->name('approvals.decide');

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');
    Route::get('/login/confirm', [StepUpController::class, 'show'])->name('step-up');
    Route::post('/login/confirm', [StepUpController::class, 'send'])->middleware('throttle:login-send')->name('step-up.send');
    Route::get('/two-factor', [TwoFactorController::class, 'show'])->name('two-factor');
    Route::post('/two-factor', [TwoFactorController::class, 'verify'])->middleware('throttle:two-factor')->name('two-factor.verify');
    Route::get('/admin/invoices/{invoice}/pdf', [InvoicePdfController::class, 'admin'])->middleware([RequireSecondFactor::class, RequireFullLogin::class])->name('admin.invoices.pdf');
    Route::middleware([RequireSecondFactor::class, RequireFullLogin::class])->group(function () {
        Route::get('/admin/events/{event}/lists/{key}.{format}', [EventListController::class, 'list'])->whereNumber('event')->where('key', '[a-z_]+')->where('format', 'pdf|xlsx|csv')->name('admin.lists.export');
        Route::get('/admin/events/{event}/camp-folder/{person?}', [EventListController::class, 'folder'])->whereNumber(['event', 'person'])->name('admin.lists.folder');
    });
    Route::post('/portal/view-as/end', [ViewAsController::class, 'end'])->name('portal.view-as.end');
    Route::middleware([RequireSecondFactor::class, ReadOnlyPortalPreview::class])->prefix('portal')->group(function () {
        Route::get('/', [PortalController::class, 'index'])->name('portal');
        Route::get('/mails/{uuid}', [PortalController::class, 'mail'])->name('portal.mail');
        Route::get('/invoices/{invoice}.pdf', [InvoicePdfController::class, 'portal'])->name('portal.invoice.pdf');
        Route::get('/children/new', [PortalChildController::class, 'create'])->middleware(RequireFullLogin::class)->name('portal.child.create');
        Route::post('/children', [PortalChildController::class, 'store'])->middleware(RequireFullLogin::class)->name('portal.child.store');
        Route::get('/children/{child}', [PortalChildController::class, 'show'])->name('portal.child');
        Route::post('/children/{child}/register', [PortalChildController::class, 'register'])->middleware(RequireFullLogin::class)->name('portal.child.register');
        Route::post('/children/{child}/participations/{participation}/cancel', [PortalChildController::class, 'cancel'])->middleware(RequireFullLogin::class)->name('portal.child.cancel');
        Route::post('/children/{child}/participations/{participation}/offer', [PortalChildController::class, 'answerOffer'])->name('portal.child.offer');
        Route::get('/children/{child}/forms/{form}', [PortalFormController::class, 'edit'])->middleware(RequireFullLogin::class)->name('portal.form.edit');
        Route::post('/children/{child}/forms/{form}', [PortalFormController::class, 'update'])->middleware(RequireFullLogin::class)->name('portal.form.update');
        Route::post('/children/{child}/forms/{form}/confirm', [PortalFormController::class, 'confirm'])->middleware(RequireFullLogin::class)->name('portal.form.confirm');
        Route::get('/families/{household}/emergency-contacts', [PortalFormController::class, 'emergencyContacts'])->middleware(RequireFullLogin::class)->name('portal.emergency');
        Route::post('/families/{household}/emergency-contacts', [PortalFormController::class, 'saveEmergencyContacts'])->middleware(RequireFullLogin::class)->name('portal.emergency.save');
    });
});
