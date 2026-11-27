<?php

namespace App\Services;

use App\Models\AllowedOrigin;
use App\Models\SystemSetting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

class TenantSettingsLoader
{
    /**
     * Cache key for the current or specified tenant's system_settings.
     */
    public static function settingsCacheKey(?string $database = null): string
    {
        $db = $database ?: TenantDatabaseManager::currentDatabase();
        return 'system_settings:' . ($db !== '' ? $db : 'default');
    }

    /**
     * Cache key for the current or specified tenant's allowed_origins.
     */
    public static function originsCacheKey(?string $database = null): string
    {
        $db = $database ?: TenantDatabaseManager::currentDatabase();
        return 'allowed_origins:' . ($db !== '' ? $db : 'default');
    }

    /**
     * Clear the cached system settings for the current or specified tenant.
     */
    public static function clearSettingsCache(?string $database = null): void
    {
        Cache::forget(self::settingsCacheKey($database));
        Cache::forget('system_settings');
    }

    /**
     * Clear the cached allowed origins for the current or specified tenant.
     */
    public static function clearOriginsCache(?string $database = null): void
    {
        Cache::forget(self::originsCacheKey($database));
        Cache::forget('allowed_origins');
    }

    /**
     * Load and apply system settings and allowed CORS origins for the active tenant database.
     */
    public static function applyForCurrentTenant(): void
    {
        $dbKey = TenantDatabaseManager::currentDatabase() ?: 'default';

        try {
            $settings = Cache::rememberForever(self::settingsCacheKey($dbKey), function () {
                return SystemSetting::all()->pluck('value', 'key');
            });

            foreach ($settings as $key => $value) {
                Config::set($key, $value);
            }

            if ($settings->isNotEmpty()) {
                self::configureMailSettings($settings);
                self::configureStripeSettings($settings);
                self::configureAwsSettings($settings);
                self::configureJwtSettings($settings);
                self::configureTwilioSettings($settings);
            }

            self::configureCorsSettings($dbKey);
        } catch (QueryException $e) {
            Log::error('Error loading system settings: ' . $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('Unexpected error loading system settings: ' . $e->getMessage());
        }
    }

    /**
     * Configure mail settings dynamically.
     */
    protected static function configureMailSettings(Collection $settings): void
    {
        $mailer = $settings->get('MAIL_MAILER', 'smtp');
        Config::set('mail.default', $mailer);

        if ($mailer === 'smtp') {
            Config::set('mail.mailers.smtp.host', $settings->get('MAIL_HOST'));
            Config::set('mail.mailers.smtp.port', $settings->get('MAIL_PORT'));
            Config::set('mail.mailers.smtp.username', $settings->get('MAIL_USERNAME'));
            Config::set('mail.mailers.smtp.password', $settings->get('MAIL_PASSWORD'));
            Config::set('mail.mailers.smtp.encryption', $settings->get('MAIL_ENCRYPTION'));
        }

        Config::set('mail.from.address', $settings->get('MAIL_FROM_ADDRESS'));
        Config::set('mail.from.name', $settings->get('MAIL_FROM_NAME'));

        if (app()->resolved('mail.manager')) {
            try {
                app('mail.manager')->purge();
            } catch (\Throwable $e) {
                Log::warning('Failed to purge mail manager after tenant switch: ' . $e->getMessage());
            }
        }
    }

    /**
     * Configure Stripe settings dynamically.
     */
    protected static function configureStripeSettings(Collection $settings): void
    {
        Config::set('services.stripe.key', $settings->get('STRIPE_KEY'));
        Config::set('services.stripe.secret', $settings->get('STRIPE_SECRET'));
        Config::set('services.stripe.webhook', $settings->get('STRIPE_WEBHOOK_SECRET'));
    }

    /**
     * Configure AWS settings dynamically.
     */
    protected static function configureAwsSettings(Collection $settings): void
    {
        Config::set('filesystems.disks.s3.key', $settings->get('AWS_ACCESS_KEY_ID'));
        Config::set('filesystems.disks.s3.secret', $settings->get('AWS_SECRET_ACCESS_KEY'));
        Config::set('filesystems.disks.s3.region', $settings->get('AWS_DEFAULT_REGION'));
        Config::set('filesystems.disks.s3.bucket', $settings->get('AWS_BUCKET'));
        Config::set('filesystems.disks.s3.use_path_style_endpoint', filter_var($settings->get('AWS_USE_PATH_STYLE_ENDPOINT', false), FILTER_VALIDATE_BOOLEAN));

        if ($settings->has('AWS_FILE_LOAD_BASE')) {
            Config::set('filesystems.disks.s3.url', $settings->get('AWS_FILE_LOAD_BASE'));
        }
    }

    /**
     * Configure JWT settings dynamically.
     */
    protected static function configureJwtSettings(Collection $settings): void
    {
        if ($settings->has('JWT_TTL')) {
            Config::set('jwt.ttl', (int) $settings->get('JWT_TTL'));
        }
        if ($settings->has('JWT_REFRESH_TTL')) {
            Config::set('jwt.refresh_ttl', (int) $settings->get('JWT_REFRESH_TTL'));
        }
        if ($settings->has('JWT_BLACKLIST_ENABLED')) {
            Config::set('jwt.blacklist_enabled', filter_var($settings->get('JWT_BLACKLIST_ENABLED'), FILTER_VALIDATE_BOOLEAN));
        }
    }

    /**
     * Configure Twilio settings dynamically.
     */
    protected static function configureTwilioSettings(Collection $settings): void
    {
        Config::set('services.twilio.sid', $settings->get('TWILIO_SID'));
        Config::set('services.twilio.token', $settings->get('TWILIO_AUTH_TOKEN'));
        Config::set('services.twilio.from', $settings->get('TWILIO_PHONE_NUMBER'));
    }

    /**
     * Configure CORS settings from the active tenant database without leaking origins across tenants.
     */
    protected static function configureCorsSettings(string $dbKey): void
    {
        try {
            $baseOrigins = Config::get('tenants.base_cors_allowed_origins');
            if ($baseOrigins === null) {
                $baseOrigins = Config::get('cors.allowed_origins', []);
                Config::set('tenants.base_cors_allowed_origins', $baseOrigins);
            }

            $origins = Cache::rememberForever(self::originsCacheKey($dbKey), function () {
                return AllowedOrigin::pluck('origin_url')->toArray();
            });

            Config::set('cors.allowed_origins', array_values(array_unique(array_merge($baseOrigins, $origins))));
        } catch (\Throwable $e) {
            Log::error('Error loading allowed origins: ' . $e->getMessage());
        }
    }
}
