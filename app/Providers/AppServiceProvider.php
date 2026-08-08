<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Everyone sees the same screen time; only parents may change it.
        Gate::define('manage-screen-time', fn (User $user) => $user->canManageScreenTime());

        // Trips are shared the same way, and follow the same rule.
        Gate::define('manage-trips', fn (User $user) => $user->canManageTrips());
    }
}
