<?php

use App\Http\Controllers\ClientLoginController;
use App\Http\Controllers\ClientMfaController;
use App\Http\Controllers\ClientPasswordResetController;
use App\Http\Controllers\ClientWorkspaceController;
use App\Http\Middleware\FoundationPreviewOnly;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [ClientLoginController::class, 'create'])->name('login');
    Route::post('/login', [ClientLoginController::class, 'store'])->name('login.store');
});
Route::post('/logout', [ClientLoginController::class, 'destroy'])->middleware('auth')->name('logout');

Route::get('/mfa/challenge', [ClientMfaController::class, 'challenge'])->name('mfa.challenge');
Route::post('/mfa/challenge', [ClientMfaController::class, 'verify'])->name('mfa.verify');
Route::get('/mfa/setup', [ClientMfaController::class, 'setup'])->name('mfa.setup');
Route::post('/mfa/setup/confirm', [ClientMfaController::class, 'confirmSetup'])->name('mfa.setup.confirm');

Route::get('/password/forgot', [ClientPasswordResetController::class, 'requestForm'])->name('password.forgot');
Route::post('/password/forgot', [ClientPasswordResetController::class, 'sendResetLink'])->name('password.email');
Route::get('/password/reset/{token}', [ClientPasswordResetController::class, 'resetForm'])->name('password.reset.form');
Route::post('/password/reset', [ClientPasswordResetController::class, 'completeReset'])->name('password.reset');

Route::middleware(FoundationPreviewOnly::class)->group(function (): void {
    Route::view('/control', 'surfaces.control')->name('platform.preview');
    Route::get('/workspace/select', [ClientWorkspaceController::class, 'select'])
        ->middleware(['auth', 'client.mfa'])
        ->name('workspace.select');
    Route::get('/workspace', fn () => view('surfaces.workspace'))
        ->middleware(['auth', 'client.mfa', 'tenant.context'])
        ->name('workspace.preview');
});
