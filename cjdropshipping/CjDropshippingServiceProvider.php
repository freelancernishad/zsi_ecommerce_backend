<?php

namespace CjDropshipping;

use Illuminate\Support\ServiceProvider;
use CjDropshipping\Commands\SyncCjInventoryCommand;

class CjDropshippingServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Merge module configuration
        $this->mergeConfigFrom(
            __DIR__ . '/Config/cj.php',
            'cj'
        );

        // Bind Services as Singletons
        $this->app->singleton(Services\CjDropshippingService::class, function ($app) {
            return new Services\CjDropshippingService();
        });

        $this->app->singleton(Services\CjImportService::class, function ($app) {
            return new Services\CjImportService($app->make(Services\CjDropshippingService::class));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Load Module Routes
        $this->loadRoutesFrom(__DIR__ . '/Routes/api.php');

        // Load Module Database Migrations
        $this->loadMigrationsFrom(__DIR__ . '/Migrations');

        // Register Console Commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncCjInventoryCommand::class,
            ]);
        }
    }
}
