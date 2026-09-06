<?php

namespace Amazon;

use Illuminate\Support\ServiceProvider;

class AmazonServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Merge module configuration
        $this->mergeConfigFrom(
            __DIR__ . '/Config/amazon.php',
            'amazon'
        );

        // Bind Services as Singletons
        $this->app->singleton(Services\AmazonScraperService::class, function ($app) {
            return new Services\AmazonScraperService();
        });

        $this->app->singleton(Services\AmazonImportService::class, function ($app) {
            return new Services\AmazonImportService($app->make(Services\AmazonScraperService::class));
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
                Commands\ProcessAmazonBulkImportCommand::class,
            ]);
        }
    }
}
