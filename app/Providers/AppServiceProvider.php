<?php

namespace App\Providers;

use App\Events\StripePaymentEvent;
use App\Listeners\CheckStripePaymentStatus;
use App\Models\SiteSetting;
use App\Services\TenantSettingsLoader;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;
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

        View::composer('emails.*', function ($view) {
            $data = $view->getData();
            $needsStoreName = !array_key_exists('storeName', $data) || !is_string($data['storeName']) || trim($data['storeName']) === '';
            $needsBranding = $needsStoreName
                || !array_key_exists('storeAddress', $data)
                || !array_key_exists('supportPhone', $data)
                || !array_key_exists('supportEmail', $data)
                || !array_key_exists('logoUrl', $data);

            if (!$needsBranding) {
                return;
            }

            $branding = self::resolveEmailBranding();

            if ($needsStoreName) {
                $view->with('storeName', $branding['storeName']);
            }
            foreach (['storeAddress', 'supportPhone', 'supportEmail', 'logoUrl'] as $key) {
                if (!array_key_exists($key, $data)) {
                    $view->with($key, $branding[$key]);
                }
            }
        });

        // Register Stripe Event Listener
        Event::listen(
            StripePaymentEvent::class,
            [CheckStripePaymentStatus::class, 'handle']
        );
    }

    /**
     * Resolve dynamic tenant store branding for email templates.
     *
     * @return array{storeName: string, storeAddress: string, supportPhone: string, supportEmail: string, logoUrl: ?string}
     */
    public static function resolveEmailBranding(): array
    {
        $settings = collect();

        try {
            $settings = SiteSetting::whereIn('key', [
                'store_name',
                'site_name',
                'store_address',
                'support_phone',
                'support_email',
                'site_logo',
            ])->get()->pluck('value', 'key');
        } catch (\Throwable $e) {
            // Fall back cleanly when database is unavailable (e.g. during bootstrap/CLI).
        }

        $stringOrEmpty = static function ($value): string {
            return is_string($value) ? trim($value) : '';
        };

        $storeName = $stringOrEmpty($settings->get('store_name'));
        if ($storeName === '') {
            $storeName = $stringOrEmpty($settings->get('site_name'));
        }
        if ($storeName === '') {
            $storeName = 'Online Store';
        }

        $siteLogo = $stringOrEmpty($settings->get('site_logo'));
        $logoUrl = null;
        if ($siteLogo !== '') {
            $logoUrl = preg_match('#^https?://#i', $siteLogo) ? $siteLogo : url($siteLogo);
        }

        return [
            'storeName' => $storeName,
            'storeAddress' => $stringOrEmpty($settings->get('store_address')),
            'supportPhone' => $stringOrEmpty($settings->get('support_phone')),
            'supportEmail' => $stringOrEmpty($settings->get('support_email')),
            'logoUrl' => $logoUrl,
        ];
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
