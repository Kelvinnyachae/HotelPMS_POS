<?php

use App\Http\Controllers\ClientLoginController;
use App\Http\Middleware\FoundationPreviewOnly;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::get('/login', [ClientLoginController::class, 'create'])->name('login');
Route::post('/login', [ClientLoginController::class, 'store'])->name('login.store');
Route::post('/logout', [ClientLoginController::class, 'destroy'])->middleware('auth')->name('logout');

Route::middleware(FoundationPreviewOnly::class)->group(function (): void {
    Route::view('/control', 'surfaces.control')->name('platform.preview');
    Route::get('/workspace', fn () => view('surfaces.workspace'))
        ->middleware(['auth', 'tenant.context'])
        ->name('workspace.preview');
});
