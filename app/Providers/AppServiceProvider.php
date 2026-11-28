<?php

namespace App\Providers;

use App\Events\StripePaymentEvent;
use App\Listeners\CheckStripePaymentStatus;
use App\Models\SiteSetting;
use App\Models\SystemSetting;
use App\Services\TenantDatabaseManager;
use App\Services\TenantSettingsLoader;
use Illuminate\Support\Collection;
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
                || !array_key_exists('logoUrl', $data)
                || !array_key_exists('storeUrl', $data)
                || !array_key_exists('adminUrl', $data);

            if (!$needsBranding) {
                return;
            }

            $branding = self::resolveEmailBranding();

            if ($needsStoreName) {
                $view->with('storeName', $branding['storeName']);
            }
            foreach (['storeAddress', 'supportPhone', 'supportEmail', 'logoUrl', 'storeUrl', 'adminUrl'] as $key) {
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

    private static function normalizeBaseUrl(?string $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }

        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }

        if (!preg_match('#^https?://#i', $trimmed)) {
            $trimmed = 'https://' . ltrim($trimmed, '/');
        }

        return rtrim($trimmed, '/');
    }

    private static function isPublicStorefrontHost(string $host): bool
    {
        $clean = strtolower(trim(explode(':', $host)[0]));
        if ($clean === '' || !str_contains($clean, '.')) {
            return false;
        }

        return $clean !== 'localhost'
            && filter_var($clean, FILTER_VALIDATE_IP) === false
            && !str_ends_with($clean, '.test')
            && !str_ends_with($clean, '.local')
            && !str_starts_with($clean, 'admin.')
            && !str_starts_with($clean, 'api.')
            && $clean !== 'ecommerce-admin-zsi.vercel.app';
    }

    private static function isLocalhostUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        return $host === 'localhost' || $host === '127.0.0.1' || str_ends_with($host, '.test');
    }

    /**
     * Resolve tenant-scoped storefront and admin base URLs from site_settings, system_settings, or tenant domain mapping.
     *
     * @return array{storeUrl: string, adminUrl: string}
     */
    public static function resolveTenantUrls(Collection $settings, ?Collection $systemSettings = null): array
    {
        $systemSettings ??= collect();

        $storeUrl = null;
        $storeCandidates = [
            $systemSettings->get('FRONTEND_URL'),
            $systemSettings->get('STORE_URL'),
            $systemSettings->get('store_url'),
            $settings->get('store_url'),
            $settings->get('canonical_url'),
        ];

        foreach ($storeCandidates as $candidate) {
            if (is_string($candidate)) {
                $normalized = self::normalizeBaseUrl($candidate);
                if ($normalized !== null && !self::isLocalhostUrl($normalized)) {
                    $storeUrl = $normalized;
                    break;
                }
            }
        }

        if ($storeUrl === null) {
            $siteFooter = $settings->get('site_footer');
            if (is_string($siteFooter)) {
                $decoded = json_decode($siteFooter, true);
                if (is_array($decoded)) {
                    $siteFooter = $decoded;
                }
            }
            if (is_array($siteFooter) && !empty($siteFooter['website']) && is_string($siteFooter['website'])) {
                $normalizedFooterUrl = self::normalizeBaseUrl($siteFooter['website']);
                if ($normalizedFooterUrl !== null && !self::isLocalhostUrl($normalizedFooterUrl)) {
                    $storeUrl = $normalizedFooterUrl;
                }
            }
        }

        $currentDb = TenantDatabaseManager::currentDatabase();
        $tenantDomainsMap = TenantDatabaseManager::tenantDomainsMap();

        if ($storeUrl === null) {
            $request = app()->bound('request') ? request() : null;
            $currentDomain = TenantDatabaseManager::currentTenantDomain($request);
            if (is_string($currentDomain) && $currentDomain !== '') {
                $host = strtolower(explode(':', $currentDomain)[0]);
                if (str_starts_with($host, 'admin.')) {
                    $host = substr($host, 6);
                } elseif (str_starts_with($host, 'api.')) {
                    $host = substr($host, 4);
                }
                if (self::isPublicStorefrontHost($host)) {
                    $storeUrl = 'https://' . $host;
                }
            }
        }

        if ($storeUrl === null && $currentDb !== '') {
            $primaryCandidate = null;
            $wwwCandidate = null;
            $vercelCandidate = null;

            foreach ($tenantDomainsMap as $domain => $dbName) {
                if ($dbName !== $currentDb) {
                    continue;
                }
                $host = strtolower(explode(':', $domain)[0]);
                if (!self::isPublicStorefrontHost($host)) {
                    continue;
                }
                if (str_ends_with($host, '.vercel.app')) {
                    $vercelCandidate ??= $host;
                } elseif (str_starts_with($host, 'www.')) {
                    $wwwCandidate ??= $host;
                } else {
                    $primaryCandidate = $host;
                    break;
                }
            }

            $chosenHost = $primaryCandidate ?? $wwwCandidate ?? $vercelCandidate;
            if ($chosenHost !== null) {
                $storeUrl = 'https://' . $chosenHost;
            }
        }

        if ($storeUrl === null) {
            $storeUrl = self::normalizeBaseUrl(config('app.frontend_url'))
                ?: self::normalizeBaseUrl(config('app.url'))
                ?: '';
        }

        $adminUrl = null;
        foreach ([$systemSettings->get('ADMIN_URL'), $systemSettings->get('admin_url'), $settings->get('admin_url')] as $adminCandidate) {
            if (is_string($adminCandidate)) {
                $normalizedAdmin = self::normalizeBaseUrl($adminCandidate);
                if ($normalizedAdmin !== null && !self::isLocalhostUrl($normalizedAdmin)) {
                    $adminUrl = $normalizedAdmin;
                    break;
                }
            }
        }

        if ($adminUrl === null && $storeUrl !== '') {
            $storeHost = parse_url($storeUrl, PHP_URL_HOST);
            if (is_string($storeHost) && $storeHost !== '') {
                $rootHost = str_starts_with(strtolower($storeHost), 'www.')
                    ? substr(strtolower($storeHost), 4)
                    : strtolower($storeHost);
                $adminCandidate = 'admin.' . $rootHost;

                if (isset($tenantDomainsMap[$adminCandidate]) && ($currentDb === '' || $tenantDomainsMap[$adminCandidate] === $currentDb)) {
                    $adminUrl = 'https://' . $adminCandidate;
                } elseif (
                    str_contains($rootHost, '.')
                    && $rootHost !== 'localhost'
                    && filter_var($rootHost, FILTER_VALIDATE_IP) === false
                    && !str_ends_with($rootHost, '.vercel.app')
                ) {
                    $adminUrl = 'https://' . $adminCandidate;
                }
            }
        }

        if ($adminUrl === null && $currentDb !== '') {
            foreach ($tenantDomainsMap as $domain => $dbName) {
                if ($dbName !== $currentDb) {
                    continue;
                }
                $host = strtolower(explode(':', $domain)[0]);
                if (str_starts_with($host, 'admin.')) {
                    $adminUrl = 'https://' . $host;
                    break;
                }
            }
        }

        if ($adminUrl === null) {
            $adminUrl = $storeUrl;
        }

        return [
            'storeUrl' => $storeUrl,
            'adminUrl' => $adminUrl,
        ];
    }

    /**
     * Resolve dynamic tenant store branding for email templates.
     *
     * @return array{storeName: string, storeAddress: string, supportPhone: string, supportEmail: string, logoUrl: ?string, storeUrl: string, adminUrl: string}
     */
    public static function resolveEmailBranding(): array
    {
        $settings = collect();
        $systemSettings = collect();

        try {
            $settings = SiteSetting::whereIn('key', [
                'store_name',
                'site_name',
                'store_address',
                'support_phone',
                'support_email',
                'site_logo',
                'store_url',
                'canonical_url',
                'site_footer',
                'admin_url',
            ])->get()->pluck('value', 'key');

            $systemSettings = SystemSetting::whereIn('key', [
                'FRONTEND_URL',
                'STORE_URL',
                'store_url',
                'ADMIN_URL',
                'admin_url',
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

        $urls = self::resolveTenantUrls($settings, $systemSettings);

        return [
            'storeName' => $storeName,
            'storeAddress' => $stringOrEmpty($settings->get('store_address')),
            'supportPhone' => $stringOrEmpty($settings->get('support_phone')),
            'supportEmail' => $stringOrEmpty($settings->get('support_email')),
            'logoUrl' => $logoUrl,
            'storeUrl' => $urls['storeUrl'],
            'adminUrl' => $urls['adminUrl'],
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
