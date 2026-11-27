<?php

namespace App\Providers;

use App\Events\StripePaymentEvent;
use App\Listeners\CheckStripePaymentStatus;
use App\Services\TenantSettingsLoader;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        // Load settings during console/queue boot; HTTP requests load tenant-scoped settings
        // in SwitchTenantDatabase after resolving the tenant database.
        if ($this->app->runningInConsole()) {
            TenantSettingsLoader::applyForCurrentTenant();
        }

        // Register Stripe Event Listener
        Event::listen(
            StripePaymentEvent::class,
            [CheckStripePaymentStatus::class, 'handle']
        );
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        // Register any application services.
    }
}
