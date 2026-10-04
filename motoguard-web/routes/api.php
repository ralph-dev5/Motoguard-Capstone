<?php

use App\Http\Controllers\Api\Device\AlertController;
use App\Http\Controllers\Api\Device\CalibrationController;
use App\Http\Controllers\Api\Device\HeartbeatController;
use App\Http\Controllers\Api\Device\LocationController;
use App\Http\Controllers\Api\Device\PairController;
use App\Http\Controllers\Api\Device\PingController;
use Illuminate\Support\Facades\Route;

// Outside the sanctum group on purpose: this route resolves its own token from cache so a
// once-a-second ping never queries the database. Throttled well above the 60/min it should see.
Route::post('v1/device/ping', PingController::class)
    ->middleware('throttle:180,1')
    ->name('api.device.ping');

// No token yet: the device sends the pairing code shown on the dashboard and gets its token back.
// Six characters from a 31-letter alphabet with a 15-minute life; 10 tries a minute keeps guessing hopeless.
Route::post('v1/device/pair', PairController::class)
    ->middleware('throttle:10,1')
    ->name('api.device.pair');

Route::prefix('v1/device')
    ->name('api.device.')
    ->middleware(['auth:sanctum', 'device', 'throttle:120,1'])
    ->group(function () {
        Route::post('heartbeat', HeartbeatController::class)->name('heartbeat');
        Route::post('locations', LocationController::class)->name('locations');
        Route::post('alerts', AlertController::class)->name('alerts');
        Route::post('calibration', CalibrationController::class)->name('calibration');
    });
