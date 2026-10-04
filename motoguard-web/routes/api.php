<?php

use App\Http\Controllers\Api\Device\AlertController;
use App\Http\Controllers\Api\Device\CalibrationController;
use App\Http\Controllers\Api\Device\EnrollController;
use App\Http\Controllers\Api\Device\HeartbeatController;
use App\Http\Controllers\Api\Device\LocationController;
use App\Http\Controllers\Api\Device\PingController;
use Illuminate\Support\Facades\Route;

// Outside the sanctum group on purpose: this route resolves its own token from cache so a
// once-a-second ping never queries the database. Throttled well above the 60/min it should see.
Route::post('v1/device/ping', PingController::class)
    ->middleware('throttle:180,1')
    ->name('api.device.ping');

// No token yet: the board sends its own built-in ID with a proof that it runs genuine firmware, and
// gets its token back once its owner has added that ID on the dashboard. A board that has not been
// added yet asks again every 10 s, so the limit leaves room for that and little else.
Route::post('v1/device/enroll', EnrollController::class)
    ->middleware('throttle:20,1')
    ->name('api.device.enroll');

Route::prefix('v1/device')
    ->name('api.device.')
    ->middleware(['auth:sanctum', 'device', 'throttle:120,1'])
    ->group(function () {
        Route::post('heartbeat', HeartbeatController::class)->name('heartbeat');
        Route::post('locations', LocationController::class)->name('locations');
        Route::post('alerts', AlertController::class)->name('alerts');
        Route::post('calibration', CalibrationController::class)->name('calibration');
    });
