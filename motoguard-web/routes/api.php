<?php

use App\Http\Controllers\Api\Device\AlertController;
use App\Http\Controllers\Api\Device\HeartbeatController;
use App\Http\Controllers\Api\Device\LocationController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/device')
    ->name('api.device.')
    ->middleware(['auth:sanctum', 'device', 'throttle:120,1'])
    ->group(function () {
        Route::post('heartbeat', HeartbeatController::class)->name('heartbeat');
        Route::post('locations', LocationController::class)->name('locations');
        Route::post('alerts', AlertController::class)->name('alerts');
    });
