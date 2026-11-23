<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\InstallController;
use App\Http\Controllers\PortalController;
use App\Http\Middleware\RequireSecondFactor;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check() ? redirect()->route('portal') : view('welcome');
});

Route::get('/install', [InstallController::class, 'show'])->name('install');
Route::post('/install', [InstallController::class, 'store'])->middleware('throttle:10,1');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'send'])->middleware('throttle:5,1')->name('login.send');
    Route::get('/login/code', [LoginController::class, 'showCode'])->name('login.code');
    Route::post('/login/code', [LoginController::class, 'consumeCode'])->middleware('throttle:10,1')->name('login.code.consume');
    Route::get('/login/link/{token}', [LoginController::class, 'showLink'])->name('login.link');
    Route::post('/login/link/{token}', [LoginController::class, 'consumeLink'])->middleware('throttle:10,1')->name('login.link.consume');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');
    Route::get('/two-factor', [TwoFactorController::class, 'show'])->name('two-factor');
    Route::post('/two-factor', [TwoFactorController::class, 'verify'])->middleware('throttle:10,1')->name('two-factor.verify');
    Route::get('/portal', [PortalController::class, 'index'])->middleware(RequireSecondFactor::class)->name('portal');
});
