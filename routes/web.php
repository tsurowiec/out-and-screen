<?php

use App\Http\Controllers\PushSubscriptionController;
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

Volt::route('trips', 'trips.index')
    ->middleware(['auth', 'verified'])
    ->name('trips');

Route::middleware(['auth'])->group(function () {
    // Device enrolment for "your session has ended" push notifications.
    Route::post('push/subscribe', [PushSubscriptionController::class, 'store']);
    Route::delete('push/subscribe', [PushSubscriptionController::class, 'destroy']);

    Route::redirect('settings', 'settings/profile');

    Volt::route('settings/profile', 'settings.profile')->name('settings.profile');
    Volt::route('settings/password', 'settings.password')->name('settings.password');
    Volt::route('settings/appearance', 'settings.appearance')->name('settings.appearance');
    Volt::route('settings/notifications', 'settings.notifications')->name('settings.notifications');
});

require __DIR__.'/auth.php';
