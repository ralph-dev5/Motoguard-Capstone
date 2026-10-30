<?php

use App\Support\DevicePin;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('device:pin {serial}', function (string $serial) {
    $pin = DevicePin::for(strtoupper($serial));
    $pin === null
        ? $this->error('DEVICE_ENROLL_SECRET is not set.')
        : $this->info(strtoupper($serial).' PIN: '.$pin);
})->purpose('Show the PIN that connects the website to a unit (for its sticker)');

Schedule::command('devices:mark-offline')->everyMinute();
Schedule::command('location-logs:prune')->daily();
