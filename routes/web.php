<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::get('/', function () {
    // The splash screen is only for guests; signed-in users go straight to work.
    return auth()->check()
        ? redirect()->route('dashboard')
        : view('welcome');
})->name('home');

Volt::route('dashboard', 'screen-time.dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Volt::route('screen-time', 'screen-time.entries')
    ->middleware(['auth', 'verified'])
    ->name('screen-time');

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Volt::route('settings/profile', 'settings.profile')->name('settings.profile');
    Volt::route('settings/password', 'settings.password')->name('settings.password');
    Volt::route('settings/appearance', 'settings.appearance')->name('settings.appearance');
});

require __DIR__.'/auth.php';
