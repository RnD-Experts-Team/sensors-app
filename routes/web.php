<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

Route::get('/', function () {
    return Inertia::render('welcome', [
        'canRegister' => Features::enabled(Features::registration()),
    ]);
})->name('home');

Route::get('dashboard', function () {
    return Inertia::render('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::get('stores', function () {
    return Inertia::render('stores');
})->middleware(['auth', 'verified'])->name('stores');

Route::get('reports', [App\Http\Controllers\ReportController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('reports');

// YoSmart API Routes (scoped per credential)
Route::middleware(['auth', 'verified'])->prefix('api/credentials/{credential}/yosmart')->group(function () {
    Route::get('/devices', [App\Http\Controllers\YoSmartController::class, 'listDevices'])->name('yosmart.devices.list');
    Route::get('/home', [App\Http\Controllers\YoSmartController::class, 'homeInfo'])->name('yosmart.home.info');
    Route::post('/device/state', [App\Http\Controllers\YoSmartController::class, 'deviceState'])->name('yosmart.device.state');
    Route::get('/device/states', [App\Http\Controllers\YoSmartController::class, 'allDeviceStates'])->name('yosmart.device.states.all');
    Route::post('/device/control', [App\Http\Controllers\YoSmartController::class, 'controlDevice'])->name('yosmart.device.control');
    Route::get('/health', [App\Http\Controllers\YoSmartController::class, 'health'])->name('yosmart.health');
});

// Report Routes (auth'd, consumed by the React reports page)
Route::middleware(['auth', 'verified'])->prefix('api/reports')->group(function () {
    Route::post('/snapshot', [App\Http\Controllers\ReportController::class, 'snapshot'])->name('reports.snapshot');
    Route::get('/data', [App\Http\Controllers\ReportController::class, 'data'])->name('reports.data');
    Route::get('/history', [App\Http\Controllers\ReportController::class, 'history'])->name('reports.history');

    // Snapshot schedule management (single global schedule)
    Route::get('/schedules', [App\Http\Controllers\ScheduleController::class, 'index'])->name('reports.schedules.index');
    Route::post('/schedules', [App\Http\Controllers\ScheduleController::class, 'upsert'])->name('reports.schedules.upsert');
    Route::patch('/schedules/toggle', [App\Http\Controllers\ScheduleController::class, 'toggle'])->name('reports.schedules.toggle');
    Route::post('/schedules/run-now', [App\Http\Controllers\ScheduleController::class, 'runNow'])->name('reports.schedules.runNow');
});

// App Settings Routes
Route::middleware(['auth', 'verified'])->prefix('api/settings')->group(function () {
    Route::get('/temperature-unit', [App\Http\Controllers\AppSettingController::class, 'temperatureUnit'])->name('settings.temperature-unit');
    Route::put('/temperature-unit', [App\Http\Controllers\AppSettingController::class, 'updateTemperatureUnit'])->name('settings.temperature-unit.update');
});

// Store Management Routes (admin, behind auth)
Route::middleware(['auth', 'verified'])->prefix('api/stores')->group(function () {
    Route::get('/', [App\Http\Controllers\StoreController::class, 'index'])->name('stores.index');
    Route::post('/', [App\Http\Controllers\StoreController::class, 'store'])->name('stores.store');
    Route::get('/{store}', [App\Http\Controllers\StoreController::class, 'show'])->name('stores.show');
    Route::put('/{store}', [App\Http\Controllers\StoreController::class, 'update'])->name('stores.update');
    Route::delete('/{store}', [App\Http\Controllers\StoreController::class, 'destroy'])->name('stores.destroy');
});

// YoSmart Credential Management Routes (admin, behind auth)
Route::middleware(['auth', 'verified'])->prefix('api/credentials')->group(function () {
    Route::get('/', [App\Http\Controllers\YoSmartCredentialController::class, 'index'])->name('credentials.index');
    Route::post('/', [App\Http\Controllers\YoSmartCredentialController::class, 'store'])->name('credentials.store');
    Route::get('/{credential}', [App\Http\Controllers\YoSmartCredentialController::class, 'show'])->name('credentials.show');
    Route::put('/{credential}', [App\Http\Controllers\YoSmartCredentialController::class, 'update'])->name('credentials.update');
    Route::delete('/{credential}', [App\Http\Controllers\YoSmartCredentialController::class, 'destroy'])->name('credentials.destroy');
    Route::post('/{credential}/sync', [App\Http\Controllers\YoSmartCredentialController::class, 'sync'])->name('credentials.sync');
    Route::put('/{credential}/devices/{device}/assign', [App\Http\Controllers\YoSmartCredentialController::class, 'assignDevice'])->name('credentials.devices.assign');
});

require __DIR__.'/settings.php';
