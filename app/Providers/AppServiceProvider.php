<?php

namespace App\Providers;

use App\Support\Owner;
use App\Support\Settings;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Scoped: one per request, so the stored settings are read once a request and never go stale under Octane.
        $this->app->scoped(Settings::class);
        $this->app->scoped(Owner::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::useBootstrapFive();
    }
}
