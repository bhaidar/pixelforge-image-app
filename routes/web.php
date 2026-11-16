<?php

use App\Http\Controllers\AdminReportController;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\GenerationController;
use App\Http\Middleware\EnsureAdmin;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::post('webhooks/pixelforge', \App\Http\Controllers\PixelforgeWebhookController::class)->name('webhooks.pixelforge');

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

Route::redirect('dashboard', '/generate')->middleware('auth')->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('generations', [GenerationController::class, 'index'])->name('generations.index');
    Route::get('generate', [GenerationController::class, 'create'])->name('generations.create');
    Route::post('generations/estimate', [GenerationController::class, 'estimate'])->name('generations.estimate');
    Route::post('generations', [GenerationController::class, 'store'])->name('generations.store');

    Route::get('credits', [CreditController::class, 'show'])->name('credits.show');
    Route::post('credits', [CreditController::class, 'buy'])->name('credits.buy');

    Route::get('admin/report', AdminReportController::class)->middleware(EnsureAdmin::class)->name('admin.report');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
