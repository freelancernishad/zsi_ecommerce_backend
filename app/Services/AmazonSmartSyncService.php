<?php

namespace App\Services;

use App\Models\InventorySyncLog;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\SiteSetting;
use Carbon\Carbon;
use DOMDocument;
use DOMXPath;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Dedicated, cost-optimized Amazon Sync Engine.
 * Completely independent from legacy AmazonImportService / AmazonScraperService / InventorySyncService.
 */
class AmazonSmartSyncService
{
    public const SETTINGS_GROUP = 'amazon_sync';
    public const USAGE_GROUP = 'amazon_sync_usage';

    /**
     * Load all switchable Amazon Smart Sync settings with sensible cost-saving defaults.
     */
    public function getConfig(): array
    {
        $settings = SiteSetting::where('group', self::SETTINGS_GROUP)->pluck('value', 'key')->toArray();

        $val = function (string $key, $default) use ($settings) {
            $v = $settings[$key] ?? $default;
            if (is_array($v)) {
                return $v[0] ?? $default;
            }
            return $v;
        };

        $bool = fn(string $key, bool $default) => in_array((string) $val($key, $default ? '1' : '0'), ['1', 'true'], true);

        return [
            // Master & Independent Schedule
            'enabled' => $bool('enabled', false),
            'sync_provider' => (string) $val('sync_provider', 'brightdata_lightweight'), // brightdata_lightweight | paapi | keepa | brightdata_dataset | direct_http
            'sync_frequency_hours' => max(1, (int) $val('sync_frequency_hours', 24)),
            'batch_limit' => max(1, (int) $val('batch_limit', 50)),
            'rate_limit_delay_ms' => max(0, (int) $val('rate_limit_delay_ms', 800)),

            // Phase 1: Code Optimization Switches
            'lightweight_mode' => $bool('lightweight_mode', true),
            'sync_variant_stock' => $bool('sync_variant_stock', true),
            'force_us_location' => $bool('force_us_location', true),
            'us_zip_code' => (string) $val('us_zip_code', '10001'),
            'published_only' => $bool('published_only', true),
            'smart_retry_enabled' => $bool('smart_retry_enabled', true),
            'max_consecutive_failures' => max(1, (int) $val('max_consecutive_failures', 3)),
            'direct_http_first' => $bool('direct_http_first', false),

            // Phase 2: Tiered & JIT (On-Demand) Sync Switches
            'tiered_sync_enabled' => $bool('tiered_sync_enabled', true),
            'hot_product_days' => max(1, (int) $val('hot_product_days', 7)),
            'hot_sync_hours' => max(1, (int) $val('hot_sync_hours', 24)),
            'active_sync_hours' => max(1, (int) $val('active_sync_hours', 72)),
            'skip_cold_products' => $bool('skip_cold_products', true),
            'cold_threshold_days' => max(1, (int) $val('cold_threshold_days', 30)),

            'jit_sync_enabled' => $bool('jit_sync_enabled', true),
            'jit_on_product_view' => $bool('jit_on_product_view', false),
            'jit_on_add_to_cart' => $bool('jit_on_add_to_cart', true),
            'jit_on_checkout' => $bool('jit_on_checkout', true),
            'jit_stale_hours' => max(1, (int) $val('jit_stale_hours', 12)),

            // Phase 3: Provider Credentials
            'paapi_access_key' => (string) $val('paapi_access_key', ''),
            'paapi_secret_key' => (string) $val('paapi_secret_key', ''),
            'paapi_partner_tag' => (string) $val('paapi_partner_tag', ''),
            'paapi_region' => (string) $val('paapi_region', 'us-east-1'),
            'paapi_host' => (string) $val('paapi_host', 'webservices.amazon.com'),

            'keepa_api_key' => (string) $val('keepa_api_key', ''),
            'keepa_domain' => (int) $val('keepa_domain', 1), // 1 = amazon.com

            'brightdata_dataset_id' => (string) $val('brightdata_dataset_id', 'gd_l7q7dkf244hwjntr0'),

            // Phase 4: Cost Guardrails & Pricing Rules
            'daily_request_cap_enabled' => $bool('daily_request_cap_enabled', true),
            'daily_request_cap' => max(1, (int) $val('daily_request_cap', 200)),
            'monthly_budget_usd' => max(0.0, (float) $val('monthly_budget_usd', 15.0)),
            'estimated_cost_per_1k_requests' => max(0.0, (float) $val('estimated_cost_per_1k_requests', 1.50)),

            'default_markup_percent' => max(0.0, (float) $val('default_markup_percent', 20.0)),
            'markup_mode' => (string) $val('markup_mode', 'product_field_or_preserve'), // product_field_or_preserve | cost_only_keep_price | global_markup
            'default_stock' => max(1, (int) $val('default_stock', 20)),
            'stock_out_action' => (string) $val('stock_out_action', 'variant_zero_keep_visible'),
            'auto_restock_enabled' => $bool('auto_restock_enabled', true),
            'price_increase_action' => (string) $val('price_increase_action', 'auto_update_markup'),
            'price_decrease_action' => (string) $val('price_decrease_action', 'keep_higher_margin'),

            // Runtime Metadata
            'last_sync_at' => (string) $val('last_sync_at', ''),
            'last_sync_summary' => $settings['last_sync_summary'] ?? null,
        ];
    }

    /**
     * Save updated Amazon Smart Sync settings.
     */
    public function saveConfig(array $newSettings): array
    {
        foreach ($newSettings as $key => $value) {
            if ($value === null) {
                continue;
            }
            SiteSetting::setValue($key, $value, self::SETTINGS_GROUP);
        }

        return $this->getConfig();
    }

    /**
     * Get daily/monthly API usage stats and paused ASINs.
     */
    public function getUsageStats(?array $config = null): array
    {
        $config = $config ?? $this->getConfig();
        $today = now()->format('Y-m-d');
        $month = now()->format('Y-m');

        $usage = SiteSetting::where('group', self::USAGE_GROUP)->pluck('value', 'key')->toArray();

        $dailyDate = (string) ($usage['amazon_sync_daily_date'] ?? $today);
        $dailyCount = ($dailyDate === $today) ? (int) ($usage['amazon_sync_daily_count'] ?? 0) : 0;

        $monthlyKey = (string) ($usage['amazon_sync_monthly_key'] ?? $month);
        $monthlyCount = ($monthlyKey === $month) ? (int) ($usage['amazon_sync_monthly_count'] ?? 0) : 0;

        $pausedAsins = is_array($usage['amazon_sync_paused_asins'] ?? null)
            ? $usage['amazon_sync_paused_asins']
            : [];

        $costPer1k = $this->resolveEffectiveCostPer1k($config);
        $estDailyCost = round(($dailyCount / 1000) * $costPer1k, 4);
        $estMonthlyCost = round(($monthlyCount / 1000) * $costPer1k, 4);

        return [
            'today' => $today,
            'month' => $month,
            'daily_requests' => $dailyCount,
            'daily_cap' => $config['daily_request_cap'],
            'daily_cap_enabled' => $config['daily_request_cap_enabled'],
            'daily_remaining' => $config['daily_request_cap_enabled']
                ? max(0, $config['daily_request_cap'] - $dailyCount)
                : null,
            'monthly_requests' => $monthlyCount,
            'estimated_daily_cost_usd' => $estDailyCost,
            'estimated_monthly_cost_usd' => $estMonthlyCost,
            'monthly_budget_usd' => $config['monthly_budget_usd'],
            'effective_cost_per_1k' => $costPer1k,
            'paused_asins' => $pausedAsins,
            'paused_count' => count($pausedAsins),
        ];
    }

    protected function resolveEffectiveCostPer1k(array $config): float
    {
        return match ($config['sync_provider']) {
            'paapi', 'direct_http' => 0.0,
            'keepa' => 0.05,
            default => (float) $config['estimated_cost_per_1k_requests'],
        };
    }

    /**
     * Increment daily and monthly API request counters.
     */
    protected function recordApiRequests(int $count = 1): void
    {
        if ($count <= 0) {
            return;
        }

        $today = now()->format('Y-m-d');
        $month = now()->format('Y-m');
        $usage = SiteSetting::where('group', self::USAGE_GROUP)->pluck('value', 'key')->toArray();

        $dailyDate = (string) ($usage['amazon_sync_daily_date'] ?? '');
        $dailyCount = ($dailyDate === $today) ? (int) ($usage['amazon_sync_daily_count'] ?? 0) : 0;

        $monthlyKey = (string) ($usage['amazon_sync_monthly_key'] ?? '');
        $monthlyCount = ($monthlyKey === $month) ? (int) ($usage['amazon_sync_monthly_count'] ?? 0) : 0;

        SiteSetting::setValue('amazon_sync_daily_date', $today, self::USAGE_GROUP);
        SiteSetting::setValue('amazon_sync_daily_count', $dailyCount + $count, self::USAGE_GROUP);
        SiteSetting::setValue('amazon_sync_monthly_key', $month, self::USAGE_GROUP);
        SiteSetting::setValue('amazon_sync_monthly_count', $monthlyCount + $count, self::USAGE_GROUP);
    }

    /**
     * Reset daily and/or monthly usage counters.
     */
    public function resetUsage(string $scope = 'daily'): array
    {
        $today = now()->format('Y-m-d');
        $month = now()->format('Y-m');

        if ($scope === 'daily' || $scope === 'all') {
            SiteSetting::setValue('amazon_sync_daily_date', $today, self::USAGE_GROUP);
            SiteSetting::setValue('amazon_sync_daily_count', 0, self::USAGE_GROUP);
        }
        if ($scope === 'monthly' || $scope === 'all') {
            SiteSetting::setValue('amazon_sync_monthly_key', $month, self::USAGE_GROUP);
            SiteSetting::setValue('amazon_sync_monthly_count', 0, self::USAGE_GROUP);
        }

        return $this->getUsageStats();
    }

    /**
     * Check if daily request cap has been reached.
     */
    public function isDailyCapReached(?array $config = null): bool
    {
        $config = $config ?? $this->getConfig();
        if (!$config['daily_request_cap_enabled']) {
            return false;
        }
        $usage = $this->getUsageStats($config);
        return $usage['daily_requests'] >= $config['daily_request_cap'];
    }

    /**
     * Record consecutive failure for an ASIN and auto-pause if threshold reached.
     */
    protected function recordAsinFailure(string $asin, string $reason, array $config): void
    {
        if (!$config['smart_retry_enabled']) {
            return;
        }

        $failureMap = SiteSetting::getValue('amazon_sync_failure_counts', []);
        if (!is_array($failureMap)) {
            $failureMap = [];
        }

        $count = ((int) ($failureMap[$asin] ?? 0)) + 1;
        $failureMap[$asin] = $count;
        SiteSetting::setValue('amazon_sync_failure_counts', $failureMap, self::USAGE_GROUP);

        if ($count >= $config['max_consecutive_failures']) {
            $paused = SiteSetting::getValue('amazon_sync_paused_asins', []);
            if (!is_array($paused)) {
                $paused = [];
            }
            $paused[$asin] = [
                'asin' => $asin,
                'failures' => $count,
                'reason' => substr($reason, 0, 200),
                'paused_at' => now()->toDateTimeString(),
            ];
            SiteSetting::setValue('amazon_sync_paused_asins', $paused, self::USAGE_GROUP);
        }
    }

    /**
     * Clear consecutive failure count for an ASIN on success.
     */
    protected function clearAsinFailure(string $asin): void
    {
        $failureMap = SiteSetting::getValue('amazon_sync_failure_counts', []);
        if (is_array($failureMap) && isset($failureMap[$asin])) {
            unset($failureMap[$asin]);
            SiteSetting::setValue('amazon_sync_failure_counts', $failureMap, self::USAGE_GROUP);
        }
    }

    /**
     * Unpause one or all paused ASINs.
     */
    public function unpauseAsins(?string $asin = null): array
    {
        $paused = SiteSetting::getValue('amazon_sync_paused_asins', []);
        $failures = SiteSetting::getValue('amazon_sync_failure_counts', []);
        if (!is_array($paused)) {
            $paused = [];
        }
        if (!is_array($failures)) {
            $failures = [];
        }

        if ($asin) {
            unset($paused[$asin], $failures[$asin]);
        } else {
            $paused = [];
            $failures = [];
        }

        SiteSetting::setValue('amazon_sync_paused_asins', $paused, self::USAGE_GROUP);
        SiteSetting::setValue('amazon_sync_failure_counts', $failures, self::USAGE_GROUP);

        return $this->getUsageStats();
    }

    /**
     * Dashboard status payload for Admin UI.
     */
    public function getSystemStatus(): array
    {
        $config = $this->getConfig();
        $usage = $this->getUsageStats($config);
        $tiers = $this->getTierBreakdown($config);

        $brightDataSettings = SiteSetting::where('group', 'amazon')->pluck('value', 'key')->toArray();
        $hasBrightData = !empty($brightDataSettings['amazon_brightdata_api_key']) && !empty($brightDataSettings['amazon_brightdata_zone']);

        $recentLogs = InventorySyncLog::where('supplier', 'amazon')
            ->latest()
            ->take(20)
            ->get();

        return [
            'config' => $config,
            'usage' => $usage,
            'tiers' => $tiers,
            'provider_readiness' => [
                'brightdata_lightweight' => $hasBrightData,
                'brightdata_dataset' => !empty($brightDataSettings['amazon_brightdata_api_key']) && !empty($config['brightdata_dataset_id']),
                'paapi' => !empty($config['paapi_access_key']) && !empty($config['paapi_secret_key']) && !empty($config['paapi_partner_tag']),
                'keepa' => !empty($config['keepa_api_key']),
                'direct_http' => true,
            ],
            'recent_logs' => $recentLogs,
            'server_environment' => [
                'php_binary' => PHP_BINARY,
                'base_path' => base_path(),
                'cron_command' => '0 * * * * cd ' . base_path() . ' && ' . PHP_BINARY . ' artisan amazon:smart-sync >> /dev/null 2>&1',
                'cpanel_cron_command' => PHP_BINARY . ' ' . base_path('artisan') . ' amazon:smart-sync >> /dev/null 2>&1',
                'laravel_scheduler_command' => '* * * * * cd ' . base_path() . ' && ' . PHP_BINARY . ' artisan schedule:run >> /dev/null 2>&1',
                'artisan_command' => 'php artisan amazon:smart-sync',
            ],
        ];
    }

    /**
     * Calculate Hot / Active / Cold / Draft counts for Amazon products.
     */
    public function getTierBreakdown(array $config): array
    {
        $base = Product::where('is_amazon_product', true)->whereNotNull('amazon_asin');
        $total = (clone $base)->count();
        $published = (clone $base)->where('status', 'published')->count();
        $draft = max(0, $total - $published);

        $hotProductIds = $this->getHotAmazonProductIds($config['hot_product_days']);
        $hotCount = count($hotProductIds);

        $coldCutoff = now()->subDays($config['cold_threshold_days']);
        $coldCount = (clone $base)
            ->where('status', 'published')
            ->whereNotIn('id', $hotProductIds ?: [0])
            ->where('created_at', '<', $coldCutoff)
            ->whereDoesntHave('orderItems', function ($q) use ($coldCutoff) {
                $q->where('created_at', '>=', $coldCutoff);
            })
            ->count();

        $activeCount = max(0, $published - $hotCount - $coldCount);

        return [
            'total_amazon_products' => $total,
            'published' => $published,
            'draft_or_inactive' => $draft,
            'tier1_hot' => $hotCount,
            'tier2_active' => $activeCount,
            'tier3_cold' => $coldCount,
        ];
    }

    protected function getHotAmazonProductIds(int $days): array
    {
        $since = now()->subDays($days);

        return OrderItem::query()
            ->where('created_at', '>=', $since)
            ->whereHas('product', function ($q) {
                $q->where('is_amazon_product', true)
                  ->whereNotNull('amazon_asin')
                  ->where('status', 'published');
            })
            ->distinct()
            ->pluck('product_id')
            ->map(fn($id) => (int) $id)
            ->toArray();
    }

    /**
     * Select eligible Amazon products based on Phase 1 & Phase 2 switches.
     */
    protected function selectEligibleProducts(array $config, int $limit, string $tier = 'all', bool $ignoreStaleness = false)
    {
        $pausedAsins = array_keys(SiteSetting::getValue('amazon_sync_paused_asins', []) ?: []);

        $query = Product::where('is_amazon_product', true)
            ->whereNotNull('amazon_asin')
            ->with('variants');

        if ($config['published_only']) {
            $query->where('status', 'published');
        }

        if (!empty($pausedAsins) && $config['smart_retry_enabled']) {
            $query->whereNotIn('amazon_asin', $pausedAsins);
        }

        if (!$config['tiered_sync_enabled'] && $tier === 'all') {
            if (!$ignoreStaleness) {
                $cutoff = now()->subHours($config['sync_frequency_hours']);
                $query->where(function ($q) use ($cutoff) {
                    $q->whereNull('amazon_synced_at')->orWhere('amazon_synced_at', '<=', $cutoff);
                });
            }

            return $query->orderBy('amazon_synced_at', 'asc')->take($limit)->get();
        }

        $hotIds = $this->getHotAmazonProductIds($config['hot_product_days']);
        $hotCutoff = now()->subHours($config['hot_sync_hours']);
        $activeCutoff = now()->subHours($config['active_sync_hours']);
        $coldCutoff = now()->subDays($config['cold_threshold_days']);

        if ($tier === 'hot') {
            if (empty($hotIds)) {
                return collect();
            }
            $query->whereIn('id', $hotIds);
            if (!$ignoreStaleness) {
                $query->where(function ($q) use ($hotCutoff) {
                    $q->whereNull('amazon_synced_at')->orWhere('amazon_synced_at', '<=', $hotCutoff);
                });
            }
            return $query->orderBy('amazon_synced_at', 'asc')->take($limit)->get();
        }

        // Priority 1: Hot products due for sync
        $selected = collect();
        if (!empty($hotIds) && ($tier === 'all')) {
            $hotQuery = (clone $query)->whereIn('id', $hotIds);
            if (!$ignoreStaleness) {
                $hotQuery->where(function ($q) use ($hotCutoff) {
                    $q->whereNull('amazon_synced_at')->orWhere('amazon_synced_at', '<=', $hotCutoff);
                });
            }
            $selected = $hotQuery->orderBy('amazon_synced_at', 'asc')->take($limit)->get();
        }

        $remaining = $limit - $selected->count();
        if ($remaining <= 0) {
            return $selected;
        }

        // Priority 2: Active products due for sync (excluding cold if skip_cold_products is ON)
        $activeQuery = (clone $query);
        if (!empty($hotIds)) {
            $activeQuery->whereNotIn('id', $hotIds);
        }
        if ($config['skip_cold_products']) {
            $activeQuery->where(function ($q) use ($coldCutoff) {
                $q->where('created_at', '>=', $coldCutoff)
                  ->orWhereHas('orderItems', function ($oq) use ($coldCutoff) {
                      $oq->where('created_at', '>=', $coldCutoff);
                  });
            });
        }
        if (!$ignoreStaleness) {
            $activeQuery->where(function ($q) use ($activeCutoff) {
                $q->whereNull('amazon_synced_at')->orWhere('amazon_synced_at', '<=', $activeCutoff);
            });
        }

        $activeProducts = $activeQuery->orderBy('amazon_synced_at', 'asc')->take($remaining)->get();

        return $selected->concat($activeProducts);
    }

    /**
     * Run Smart Sync batch using configured provider and switches.
     */
    public function runSmartSync(?int $limit = null, string $tier = 'all', bool $ignoreStaleness = false): array
    {
        @set_time_limit(900);
        $config = $this->getConfig();
        $batchLimit = $limit ? max(1, $limit) : $config['batch_limit'];

        $summary = [
            'provider' => $config['sync_provider'],
            'tier' => $tier,
            'total_checked' => 0,
            'successful' => 0,
            'failed' => 0,
            'skipped_cap' => 0,
            'api_requests_made' => 0,
            'stock_changes' => 0,
            'price_changes' => 0,
            'out_of_stock_detected' => 0,
            'started_at' => now()->toDateTimeString(),
            'finished_at' => null,
            'message' => null,
            'items' => [],
        ];

        if ($this->isDailyCapReached($config)) {
            $summary['finished_at'] = now()->toDateTimeString();
            $summary['message'] = "Daily request cap ({$config['daily_request_cap']}) reached. Sync skipped to protect budget.";
            return $summary;
        }

        $products = $this->selectEligibleProducts($config, $batchLimit, $tier, $ignoreStaleness);
        if ($products->isEmpty()) {
            $summary['finished_at'] = now()->toDateTimeString();
            $summary['message'] = 'No eligible Amazon products need syncing right now.';
            return $summary;
        }

        $provider = $config['sync_provider'];

        // Batch-capable providers (PA-API 5.0: 10/call, Keepa: 100/call, BrightData Dataset: 25/call)
        if (in_array($provider, ['paapi', 'keepa', 'brightdata_dataset'], true)) {
            $chunkSize = match ($provider) {
                'paapi' => 10,
                'keepa' => 100,
                'brightdata_dataset' => 25,
                default => 10,
            };

            foreach ($products->chunk($chunkSize) as $chunk) {
                if ($this->isDailyCapReached($config)) {
                    $summary['skipped_cap'] += $chunk->count();
                    continue;
                }

                $asins = $chunk->pluck('amazon_asin')->filter()->map(fn($a) => strtoupper((string) $a))->unique()->values()->toArray();
                if ($config['sync_variant_stock']) {
                    $variantAsins = $chunk->flatMap(fn($p) => $p->variants->pluck('amazon_asin'))
                        ->filter()
                        ->map(fn($a) => strtoupper((string) $a))
                        ->unique()
                        ->values()
                        ->toArray();
                    $asins = array_values(array_unique(array_merge($asins, $variantAsins)));
                }
                if (empty($asins)) {
                    continue;
                }

                try {
                    $batchData = [];
                    foreach (array_chunk($asins, $chunkSize) as $asinSubChunk) {
                        $subRes = match ($provider) {
                            'paapi' => $this->fetchBatchViaPaApi($asinSubChunk, $config),
                            'keepa' => $this->fetchBatchViaKeepa($asinSubChunk, $config),
                            'brightdata_dataset' => $this->fetchBatchViaBrightDataDataset($asinSubChunk, $config),
                        };
                        $batchData = array_merge($batchData, $subRes);
                        $this->recordApiRequests(1);
                        $summary['api_requests_made'] += 1;
                    }

                    foreach ($chunk as $product) {
                        $asin = strtoupper((string) $product->amazon_asin);
                        $itemData = $batchData[$asin] ?? null;

                        if ($itemData && $config['sync_variant_stock'] && $product->variants->isNotEmpty()) {
                            $variantMap = [];
                            foreach ($product->variants as $v) {
                                $vAsin = strtoupper((string) ($v->amazon_asin ?: $asin));
                                if (isset($batchData[$vAsin])) {
                                    $variantMap[$vAsin] = $batchData[$vAsin];
                                }
                            }
                            $itemData['variants'] = $variantMap;
                        }

                        if (!$itemData) {
                            $err = "No data returned by {$provider} for ASIN {$asin}";
                            $this->logSyncError($product, $err, $provider);
                            $this->recordAsinFailure($asin, $err, $config);
                            $summary['total_checked']++;
                            $summary['failed']++;
                            $summary['items'][] = ['product_id' => $product->id, 'asin' => $asin, 'status' => 'failed', 'error' => $err];
                            continue;
                        }

                        $res = $this->applyProductUpdate($product, $itemData, $config, $provider);
                        $this->clearAsinFailure($asin);
                        $this->accumulate($summary, $res);
                    }
                } catch (Exception $e) {
                    $this->recordApiRequests(1);
                    $summary['api_requests_made'] += 1;
                    foreach ($chunk as $product) {
                        $this->logSyncError($product, $e->getMessage(), $provider);
                        $summary['total_checked']++;
                        $summary['failed']++;
                        $summary['items'][] = [
                            'product_id' => $product->id,
                            'asin' => $product->amazon_asin,
                            'status' => 'failed',
                            'error' => $e->getMessage(),
                        ];
                    }
                }

                if ($config['rate_limit_delay_ms'] > 0) {
                    usleep($config['rate_limit_delay_ms'] * 1000);
                }
            }
        } else {
            // Single-ASIN providers: brightdata_lightweight | direct_http
            foreach ($products as $idx => $product) {
                if ($this->isDailyCapReached($config)) {
                    $summary['skipped_cap']++;
                    continue;
                }

                if ($idx > 0 && $config['rate_limit_delay_ms'] > 0) {
                    usleep($config['rate_limit_delay_ms'] * 1000);
                }

                $res = $this->syncSingleProductWithProvider($product, $config);
                $summary['api_requests_made'] += (int) ($res['api_requests_made'] ?? 0);
                $this->accumulate($summary, $res);
            }
        }

        $summary['finished_at'] = now()->toDateTimeString();
        SiteSetting::setValue('last_sync_at', $summary['finished_at'], self::SETTINGS_GROUP);
        SiteSetting::setValue('last_sync_summary', [
            'provider' => $summary['provider'],
            'total_checked' => $summary['total_checked'],
            'successful' => $summary['successful'],
            'failed' => $summary['failed'],
            'api_requests_made' => $summary['api_requests_made'],
            'stock_changes' => $summary['stock_changes'],
            'price_changes' => $summary['price_changes'],
            'finished_at' => $summary['finished_at'],
        ], self::SETTINGS_GROUP);

        return $summary;
    }

    /**
     * Phase 2: Just-in-Time (On-Demand) Sync for Product View / Add to Cart / Checkout.
     */
    public function jitSyncProduct(int $productId, string $trigger = 'checkout'): array
    {
        $config = $this->getConfig();

        if (!$config['jit_sync_enabled']) {
            return ['synced' => false, 'reason' => 'jit_disabled'];
        }

        $triggerAllowed = match ($trigger) {
            'view' => $config['jit_on_product_view'],
            'cart' => $config['jit_on_add_to_cart'],
            'checkout' => $config['jit_on_checkout'],
            default => false,
        };

        if (!$triggerAllowed) {
            return ['synced' => false, 'reason' => "jit_trigger_{$trigger}_disabled"];
        }

        $product = Product::with('variants')->find($productId);
        if (!$product || !$product->is_amazon_product || empty($product->amazon_asin)) {
            return ['synced' => false, 'reason' => 'not_amazon_product'];
        }

        // Only call API if last sync is older than jit_stale_hours
        if ($product->amazon_synced_at) {
            $hoursSinceSync = Carbon::parse($product->amazon_synced_at)->diffInHours(now());
            if ($hoursSinceSync < $config['jit_stale_hours']) {
                return [
                    'synced' => false,
                    'fresh' => true,
                    'reason' => 'still_fresh',
                    'product_id' => $product->id,
                    'price' => (float) $product->price,
                    'stock_quantity' => (int) $product->stock_quantity,
                    'amazon_synced_at' => $product->amazon_synced_at,
                ];
            }
        }

        if ($this->isDailyCapReached($config)) {
            return ['synced' => false, 'reason' => 'daily_cap_reached'];
        }

        $result = $this->syncSingleProductWithProvider($product, $config);
        $product->refresh();

        return [
            'synced' => ($result['status'] ?? '') === 'success',
            'trigger' => $trigger,
            'product_id' => $product->id,
            'price' => (float) $product->price,
            'stock_quantity' => (int) $product->stock_quantity,
            'status' => $product->status,
            'changes_detected' => $result['changes_detected'] ?? false,
            'error' => $result['error'] ?? null,
        ];
    }

    /**
     * Sync a single Amazon product using the active provider.
     */
    protected function syncSingleProductWithProvider(Product $product, array $config): array
    {
        $asin = strtoupper(trim((string) $product->amazon_asin));
        $provider = $config['sync_provider'];
        $url = !empty($product->amazon_url) ? (string) $product->amazon_url : "https://www.amazon.com/dp/{$asin}?th=1&psc=1";
        $apiCalls = 0;

        try {
            $data = null;

            if ($provider === 'paapi') {
                $batch = $this->fetchBatchViaPaApi([$asin], $config);
                $apiCalls = 1;
                $data = $batch[$asin] ?? null;
            } elseif ($provider === 'keepa') {
                $batch = $this->fetchBatchViaKeepa([$asin], $config);
                $apiCalls = 1;
                $data = $batch[$asin] ?? null;
            } elseif ($provider === 'brightdata_dataset') {
                $batch = $this->fetchBatchViaBrightDataDataset([$asin], $config);
                $apiCalls = 1;
                $data = $batch[$asin] ?? null;
            } elseif ($provider === 'direct_http') {
                $data = $this->fetchViaDirectHttp($asin, $url, $config);
                $apiCalls = 0; // Free
            } else {
                // brightdata_lightweight (default)
                if ($config['direct_http_first']) {
                    $data = $this->fetchViaDirectHttp($asin, $url, $config);
                }
                if (!$data) {
                    $data = $this->fetchViaBrightDataLightweight($asin, $url, $config);
                    $apiCalls = 1;
                }
            }

            if ($apiCalls > 0) {
                $this->recordApiRequests($apiCalls);
            }

            if (!$data) {
                throw new Exception("Unable to fetch price/stock for ASIN {$asin} via {$provider}.");
            }

            $res = $this->applyProductUpdate($product, $data, $config, $provider);
            $res['api_requests_made'] = $apiCalls;
            $this->clearAsinFailure($asin);
            return $res;
        } catch (Exception $e) {
            if ($apiCalls > 0) {
                $this->recordApiRequests($apiCalls);
            }
            $this->logSyncError($product, $e->getMessage(), $provider);
            $this->recordAsinFailure($asin, $e->getMessage(), $config);

            return [
                'status' => 'failed',
                'product_id' => $product->id,
                'asin' => $asin,
                'name' => $product->name,
                'api_requests_made' => $apiCalls,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Paginated list of Amazon products for manual inspection & sync.
     */
    public function listAmazonProducts(array $filters = []): array
    {
        $perPage = min(100, max(5, (int) ($filters['per_page'] ?? 20)));
        $search = trim((string) ($filters['search'] ?? ''));
        $status = (string) ($filters['status'] ?? 'all');

        $query = Product::where('is_amazon_product', true)
            ->whereNotNull('amazon_asin')
            ->withCount('variants')
            ->with(['images' => function ($q) {
                $q->orderByDesc('is_primary')->orderBy('id');
            }]);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('amazon_asin', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%");
            });
        }

        if ($status === 'published' || $status === 'draft') {
            $query->where('status', $status);
        } elseif ($status === 'out_of_stock') {
            $query->where('stock_quantity', '<=', 0);
        }

        $paginated = $query->orderBy('amazon_synced_at', 'asc')
            ->orderByDesc('id')
            ->paginate($perPage);

        $pausedMap = SiteSetting::getValue('amazon_sync_paused_asins', []);
        if (!is_array($pausedMap)) {
            $pausedMap = [];
        }

        $items = $paginated->getCollection()->map(function (Product $p) use ($pausedMap) {
            $asin = strtoupper((string) $p->amazon_asin);
            $primaryImg = $p->images->first()?->image_path ?? null;

            $inferred = $this->inferExistingMarkup($p);

            return [
                'id' => $p->id,
                'name' => $p->name,
                'slug' => $p->slug,
                'asin' => $asin,
                'sku' => $p->sku,
                'amazon_url' => $p->amazon_url ?: "https://www.amazon.com/dp/{$asin}",
                'image' => $primaryImg,
                'price' => (float) $p->price,
                'cost_per_item' => (float) ($p->cost_per_item ?? 0),
                'sync_markup_type' => $p->sync_markup_type,
                'sync_markup_value' => $p->sync_markup_value !== null ? (float) $p->sync_markup_value : null,
                'effective_markup_type' => $inferred['type'],
                'effective_markup_value' => $inferred['value'],
                'is_markup_inferred' => $inferred['inferred'],
                'stock_quantity' => (int) ($p->stock_quantity ?? 0),
                'variants_count' => (int) ($p->variants_count ?? 0),
                'status' => $p->status,
                'amazon_synced_at' => $p->amazon_synced_at,
                'synced_ago' => $p->amazon_synced_at ? Carbon::parse($p->amazon_synced_at)->diffForHumans() : null,
                'is_paused' => isset($pausedMap[$asin]),
            ];
        })->values();

        return [
            'data' => $items,
            'current_page' => $paginated->currentPage(),
            'last_page' => $paginated->lastPage(),
            'per_page' => $paginated->perPage(),
            'total' => $paginated->total(),
        ];
    }

    /**
     * Paginated Amazon sync history logs with what changed, when synced, how long ago, and store/Amazon links.
     */
    public function listSyncHistory(array $filters = []): array
    {
        $perPage = min(100, max(5, (int) ($filters['per_page'] ?? 20)));
        $search = trim((string) ($filters['search'] ?? ''));
        $status = (string) ($filters['status'] ?? 'all');
        $changesOnly = in_array((string) ($filters['changes_only'] ?? '0'), ['1', 'true'], true);

        $query = InventorySyncLog::where('supplier', 'amazon')
            ->with(['product:id,name,slug,amazon_asin,amazon_url'])
            ->latest('id');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('product_name', 'like', "%{$search}%")
                  ->orWhere('supplier_id', 'like', "%{$search}%");
            });
        }

        if ($status === 'success' || $status === 'error') {
            $query->where('status', $status);
        }

        if ($changesOnly) {
            $query->where('changes_detected', true);
        }

        $paginated = $query->paginate($perPage);

        $items = $paginated->getCollection()->map(function (InventorySyncLog $log) {
            $product = $log->product;
            $asin = strtoupper((string) ($log->supplier_id ?: ($product?->amazon_asin ?? '')));
            $details = is_array($log->details) ? $log->details : [];

            $oldPrice = $log->old_price !== null ? (float) $log->old_price : null;
            $newPrice = $log->new_price !== null ? (float) $log->new_price : null;
            $oldStock = $log->old_stock !== null ? (int) $log->old_stock : null;
            $newStock = $log->new_stock !== null ? (int) $log->new_stock : null;

            $priceChanged = ($oldPrice !== null && $newPrice !== null && abs($oldPrice - $newPrice) > 0.001);
            $stockChanged = ($oldStock !== null && $newStock !== null && $oldStock !== $newStock);
            $statusChanged = !empty($details['old_status']) && !empty($details['new_status']) && $details['old_status'] !== $details['new_status'];

            return [
                'id' => $log->id,
                'product_id' => $log->product_id,
                'product_name' => $log->product_name ?: ($product?->name ?? "Product #{$log->product_id}"),
                'product_slug' => $product?->slug,
                'asin' => $asin,
                'amazon_url' => $product?->amazon_url ?: ($asin ? "https://www.amazon.com/dp/{$asin}" : null),
                'status' => $log->status,
                'changes_detected' => (bool) $log->changes_detected,
                'price_changed' => $priceChanged,
                'stock_changed' => $stockChanged,
                'status_changed' => $statusChanged,
                'old_price' => $oldPrice,
                'new_price' => $newPrice,
                'old_stock' => $oldStock,
                'new_stock' => $newStock,
                'old_status' => $details['old_status'] ?? null,
                'new_status' => $details['new_status'] ?? null,
                'provider' => $details['provider'] ?? 'legacy',
                'supplier_cost' => isset($details['supplier_cost']) ? (float) $details['supplier_cost'] : null,
                'error_message' => $log->error_message,
                'synced_at' => $log->created_at?->toDateTimeString(),
                'synced_ago' => $log->created_at ? $log->created_at->diffForHumans() : null,
            ];
        })->values();

        return [
            'data' => $items,
            'current_page' => $paginated->currentPage(),
            'last_page' => $paginated->lastPage(),
            'per_page' => $paginated->perPage(),
            'total' => $paginated->total(),
        ];
    }

    /**
     * Get all matching Amazon product IDs for chunked "Sync All" from Admin UI.
     */
    public function getAllAmazonProductIds(string $status = 'all', string $search = ''): array
    {
        $query = Product::where('is_amazon_product', true)
            ->whereNotNull('amazon_asin');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('amazon_asin', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%");
            });
        }

        if ($status === 'published' || $status === 'draft') {
            $query->where('status', $status);
        } elseif ($status === 'out_of_stock') {
            $query->where('stock_quantity', '<=', 0);
        }

        return $query->orderBy('amazon_synced_at', 'asc')->pluck('id')->map(fn($id) => (int) $id)->toArray();
    }

    /**
     * Manually sync a specific list of Amazon product IDs using the active provider.
     */
    public function syncSpecificProducts(array $productIds): array
    {
        @set_time_limit(300);
        $config = $this->getConfig();
        $ids = array_values(array_unique(array_map('intval', $productIds)));

        $products = Product::whereIn('id', $ids)
            ->where('is_amazon_product', true)
            ->whereNotNull('amazon_asin')
            ->with('variants')
            ->get();

        $summary = [
            'provider' => $config['sync_provider'],
            'total_checked' => 0,
            'successful' => 0,
            'failed' => 0,
            'api_requests_made' => 0,
            'stock_changes' => 0,
            'price_changes' => 0,
            'out_of_stock_detected' => 0,
            'items' => [],
        ];

        if ($products->isEmpty()) {
            return $summary;
        }

        $provider = $config['sync_provider'];
        if (in_array($provider, ['paapi', 'keepa', 'brightdata_dataset'], true)) {
            $chunkSize = match ($provider) {
                'paapi' => 10,
                'keepa' => 100,
                'brightdata_dataset' => 25,
                default => 10,
            };

            $asins = $products->pluck('amazon_asin')->filter()->map(fn($a) => strtoupper((string) $a))->unique()->values()->toArray();
            if ($config['sync_variant_stock']) {
                $vAsins = $products->flatMap(fn($p) => $p->variants->pluck('amazon_asin'))
                    ->filter()->map(fn($a) => strtoupper((string) $a))->unique()->values()->toArray();
                $asins = array_values(array_unique(array_merge($asins, $vAsins)));
            }

            $batchData = [];
            foreach (array_chunk($asins, $chunkSize) as $subChunk) {
                try {
                    $subRes = match ($provider) {
                        'paapi' => $this->fetchBatchViaPaApi($subChunk, $config),
                        'keepa' => $this->fetchBatchViaKeepa($subChunk, $config),
                        'brightdata_dataset' => $this->fetchBatchViaBrightDataDataset($subChunk, $config),
                    };
                    $batchData = array_merge($batchData, $subRes);
                    $this->recordApiRequests(1);
                    $summary['api_requests_made'] += 1;
                } catch (Exception $e) {
                    $this->recordApiRequests(1);
                    $summary['api_requests_made'] += 1;
                    Log::warning("Manual batch sync chunk failed ({$provider}): " . $e->getMessage());
                }
            }

            foreach ($products as $product) {
                $asin = strtoupper((string) $product->amazon_asin);
                $itemData = $batchData[$asin] ?? null;
                if (!$itemData) {
                    $err = "No data returned by {$provider} for ASIN {$asin}";
                    $this->logSyncError($product, $err, $provider);
                    $summary['total_checked']++;
                    $summary['failed']++;
                    $summary['items'][] = ['product_id' => $product->id, 'asin' => $asin, 'status' => 'failed', 'error' => $err];
                    continue;
                }

                if ($config['sync_variant_stock'] && $product->variants->isNotEmpty()) {
                    $variantMap = [];
                    foreach ($product->variants as $v) {
                        $vAsin = strtoupper((string) ($v->amazon_asin ?: $asin));
                        if (isset($batchData[$vAsin])) {
                            $variantMap[$vAsin] = $batchData[$vAsin];
                        }
                    }
                    $itemData['variants'] = $variantMap;
                }

                $res = $this->applyProductUpdate($product, $itemData, $config, $provider);
                $this->clearAsinFailure($asin);
                $this->accumulate($summary, $res);
            }
        } else {
            foreach ($products as $idx => $product) {
                if ($idx > 0 && $config['rate_limit_delay_ms'] > 0) {
                    usleep($config['rate_limit_delay_ms'] * 1000);
                }
                $res = $this->syncSingleProductWithProvider($product, $config);
                $summary['api_requests_made'] += (int) ($res['api_requests_made'] ?? 0);
                $this->accumulate($summary, $res);
            }
        }

        return $summary;
    }

    /**
     * Test any provider connection with a sample ASIN without modifying database products.
     */
    public function testProvider(string $provider, ?string $testAsin = null): array
    {
        $config = $this->getConfig();
        $config['sync_provider'] = $provider;

        $asin = strtoupper(trim((string) ($testAsin ?: Product::where('is_amazon_product', true)->whereNotNull('amazon_asin')->value('amazon_asin') ?: 'B09G9FPHY6')));
        $url = "https://www.amazon.com/dp/{$asin}?th=1&psc=1";
        $start = microtime(true);

        $data = match ($provider) {
            'paapi' => ($this->fetchBatchViaPaApi([$asin], $config))[$asin] ?? null,
            'keepa' => ($this->fetchBatchViaKeepa([$asin], $config))[$asin] ?? null,
            'brightdata_dataset' => ($this->fetchBatchViaBrightDataDataset([$asin], $config))[$asin] ?? null,
            'direct_http' => $this->fetchViaDirectHttp($asin, $url, $config),
            default => $this->fetchViaBrightDataLightweight($asin, $url, $config),
        };

        $latencyMs = (int) round((microtime(true) - $start) * 1000);

        if (!$data) {
            throw new Exception("Provider '{$provider}' returned no price/stock data for ASIN {$asin}.");
        }

        $variantsMap = is_array($data['variants'] ?? null) ? $data['variants'] : [];
        $variantsInStock = count(array_filter($variantsMap, fn($v) => !empty($v['available'])));
        $variantsOutOfStock = count($variantsMap) - $variantsInStock;

        return [
            'provider' => $provider,
            'asin' => $asin,
            'latency_ms' => $latencyMs,
            'price' => $data['price'],
            'available' => $data['available'],
            'stock' => $data['stock'],
            'variants_detected' => count($variantsMap),
            'variants_in_stock' => $variantsInStock,
            'variants_out_of_stock' => $variantsOutOfStock,
        ];
    }

    // =========================================================================
    // PROVIDER IMPLEMENTATIONS
    // =========================================================================

    /**
     * Provider 1: BrightData Lightweight (EXACTLY 1 request per product, NO 40x variant enrichment, NO double format=json).
     */
    protected function fetchViaBrightDataLightweight(string $asin, string $url, array $config): ?array
    {
        $amazonSettings = SiteSetting::where('group', 'amazon')->pluck('value', 'key')->toArray();
        $apiKey = $amazonSettings['amazon_brightdata_api_key'] ?? config('amazon.brightdata_api_key', '');
        $zone = $amazonSettings['amazon_brightdata_zone'] ?? config('amazon.brightdata_zone', '');

        if (empty($apiKey) || empty($zone)) {
            throw new Exception('BrightData API key or zone is not configured in Amazon Scraper settings.');
        }

        $targetUrl = $this->buildUsAmazonUrl($asin, $url, $config);

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type' => 'application/json',
        ])->timeout(35)->post('https://api.brightdata.com/request', [
            'zone' => $zone,
            'url' => $targetUrl,
            'country' => 'us',
            'format' => 'raw',
        ]);

        if ($response->status() === 404) {
            return ['price' => 0.0, 'available' => false, 'stock' => 0];
        }

        $html = $response->body();
        if (!$response->successful() || empty($html)) {
            throw new Exception("BrightData returned HTTP {$response->status()} for {$asin}");
        }

        if (str_contains($html, 'Page Not Found') || str_contains($html, 'Looking for something?')) {
            return ['price' => 0.0, 'available' => false, 'stock' => 0];
        }

        return $this->parseLightweightHtml($html, $config['default_stock']);
    }

    /**
     * Normalize any Amazon product URL to www.amazon.com (US) when force_us_location is enabled.
     */
    protected function buildUsAmazonUrl(string $asin, ?string $existingUrl, array $config): string
    {
        if (!empty($config['force_us_location'])) {
            return "https://www.amazon.com/dp/{$asin}?th=1&psc=1&language=en_US&currency=USD";
        }
        return !empty($existingUrl) ? $existingUrl : "https://www.amazon.com/dp/{$asin}?th=1&psc=1";
    }

    /**
     * Establish and cache a genuine US Delivery Location (ZIP 10001) Amazon cookie session
     * so Direct HTTP requests always see US Buybox prices & stock even from non-US servers.
     */
    protected function getUsSessionCookieHeader(string $zipCode = '10001', bool $forceRefresh = false): string
    {
        $zip = preg_replace('/[^\d]/', '', $zipCode) ?: '10001';
        $cacheKey = "amazon_smart_sync_us_cookie_{$zip}";

        if (!$forceRefresh) {
            $cached = Cache::get($cacheKey);
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        }

        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';
        $cookies = [
            'i18n-prefs' => 'USD',
            'lc-main' => 'en_US',
        ];

        try {
            // Step 1: Load amazon.com homepage to obtain initial session-id & anti-csrftoken
            $homeRes = Http::withHeaders([
                'User-Agent' => $ua,
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9',
                'Cookie' => 'i18n-prefs=USD; lc-main=en_US',
            ])->timeout(15)->get('https://www.amazon.com/?language=en_US&currency=USD');

            foreach ($homeRes->cookies() as $c) {
                $cookies[$c->getName()] = $c->getValue();
            }

            $csrfToken = '';
            $homeBody = $homeRes->body();
            if (preg_match('/"anti-csrftoken-a2z"\s*:\s*"([^"]+)"/', $homeBody, $m)) {
                $csrfToken = $m[1];
            } elseif (preg_match('/name="anti-csrftoken-a2z"\s+value="([^"]+)"/', $homeBody, $m)) {
                $csrfToken = $m[1];
            }

            // Step 2: Set Amazon delivery location to US ZIP code (e.g. 10001 New York)
            if ($csrfToken !== '') {
                $cookieStr = $this->formatCookieHeader($cookies);
                $addrRes = Http::withHeaders([
                    'User-Agent' => $ua,
                    'Accept' => 'text/html,*/*',
                    'Accept-Language' => 'en-US,en;q=0.9',
                    'Content-Type' => 'application/json',
                    'X-Requested-With' => 'XMLHttpRequest',
                    'anti-csrftoken-a2z' => $csrfToken,
                    'Origin' => 'https://www.amazon.com',
                    'Referer' => 'https://www.amazon.com/',
                    'Cookie' => $cookieStr,
                ])->timeout(15)->post('https://www.amazon.com/portal-migration/hz/glow/address-change?actionSource=glow', [
                    'locationType' => 'LOCATION_INPUT',
                    'zipCode' => $zip,
                    'storeContext' => 'generic',
                    'deviceType' => 'web',
                    'pageType' => 'Gateway',
                    'actionSource' => 'glow',
                ]);

                foreach ($addrRes->cookies() as $c) {
                    $cookies[$c->getName()] = $c->getValue();
                }
            }
        } catch (Exception $e) {
            Log::warning('Failed to initialize US Amazon ZIP session: ' . $e->getMessage());
        }

        $finalCookieHeader = $this->formatCookieHeader($cookies);
        Cache::put($cacheKey, $finalCookieHeader, now()->addHours(6));

        return $finalCookieHeader;
    }

    protected function formatCookieHeader(array $cookies): string
    {
        $parts = [];
        foreach ($cookies as $k => $v) {
            if ($k !== '' && $v !== '') {
                $parts[] = "{$k}={$v}";
            }
        }
        return implode('; ', $parts);
    }

    /**
     * Provider 2: Direct HTTP (100% Free, realistic desktop headers + cached US ZIP 10001 delivery session).
     */
    protected function fetchViaDirectHttp(string $asin, string $url, array $config): ?array
    {
        try {
            $targetUrl = $this->buildUsAmazonUrl($asin, $url, $config);
            $cookieHeader = !empty($config['force_us_location'])
                ? $this->getUsSessionCookieHeader((string) ($config['us_zip_code'] ?? '10001'))
                : 'i18n-prefs=USD; lc-main=en_US';

            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9',
                'Cookie' => $cookieHeader,
            ])->timeout(15)->get($targetUrl);

            if ($response->status() === 404) {
                return ['price' => 0.0, 'available' => false, 'stock' => 0, 'explicitly_out_of_stock' => true];
            }

            $html = $response->body();
            if (!$response->successful() || empty($html) || str_contains($html, 'validateCaptcha') || str_contains($html, 'Robot Check')) {
                return null;
            }

            return $this->parseLightweightHtml($html, $config['default_stock']);
        } catch (Exception $e) {
            if (($config['sync_provider'] ?? '') === 'direct_http' && (str_contains($e->getMessage(), 'Geo-restricted') || str_contains($e->getMessage(), 'Could not verify'))) {
                throw $e;
            }
            return null;
        }
    }

    /**
     * Provider 3: Amazon PA-API 5.0 GetItems (FREE, batches up to 10 ASINs per call with AWS SigV4).
     */
    protected function fetchBatchViaPaApi(array $asins, array $config): array
    {
        $accessKey = trim($config['paapi_access_key']);
        $secretKey = trim($config['paapi_secret_key']);
        $partnerTag = trim($config['paapi_partner_tag']);
        $region = trim($config['paapi_region'] ?: 'us-east-1');
        $host = trim($config['paapi_host'] ?: 'webservices.amazon.com');

        if (empty($accessKey) || empty($secretKey) || empty($partnerTag)) {
            throw new Exception('Amazon PA-API 5.0 credentials (Access Key, Secret Key, Partner Tag) are required.');
        }

        $uriPath = '/paapi5/getitems';
        $service = 'ProductAdvertisingAPI';
        $target = 'com.amazon.paapi5.v1.ProductAdvertisingAPIv1.GetItems';

        $payload = json_encode([
            'ItemIds' => array_slice(array_values(array_map('strtoupper', $asins)), 0, 10),
            'PartnerTag' => $partnerTag,
            'PartnerType' => 'Associates',
            'Resources' => [
                'Offers.Listings.Price',
                'Offers.Listings.Availability.Type',
                'Offers.Listings.Availability.Message',
            ],
        ]);

        $amzDate = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');
        $contentEncoding = 'amz-1.0';
        $contentType = 'application/json; charset=utf-8';

        $canonicalHeaders =
            "content-encoding:{$contentEncoding}\n"
            . "content-type:{$contentType}\n"
            . "host:{$host}\n"
            . "x-amz-date:{$amzDate}\n"
            . "x-amz-target:{$target}\n";
        $signedHeaders = 'content-encoding;content-type;host;x-amz-date;x-amz-target';
        $payloadHash = hash('sha256', $payload);

        $canonicalRequest = "POST\n{$uriPath}\n\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";
        $algorithm = 'AWS4-HMAC-SHA256';
        $credentialScope = "{$dateStamp}/{$region}/{$service}/aws4_request";
        $stringToSign = "{$algorithm}\n{$amzDate}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);

        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $secretKey, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $authHeader = "{$algorithm} Credential={$accessKey}/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

        $response = Http::withHeaders([
            'Content-Encoding' => $contentEncoding,
            'Content-Type' => $contentType,
            'Host' => $host,
            'X-Amz-Date' => $amzDate,
            'X-Amz-Target' => $target,
            'Authorization' => $authHeader,
        ])->timeout(25)->withBody($payload, $contentType)->post("https://{$host}{$uriPath}");

        if (!$response->successful()) {
            throw new Exception("PA-API 5.0 HTTP {$response->status()}: " . substr($response->body(), 0, 250));
        }

        $json = $response->json();
        $results = [];

        foreach ($json['ItemsResult']['Items'] ?? [] as $item) {
            $asin = strtoupper((string) ($item['ASIN'] ?? ''));
            if (!$asin) {
                continue;
            }
            $listing = $item['Offers']['Listings'][0] ?? null;
            $price = (float) ($listing['Price']['Amount'] ?? 0.0);
            $availType = strtolower((string) ($listing['Availability']['Type'] ?? ''));
            $availMsg = strtolower((string) ($listing['Availability']['Message'] ?? ''));

            $available = $price > 0 && ($availType === 'now' || str_contains($availMsg, 'in stock'));
            $stock = $available ? $config['default_stock'] : 0;
            if ($available && preg_match('/only\s+(\d+)\s+left/i', $availMsg, $m)) {
                $stock = max(1, (int) $m[1]);
            }

            $results[$asin] = [
                'price' => $price,
                'available' => $available,
                'stock' => $stock,
            ];
        }

        return $results;
    }

    /**
     * Provider 4: Keepa API (Batches up to 100 ASINs in a single request).
     */
    protected function fetchBatchViaKeepa(array $asins, array $config): array
    {
        $apiKey = trim($config['keepa_api_key']);
        $domain = (int) ($config['keepa_domain'] ?: 1);

        if (empty($apiKey)) {
            throw new Exception('Keepa API Key is required.');
        }

        $response = Http::timeout(30)->get('https://api.keepa.com/product', [
            'key' => $apiKey,
            'domain' => $domain,
            'asin' => implode(',', array_slice($asins, 0, 100)),
            'stats' => 1,
            'buybox' => 1,
        ]);

        if (!$response->successful()) {
            throw new Exception("Keepa API HTTP {$response->status()}: " . substr($response->body(), 0, 250));
        }

        $json = $response->json();
        $results = [];

        foreach ($json['products'] ?? [] as $p) {
            $asin = strtoupper((string) ($p['asin'] ?? ''));
            if (!$asin) {
                continue;
            }

            // Keepa stats.current: index 18 = Buy Box, 0 = Amazon, 1 = New (values in cents, -1 = out of stock)
            $current = $p['stats']['current'] ?? [];
            $rawCents = -1;
            foreach ([18, 0, 1] as $idx) {
                if (isset($current[$idx]) && (int) $current[$idx] > 0) {
                    $rawCents = (int) $current[$idx];
                    break;
                }
            }

            $price = $rawCents > 0 ? round($rawCents / 100, 2) : 0.0;
            $available = $price > 0;

            $results[$asin] = [
                'price' => $price,
                'available' => $available,
                'stock' => $available ? $config['default_stock'] : 0,
            ];
        }

        return $results;
    }

    /**
     * Provider 5: BrightData Web Scraper API (Amazon Products Dataset Batch).
     */
    protected function fetchBatchViaBrightDataDataset(array $asins, array $config): array
    {
        $amazonSettings = SiteSetting::where('group', 'amazon')->pluck('value', 'key')->toArray();
        $apiKey = $amazonSettings['amazon_brightdata_api_key'] ?? config('amazon.brightdata_api_key', '');
        $datasetId = trim($config['brightdata_dataset_id'] ?: 'gd_l7q7dkf244hwjntr0');

        if (empty($apiKey) || empty($datasetId)) {
            throw new Exception('BrightData API Key and Dataset ID are required.');
        }

        $payload = array_map(fn($asin) => [
            'url' => "https://www.amazon.com/dp/{$asin}",
            'zipcode' => (string) ($config['us_zip_code'] ?? '10001'),
        ], $asins);

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type' => 'application/json',
        ])->timeout(60)->post("https://api.brightdata.com/datasets/v3/scrape?dataset_id={$datasetId}&format=json", $payload);

        if (!$response->successful()) {
            throw new Exception("BrightData Dataset API HTTP {$response->status()}: " . substr($response->body(), 0, 250));
        }

        $records = $response->json();
        if (!is_array($records)) {
            return [];
        }

        $results = [];
        foreach ($records as $rec) {
            if (!is_array($rec)) {
                continue;
            }
            $asin = strtoupper((string) ($rec['asin'] ?? ''));
            if (!$asin && !empty($rec['url']) && preg_match('/(B0[0-9A-Z]{8})/i', $rec['url'], $m)) {
                $asin = strtoupper($m[1]);
            }
            if (!$asin) {
                continue;
            }

            $price = (float) ($rec['final_price'] ?? $rec['price'] ?? $rec['initial_price'] ?? 0.0);
            $availText = strtolower((string) ($rec['availability'] ?? ''));
            $available = $price > 0 && !str_contains($availText, 'unavailable') && !str_contains($availText, 'out of stock');

            $results[$asin] = [
                'price' => $price,
                'available' => $available,
                'stock' => $available ? $config['default_stock'] : 0,
            ];
        }

        return $results;
    }

    // =========================================================================
    // LIGHTWEIGHT HTML PARSER & DB UPDATER
    // =========================================================================

    /**
     * Ultra-fast price & availability parser scoped STRICTLY to the main product buybox/corePrice
     * (never accidentally picks prices from Sponsored or Similar Items carousels).
     */
    protected function parseLightweightHtml(string $html, int $defaultStock): array
    {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        $xpath = new DOMXPath($dom);
        libxml_clear_errors();

        // 1. Strictly extract price ONLY from the main product's corePrice / buybox containers
        $price = 0.0;
        $mainPriceNodes = $xpath->query(
            '//div[@id="corePriceDisplay_desktop_feature_div"]//span[contains(@class, "priceToPay")]//span[contains(@class, "a-offscreen")]'
            . ' | //div[@id="corePriceDisplay_desktop_feature_div"]//span[contains(@class, "a-price") and not(contains(@class, "a-text-price"))]//span[contains(@class, "a-offscreen")]'
            . ' | //div[@id="corePrice_feature_div"]//span[contains(@class, "a-price") and not(contains(@class, "a-text-price"))]//span[contains(@class, "a-offscreen")]'
            . ' | //div[@id="apex_desktop"]//span[contains(@class, "priceToPay")]//span[contains(@class, "a-offscreen")]'
            . ' | //div[@id="buybox"]//span[@id="price_inside_buybox"]'
            . ' | //span[@id="priceblock_ourprice"]'
            . ' | //span[@id="priceblock_dealprice"]'
        );

        if ($mainPriceNodes->length > 0) {
            $rawPrice = trim((string) $mainPriceNodes->item(0)->nodeValue);
            if (preg_match('/[\d,]+\.\d{2}/', $rawPrice, $m)) {
                $price = (float) str_replace(',', '', $m[0]);
            }
        }

        // Fallback: check whole + fraction inside corePriceDisplay_desktop_feature_div (some pages omit a-offscreen)
        if ($price <= 0) {
            $wholeNodes = $xpath->query('//div[@id="corePriceDisplay_desktop_feature_div"]//span[contains(@class, "priceToPay")]//span[contains(@class, "a-price-whole")]');
            $fracNodes = $xpath->query('//div[@id="corePriceDisplay_desktop_feature_div"]//span[contains(@class, "priceToPay")]//span[contains(@class, "a-price-fraction")]');
            if ($wholeNodes->length > 0) {
                $whole = preg_replace('/[^\d]/', '', (string) $wholeNodes->item(0)->textContent);
                $frac = $fracNodes->length > 0 ? preg_replace('/[^\d]/', '', (string) $fracNodes->item(0)->textContent) : '00';
                if ($whole !== '') {
                    $price = (float) "{$whole}.{$frac}";
                }
            }
        }

        // 2. Check for Geo-restriction ("cannot be shipped to your selected delivery location")
        $buyboxText = '';
        $buyboxNodes = $xpath->query(
            '//div[@id="outOfStock"] | //div[@id="deliveryBlockMessage"] | //div[@id="mir-layout-DELIVERY_BLOCK"] | //div[@id="rightCol"] | //div[@id="availability"] | //div[@id="availabilityInsideBuyBox_feature_div"]'
        );
        foreach ($buyboxNodes as $node) {
            $buyboxText .= ' ' . trim(preg_replace('/\s+/', ' ', (string) $node->textContent));
        }
        $buyboxLower = strtolower(trim($buyboxText));

        if ($price <= 0 && str_contains($buyboxLower, 'cannot be shipped to your selected delivery location')) {
            throw new Exception(
                'Geo-restricted by Amazon ("cannot be shipped to your selected delivery location"). Your server IP is outside the US—use BrightData US, PA-API 5.0, or Keepa API for accurate US buybox prices.'
            );
        }

        // 3. Availability & Stock detection scoped to availability/outOfStock nodes
        $availNodes = $xpath->query('//div[@id="availability"] | //div[@id="availabilityInsideBuyBox_feature_div"] | //div[@id="outOfStock"]');
        $availText = '';
        foreach ($availNodes as $node) {
            $availText .= ' ' . trim(preg_replace('/\s+/', ' ', $node->textContent));
        }
        $availLower = strtolower(trim($availText));

        $hasBuyButton = $xpath->query('//input[@id="add-to-cart-button"] | //input[@id="buy-now-button"]')->length > 0;
        $hasBuyingOptions = $xpath->query('//a[contains(@id, "buybox-see-all-buying-choices")] | //span[@id="buybox-see-all-buying-choices"]')->length > 0
            || str_contains($buyboxLower, 'see all buying options');

        $explicitlyOutOfStock = false;
        $available = ($price > 0 && $hasBuyButton) || $hasBuyingOptions;
        $stock = $available ? $defaultStock : 0;

        if ($availLower !== '') {
            if (preg_match('/currently unavailable|out of stock|temporarily out of stock|we don\'t know when or if this item will be back/', $availLower)) {
                $explicitlyOutOfStock = true;
                $available = false;
                $stock = 0;
            } elseif (preg_match('/only\s+(\d+)\s+left\s+in\s+stock/', $availLower, $m)) {
                $available = true;
                $stock = max(1, (int) $m[1]);
            } elseif (str_contains($availLower, 'in stock')) {
                $available = true;
                $stock = $defaultStock;
            }
        }

        // Extract per-variant availability & price from the SAME single HTML page (0 extra API calls!)
        $variants = [];

        // Strategy A: twister-sort-filter-data JSON (including "options from $XX.XX" olpMessage!)
        if (preg_match('/<script[^>]*data-a-state=["\'][^>]*twister-sort-filter-data[^>]*["\'][^>]*>(.*?)<\/script>/s', $html, $tm)) {
            $tJson = json_decode(html_entity_decode($tm[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
            if (!empty($tJson['sortedDimValuesForAllDims']) && is_array($tJson['sortedDimValuesForAllDims'])) {
                foreach ($tJson['sortedDimValuesForAllDims'] as $items) {
                    if (!is_array($items)) {
                        continue;
                    }
                    foreach ($items as $item) {
                        $vAsin = strtoupper(trim((string) ($item['defaultAsin'] ?? '')));
                        if (strlen($vAsin) !== 10) {
                            continue;
                        }
                        $state = strtoupper((string) ($item['dimensionValueState'] ?? ''));
                        $isSelected = ($state === 'SELECTED');
                        $vAvail = $isSelected ? $available : ($state !== 'UNAVAILABLE');
                        $vPrice = $isSelected && $price > 0 ? $price : null;
                        foreach ($item['slots'] ?? [] as $slot) {
                            $dd = $slot['displayData'] ?? [];
                            if (!empty($dd['priceWithoutCurrencySymbol'])) {
                                $pVal = (float) str_replace(',', '', $dd['priceWithoutCurrencySymbol']);
                                if ($pVal > 0) {
                                    $vPrice = $pVal;
                                    break;
                                }
                            }
                            if (!empty($dd['olpMessage']) && preg_match('/\$([\d,]+\.\d{2})/', (string) $dd['olpMessage'], $om)) {
                                $pVal = (float) str_replace(',', '', $om[1]);
                                if ($pVal > 0) {
                                    $vPrice = $pVal;
                                    $vAvail = true;
                                    break;
                                }
                            }
                        }
                        $variants[$vAsin] = [
                            'available' => $vAvail,
                            'stock' => $isSelected ? $stock : ($vAvail ? $defaultStock : 0),
                            'price' => $vPrice,
                        ];
                    }
                }
            }
        }

        // Strategy B: DOM swatch <li> nodes with data-asin / data-defaultasin
        $swatchNodes = $xpath->query(
            '//div[contains(@id, "inline-twister-row")]//li[@data-asin or @data-csa-c-item-id]'
            . ' | //div[contains(@id, "variation_")]//li[@data-asin or @data-defaultasin]'
            . ' | //div[@id="twister"]//li[@data-asin or @data-defaultasin]'
        );
        foreach ($swatchNodes as $node) {
            $vAsin = strtoupper(trim((string) (
                $node->getAttribute('data-asin')
                ?: $node->getAttribute('data-csa-c-item-id')
                ?: $node->getAttribute('data-defaultasin')
            )));
            if (strlen($vAsin) !== 10 || isset($variants[$vAsin])) {
                continue;
            }
            $liClass = strtolower((string) $node->getAttribute('class'));
            $vAvail = !str_contains($liClass, 'unavailable');
            $vPrice = null;
            $pNodes = $xpath->query('.//span[contains(@class, "a-price")]/span[contains(@class, "a-offscreen")] | .//span[contains(@class, "a-color-price")]', $node);
            if ($pNodes->length > 0 && preg_match('/[\d,]+\.\d{2}/', $pNodes->item(0)->textContent, $pm)) {
                $vPrice = (float) str_replace(',', '', $pm[0]);
            } elseif (preg_match('/from\s+\$([\d,]+\.\d{2})/i', (string) $node->textContent, $om)) {
                $vPrice = (float) str_replace(',', '', $om[1]);
                $vAvail = true;
            }
            $variants[$vAsin] = [
                'available' => $vAvail,
                'stock' => $vAvail ? $defaultStock : 0,
                'price' => $vPrice,
            ];
        }

        // Safety guard: NEVER falsely zero out stock if Amazon did NOT explicitly say Out of Stock / Unavailable
        $hasAnyAvailableVariant = !empty(array_filter($variants, fn($v) => !empty($v['available'])));
        if (!$explicitlyOutOfStock && !$available && !$hasAnyAvailableVariant && $price <= 0) {
            throw new Exception(
                'Could not verify live Buybox price/stock (possible geo-restriction or anti-bot page) — preserved existing stock & price.'
            );
        }

        return [
            'price' => $price,
            'available' => $available,
            'stock' => $stock,
            'explicitly_out_of_stock' => $explicitlyOutOfStock,
            'variants' => $variants,
        ];
    }

    /**
     * Save per-product markup override (percentage / fixed / null=auto) without touching legacy import code.
     */
    public function updateProductMarkup(int $productId, ?string $markupType, ?float $markupValue): array
    {
        $product = Product::where('id', $productId)->where('is_amazon_product', true)->firstOrFail();

        if (empty($markupType) || $markupType === 'auto') {
            $product->sync_markup_type = null;
            $product->sync_markup_value = null;
        } else {
            $product->sync_markup_type = $markupType; // 'percentage' | 'fixed'
            $product->sync_markup_value = max(0.0, (float) ($markupValue ?? 0));
        }
        $product->save();

        return [
            'id' => $product->id,
            'sync_markup_type' => $product->sync_markup_type,
            'sync_markup_value' => $product->sync_markup_value !== null ? (float) $product->sync_markup_value : null,
        ];
    }

    /**
     * Resolve saved or inferred markup for a product from its existing price & cost_per_item.
     */
    protected function inferExistingMarkup(Product $product, ?float $fallbackPercent = null): array
    {
        if (!empty($product->sync_markup_type) && $product->sync_markup_value !== null) {
            return [
                'type' => (string) $product->sync_markup_type,
                'value' => round((float) $product->sync_markup_value, 2),
                'inferred' => false,
            ];
        }

        $price = (float) ($product->price ?? 0);
        $cost = (float) ($product->cost_per_item ?? 0);

        if ($cost > 0 && $price > $cost) {
            $diff = round($price - $cost, 2);
            $pct = round(($diff / $cost) * 100, 2);

            $isCleanFixed = abs($diff * 2 - round($diff * 2)) < 0.001; // e.g. $5.00, $10.00, $12.50
            $isCleanPct = abs($pct * 2 - round($pct * 2)) < 0.01;      // e.g. 15%, 20%, 25.5%

            if ($isCleanFixed && !$isCleanPct) {
                return ['type' => 'fixed', 'value' => $diff, 'inferred' => true];
            }
            return ['type' => 'percentage', 'value' => $pct, 'inferred' => true];
        }

        $defaultPct = $fallbackPercent ?? (float) ($this->getConfig()['default_markup_percent'] ?? 20.0);
        return ['type' => 'percentage', 'value' => round($defaultPct, 2), 'inferred' => true];
    }

    /**
     * Calculate target retail price respecting per-product markup fields, preserved margin, or cost-only mode.
     */
    protected function calculateTargetRetailPrice(Product $product, float $suppCost, float $oldPrice, array $config): float
    {
        if ($suppCost <= 0) {
            return $oldPrice;
        }

        $mode = (string) ($config['markup_mode'] ?? 'product_field_or_preserve');
        if ($mode === 'cost_only_keep_price') {
            return $oldPrice;
        }

        if ($mode === 'product_field_or_preserve') {
            $resolved = $this->inferExistingMarkup($product, (float) ($config['default_markup_percent'] ?? 20.0));
            if ($resolved['type'] === 'fixed') {
                return round($suppCost + $resolved['value'], 2);
            }
            return round($suppCost * (1 + ($resolved['value'] / 100)), 2);
        }

        // Global markup fallback
        $markup = (float) ($config['default_markup_percent'] ?? 20.0);
        return round($suppCost * (1 + ($markup / 100)), 2);
    }

    /**
     * Apply price & stock updates to the Product and its variants cleanly in a transaction.
     */
    protected function applyProductUpdate(Product $product, array $data, array $config, string $provider): array
    {
        return DB::transaction(function () use ($product, $data, $config, $provider) {
            $oldStock = (int) ($product->stock_quantity ?? 0);
            $oldPrice = (float) ($product->price ?? 0);
            $oldStatus = (string) $product->status;

            $suppCost = (float) ($data['price'] ?? 0.0);
            $newStock = max(0, (int) ($data['stock'] ?? 0));

            $newPrice = $oldPrice;
            $priceChanged = false;
            if ($suppCost > 0 && ($config['markup_mode'] ?? 'product_field_or_preserve') !== 'cost_only_keep_price') {
                $calcPrice = $this->calculateTargetRetailPrice($product, $suppCost, $oldPrice, $config);
                if ($calcPrice > $oldPrice && $config['price_increase_action'] === 'auto_update_markup') {
                    $newPrice = $calcPrice;
                    $priceChanged = true;
                } elseif ($calcPrice < $oldPrice && $config['price_decrease_action'] === 'update_lower_price') {
                    $newPrice = $calcPrice;
                    $priceChanged = true;
                }
            }

            // Variant-level stock & price update (using zero-extra-cost parsed variants or batch variant data)
            $variantDataMap = is_array($data['variants'] ?? null) ? $data['variants'] : [];
            $variantLogs = [];
            $variantTotalStock = 0;
            $hasVariants = $product->variants->isNotEmpty();

            if ($hasVariants) {
                foreach ($product->variants as $variant) {
                    $vAsin = strtoupper(trim((string) ($variant->amazon_asin ?: $product->amazon_asin)));
                    $oldVarStock = (int) ($variant->stock ?? $variant->available_quantity ?? 0);
                    $oldVarPrice = (float) ($variant->price ?? 0);
                    $newVarStock = $oldVarStock;
                    $newVarPrice = $oldVarPrice;

                    if ($config['sync_variant_stock'] && isset($variantDataMap[$vAsin])) {
                        $vInfo = $variantDataMap[$vAsin];
                        $newVarStock = max(0, (int) ($vInfo['stock'] ?? (!empty($vInfo['available']) ? $config['default_stock'] : 0)));
                        $vCost = (float) ($vInfo['price'] ?? 0);
                        if ($vCost > 0 && ($config['markup_mode'] ?? 'product_field_or_preserve') !== 'cost_only_keep_price') {
                            $vCalc = $this->calculateTargetRetailPrice($product, $vCost, $oldVarPrice, $config);
                            if ($vCalc > $oldVarPrice && $config['price_increase_action'] === 'auto_update_markup') {
                                $newVarPrice = $vCalc;
                                $priceChanged = true;
                            } elseif ($vCalc < $oldVarPrice && $config['price_decrease_action'] === 'update_lower_price') {
                                $newVarPrice = $vCalc;
                                $priceChanged = true;
                            }
                        }
                    } elseif ($vAsin === strtoupper((string) $product->amazon_asin) || $product->variants->count() === 1) {
                        $newVarStock = $newStock;
                        if ($priceChanged) {
                            $newVarPrice = $newPrice;
                        }
                    } elseif (!empty($data['explicitly_out_of_stock']) && empty($variantDataMap)) {
                        $newVarStock = 0;
                    }

                    $variant->stock = $newVarStock;
                    $variant->available_quantity = $newVarStock;
                    $variant->price = $newVarPrice;
                    $variant->save();

                    $variantTotalStock += $newVarStock;
                    $variantLogs[] = [
                        'variant_id' => $variant->id,
                        'asin' => $vAsin,
                        'label' => $variant->combination_label,
                        'old_stock' => $oldVarStock,
                        'new_stock' => $newVarStock,
                        'old_price' => $oldVarPrice,
                        'new_price' => $newVarPrice,
                    ];
                }

                // Parent stock reflects combined variant availability when variant sync is active
                if ($config['sync_variant_stock']) {
                    $newStock = $variantTotalStock;
                }
            }

            $stockChanged = ($newStock !== $oldStock);
            $isOutOfStock = $newStock <= 0;

            $newStatus = $oldStatus;
            if ($isOutOfStock) {
                $newStatus = ($config['stock_out_action'] === 'unpublish_product') ? 'draft' : 'published';
            } elseif ($oldStatus === 'draft' && $config['auto_restock_enabled']) {
                $newStatus = 'published';
            }

            $product->stock_quantity = $newStock;
            $product->price = $newPrice;
            if ($suppCost > 0) {
                $product->cost_per_item = $suppCost;
            }
            $product->status = $newStatus;
            $product->amazon_synced_at = now();
            $product->save();

            $changesDetected = $stockChanged || $priceChanged || ($oldStatus !== $newStatus);

            InventorySyncLog::create([
                'product_id' => $product->id,
                'product_name' => $product->name,
                'supplier' => 'amazon',
                'supplier_id' => (string) $product->amazon_asin,
                'status' => 'success',
                'changes_detected' => $changesDetected,
                'old_stock' => $oldStock,
                'new_stock' => $newStock,
                'old_price' => $oldPrice,
                'new_price' => $newPrice,
                'details' => [
                    'engine' => 'amazon_smart_sync',
                    'provider' => $provider,
                    'old_status' => $oldStatus,
                    'new_status' => $newStatus,
                    'supplier_cost' => $suppCost,
                    'variants' => $variantLogs,
                ],
            ]);

            return [
                'status' => 'success',
                'product_id' => $product->id,
                'asin' => $product->amazon_asin,
                'name' => $product->name,
                'changes_detected' => $changesDetected,
                'stock_changed' => $stockChanged,
                'price_changed' => $priceChanged,
                'is_out_of_stock' => $isOutOfStock,
                'old_stock' => $oldStock,
                'new_stock' => $newStock,
                'old_price' => $oldPrice,
                'new_price' => $newPrice,
            ];
        });
    }

    protected function logSyncError(Product $product, string $error, string $provider): void
    {
        InventorySyncLog::create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'supplier' => 'amazon',
            'supplier_id' => (string) $product->amazon_asin,
            'status' => 'error',
            'changes_detected' => false,
            'error_message' => "[SmartSync:{$provider}] " . $error,
        ]);
    }

    protected function accumulate(array &$summary, array $item): void
    {
        $summary['total_checked']++;
        if (($item['status'] ?? '') === 'success') {
            $summary['successful']++;
            if (!empty($item['stock_changed'])) {
                $summary['stock_changes']++;
            }
            if (!empty($item['price_changed'])) {
                $summary['price_changes']++;
            }
            if (!empty($item['is_out_of_stock'])) {
                $summary['out_of_stock_detected']++;
            }
        } else {
            $summary['failed']++;
        }
        $summary['items'][] = $item;
    }
}
