<?php

use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');
    Route::livewire('devices', 'pages::devices.index')->name('devices.index');
    Route::livewire('devices/{device}', 'pages::devices.show')->name('devices.show');
    Route::livewire('alerts', 'pages::alerts.index')->name('alerts.index');
});

require __DIR__.'/settings.php';
