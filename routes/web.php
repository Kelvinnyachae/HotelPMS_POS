<?php

use App\Http\Middleware\FoundationPreviewOnly;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::middleware(FoundationPreviewOnly::class)->group(function (): void {
    Route::view('/control', 'surfaces.control')->name('platform.preview');
    Route::view('/workspace', 'surfaces.workspace')->name('workspace.preview');
});
