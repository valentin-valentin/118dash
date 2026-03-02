<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::redirect('/', '/dashboard');

require __DIR__.'/settings.php';

Route::middleware(['auth'])->group(function () {

    // ── Pages (Inertia) ───────────────────────────────────────────────────────
    Route::get('/dashboard', fn () => Inertia::render('Dashboard'))->name('dashboard');
    // Route::get('/users',    fn () => Inertia::render('Users'))->name('users');
    // Route::get('/orders',   fn () => Inertia::render('Orders'))->name('orders');

    // ── Data endpoints (XHR / JSON) ───────────────────────────────────────────
    // All return JSON for Vue XHR calls. Add your routes here.
    Route::prefix('data')->group(function () {
        Route::get('/stats',   [DashboardController::class, 'stats']);
        Route::get('/example', [DashboardController::class, 'example']);
    });
});
