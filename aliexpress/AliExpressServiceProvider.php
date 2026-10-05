<?php

namespace AliExpress;

use Illuminate\Support\ServiceProvider;
use AliExpress\Commands\SyncAliExpressInventoryCommand;
use AliExpress\Commands\ProcessAliExpressBulkImportCommand;

class AliExpressServiceProvider extends ServiceProvider
{
    /**
     * Register application services.
     */
    public function register(): void
    {
        // Merge module configuration
        $this->mergeConfigFrom(
            __DIR__ . '/Config/aliexpress.php',
            'aliexpress'
        );

        // Bind Services as Singletons
        $this->app->singleton(Services\AliExpressService::class, function ($app) {
            return new Services\AliExpressService();
        });

        $this->app->singleton(Services\AliExpressImportService::class, function ($app) {
            return new Services\AliExpressImportService($app->make(Services\AliExpressService::class));
        });
    }

    /**
     * Bootstrap application services.
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
                SyncAliExpressInventoryCommand::class,
                ProcessAliExpressBulkImportCommand::class,
            ]);
        }
    }
}
