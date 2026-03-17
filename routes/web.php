<?php

use App\Http\Controllers\ApiTesterController;
use App\Http\Controllers\AssignmentHistoryController;
use App\Http\Controllers\BlacklistController;
use App\Http\Controllers\CallController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PartnerStatsController;
use App\Http\Controllers\PhonenumberController;
use App\Http\Controllers\ProviderController;
use App\Http\Controllers\RoutingLogController;
use App\Http\Controllers\SourceController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// ── Routes publiques partenaires ──────────────────────────────────────────────
Route::get('/partners/{sources}/{hash}', [PartnerStatsController::class, 'show']);
Route::prefix('partners/{sources}/{hash}/data')->group(function () {
    Route::get('/daily-breakdown', [PartnerStatsController::class, 'dailyBreakdown']);
    Route::get('/hourly-breakdown', [PartnerStatsController::class, 'hourlyBreakdown']);
    Route::get('/filter-options', [PartnerStatsController::class, 'filterOptions']);
});

Route::redirect('/', '/dashboard');

Route::middleware(['auth'])->group(function () {

    // ── Pages (Inertia) ───────────────────────────────────────────────────────
    Route::get('/dashboard', fn () => Inertia::render('Dashboard'))->name('dashboard');
    Route::get('/calls', [CallController::class, 'index'])->name('calls.index');
    Route::get('/calls/{call}', [CallController::class, 'show'])->name('calls.show');

    Route::get('/providers', [ProviderController::class, 'index'])->name('providers.index');
    Route::get('/providers/create', [ProviderController::class, 'create'])->name('providers.create');
    Route::post('/providers', [ProviderController::class, 'store'])->name('providers.store');
    Route::get('/providers/{provider}/edit', [ProviderController::class, 'edit'])->name('providers.edit');
    Route::put('/providers/{provider}', [ProviderController::class, 'update'])->name('providers.update');

    Route::get('/companies', [CompanyController::class, 'index'])->name('companies.index');
    Route::get('/companies/create', [CompanyController::class, 'create'])->name('companies.create');
    Route::post('/companies', [CompanyController::class, 'store'])->name('companies.store');
    Route::get('/companies/{company}/edit', [CompanyController::class, 'edit'])->name('companies.edit');
    Route::put('/companies/{company}', [CompanyController::class, 'update'])->name('companies.update');

    Route::get('/sources', [SourceController::class, 'index'])->name('sources.index');
    Route::get('/sources/create', [SourceController::class, 'create'])->name('sources.create');
    Route::post('/sources', [SourceController::class, 'store'])->name('sources.store');
    Route::get('/sources/{source}/edit', [SourceController::class, 'edit'])->name('sources.edit');
    Route::put('/sources/{source}', [SourceController::class, 'update'])->name('sources.update');

    Route::get('/phonenumbers', [PhonenumberController::class, 'index'])->name('phonenumbers.index');
    Route::get('/phonenumbers/create', [PhonenumberController::class, 'create'])->name('phonenumbers.create');
    Route::post('/phonenumbers', [PhonenumberController::class, 'store'])->name('phonenumbers.store');
    Route::get('/phonenumbers/{phonenumber}/edit', [PhonenumberController::class, 'edit'])->name('phonenumbers.edit');
    Route::put('/phonenumbers/{phonenumber}', [PhonenumberController::class, 'update'])->name('phonenumbers.update');

    Route::get('/blacklists', [BlacklistController::class, 'index'])->name('blacklists.index');
    Route::get('/blacklists/create', [BlacklistController::class, 'create'])->name('blacklists.create');
    Route::post('/blacklists', [BlacklistController::class, 'store'])->name('blacklists.store');
    Route::get('/blacklists/{blacklist}/edit', [BlacklistController::class, 'edit'])->name('blacklists.edit');
    Route::put('/blacklists/{blacklist}', [BlacklistController::class, 'update'])->name('blacklists.update');

    Route::get('/assignment-history', [AssignmentHistoryController::class, 'index'])->name('assignment-history.index');

    Route::get('/routing-logs', [RoutingLogController::class, 'index'])->name('routing-logs.index');
    Route::get('/routing-logs/{routingLog}', [RoutingLogController::class, 'show'])->name('routing-logs.show');

    Route::get('/api-tester', [ApiTesterController::class, 'index'])->name('api-tester.index');

    // ── Data endpoints (XHR / JSON) ───────────────────────────────────────────
    // All return JSON for Vue XHR calls. Add your routes here.
    Route::prefix('data')->group(function () {
        Route::get('/stats', [DashboardController::class, 'stats']);
        Route::get('/chart-data', [DashboardController::class, 'chartData']);
        Route::get('/brand-distribution', [DashboardController::class, 'brandDistribution']);
        Route::get('/daily-breakdown', [DashboardController::class, 'dailyBreakdown']);
        Route::get('/hourly-breakdown', [DashboardController::class, 'hourlyBreakdown']);
        Route::get('/dashboard/filter-options', [DashboardController::class, 'filterOptions']);
        Route::get('/calls/stats', [CallController::class, 'stats']);
        Route::get('/calls/filter-options', [CallController::class, 'filterOptions']);
        Route::post('/calls/clear-filter-cache', [CallController::class, 'clearFilterCache']);
        Route::get('/calls', [CallController::class, 'data']);

        Route::get('/providers/stats', [ProviderController::class, 'stats']);
        Route::get('/providers', [ProviderController::class, 'data']);

        Route::get('/companies/stats', [CompanyController::class, 'stats']);
        Route::get('/companies', [CompanyController::class, 'data']);

        Route::get('/sources/stats', [SourceController::class, 'stats']);
        Route::get('/sources', [SourceController::class, 'data']);

        Route::get('/phonenumbers/stats', [PhonenumberController::class, 'stats']);
        Route::get('/phonenumbers/filter-options', [PhonenumberController::class, 'filterOptions']);
        Route::get('/phonenumbers/has-invalid-routing', [PhonenumberController::class, 'hasInvalidRouting']);
        Route::get('/phonenumbers', [PhonenumberController::class, 'data']);
        Route::post('/phonenumbers/bulk-delete', [PhonenumberController::class, 'bulkDelete']);
        Route::post('/phonenumbers/bulk-restore', [PhonenumberController::class, 'bulkRestore']);
        Route::post('/phonenumbers/bulk-update', [PhonenumberController::class, 'bulkUpdate']);
        Route::post('/phonenumbers/bulk-update-source', [PhonenumberController::class, 'bulkUpdateSource']);
        Route::post('/phonenumbers/bulk-assign', [PhonenumberController::class, 'bulkAssign']);
        Route::post('/phonenumbers/bulk-import', [PhonenumberController::class, 'bulkImport']);
        Route::post('/phonenumbers/manual-route', [PhonenumberController::class, 'manualRoute']);
        Route::post('/phonenumbers/cancel-deletion', [PhonenumberController::class, 'cancelDeletion']);

        Route::get('/blacklists/stats', [BlacklistController::class, 'stats']);
        Route::get('/blacklists/filter-options', [BlacklistController::class, 'filterOptions']);
        Route::get('/blacklists', [BlacklistController::class, 'data']);

        Route::get('/assignment-history/stats', [AssignmentHistoryController::class, 'stats']);
        Route::get('/assignment-history/filter-options', [AssignmentHistoryController::class, 'filterOptions']);
        Route::get('/assignment-history', [AssignmentHistoryController::class, 'data']);

        Route::get('/routing-logs/stats', [RoutingLogController::class, 'stats']);
        Route::get('/routing-logs/filter-options', [RoutingLogController::class, 'filterOptions']);
        Route::get('/routing-logs/has-errors', [RoutingLogController::class, 'hasErrors']);
        Route::get('/routing-logs', [RoutingLogController::class, 'data']);

        Route::get('/api-tester/sources', [ApiTesterController::class, 'sources']);
        Route::post('/api-tester/proxy', [ApiTesterController::class, 'proxy']);
    });
});
