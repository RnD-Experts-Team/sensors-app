<?php

use App\Http\Controllers\Api\PublicStoreController;
use Illuminate\Support\Facades\Route;
use App\Http\Middleware\AuthTokenStoreScopeMiddleware;

/*
|--------------------------------------------------------------------------
| Public API Routes
|--------------------------------------------------------------------------
|
| These routes are stateless and have no auth by default.
| A middleware (e.g. API key validation) can be added here later.
|
*/

Route::prefix('stores')->middleware([
    AuthTokenStoreScopeMiddleware::class,
])->group(function () {
    // GET /api/stores/{store_id}/sensors
    Route::get('{store_id}/sensors', [PublicStoreController::class, 'sensors'])
        ->name('api.stores.sensors');

    // ── Public Reports API ────────────────────────────────────────
    // GET  /api/stores/{store_id}/reports?period=daily|weekly|monthly&date=&device_id=&fields=
    // POST /api/stores/{store_id}/reports/snapshot
    // GET  /api/stores/{store_id}/reports/history?from=&to=&per_page=&device_type=
    // GET  /api/stores/{store_id}/reports/alerts?from=&to=
    Route::prefix('{store_id}/reports')->group(function () {
        Route::get('/', [App\Http\Controllers\Api\PublicReportController::class, 'index'])
            ->name('api.stores.reports');
        Route::post('/snapshot', [App\Http\Controllers\Api\PublicReportController::class, 'snapshot'])
            ->name('api.stores.reports.snapshot');
        Route::get('/history', [App\Http\Controllers\Api\PublicReportController::class, 'history'])
            ->name('api.stores.reports.history');
        Route::get('/alerts', [App\Http\Controllers\Api\PublicReportController::class, 'alerts'])
            ->name('api.stores.reports.alerts');
    });
});
