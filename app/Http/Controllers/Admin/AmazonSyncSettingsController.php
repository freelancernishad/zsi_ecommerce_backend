<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AmazonSmartSyncService;
use Exception;
use Illuminate\Http\Request;

class AmazonSyncSettingsController extends Controller
{
    protected AmazonSmartSyncService $smartSyncService;

    public function __construct(AmazonSmartSyncService $smartSyncService)
    {
        $this->smartSyncService = $smartSyncService;
    }

    /**
     * Get Amazon Smart Sync settings, usage analytics, tier breakdown, and cron instructions.
     */
    public function status()
    {
        try {
            return response()->json([
                'success' => true,
                'data' => $this->smartSyncService->getSystemStatus(),
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load Amazon Sync status: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Save switchable Amazon Sync settings.
     */
    public function updateSettings(Request $request)
    {
        $validated = $request->validate([
            'enabled' => 'nullable|in:0,1,true,false',
            'sync_provider' => 'nullable|string|in:brightdata_lightweight,paapi,keepa,brightdata_dataset,direct_http',
            'sync_frequency_hours' => 'nullable|integer|min:1|max:720',
            'batch_limit' => 'nullable|integer|min:1|max:500',
            'rate_limit_delay_ms' => 'nullable|integer|min:0|max:10000',

            // Phase 1
            'lightweight_mode' => 'nullable|in:0,1,true,false',
            'sync_variant_stock' => 'nullable|in:0,1,true,false',
            'force_us_location' => 'nullable|in:0,1,true,false',
            'us_zip_code' => 'nullable|string|max:15',
            'published_only' => 'nullable|in:0,1,true,false',
            'smart_retry_enabled' => 'nullable|in:0,1,true,false',
            'max_consecutive_failures' => 'nullable|integer|min:1|max:20',
            'direct_http_first' => 'nullable|in:0,1,true,false',

            // Phase 2
            'tiered_sync_enabled' => 'nullable|in:0,1,true,false',
            'hot_product_days' => 'nullable|integer|min:1|max:90',
            'hot_sync_hours' => 'nullable|integer|min:1|max:720',
            'active_sync_hours' => 'nullable|integer|min:1|max:720',
            'skip_cold_products' => 'nullable|in:0,1,true,false',
            'cold_threshold_days' => 'nullable|integer|min:1|max:365',

            'jit_sync_enabled' => 'nullable|in:0,1,true,false',
            'jit_on_product_view' => 'nullable|in:0,1,true,false',
            'jit_on_add_to_cart' => 'nullable|in:0,1,true,false',
            'jit_on_checkout' => 'nullable|in:0,1,true,false',
            'jit_stale_hours' => 'nullable|integer|min:1|max:168',

            // Phase 3 Credentials
            'paapi_access_key' => 'nullable|string|max:255',
            'paapi_secret_key' => 'nullable|string|max:255',
            'paapi_partner_tag' => 'nullable|string|max:120',
            'paapi_region' => 'nullable|string|max:60',
            'paapi_host' => 'nullable|string|max:120',

            'keepa_api_key' => 'nullable|string|max:255',
            'keepa_domain' => 'nullable|integer|min:1|max:12',

            'brightdata_dataset_id' => 'nullable|string|max:120',

            // Phase 4 Guardrails & Pricing
            'daily_request_cap_enabled' => 'nullable|in:0,1,true,false',
            'daily_request_cap' => 'nullable|integer|min:1|max:100000',
            'monthly_budget_usd' => 'nullable|numeric|min:0|max:10000',
            'estimated_cost_per_1k_requests' => 'nullable|numeric|min:0|max:100',

            'default_markup_percent' => 'nullable|numeric|min:0|max:500',
            'markup_mode' => 'nullable|string|in:product_field_or_preserve,cost_only_keep_price,global_markup',
            'default_stock' => 'nullable|integer|min:1|max:10000',
            'stock_out_action' => 'nullable|string|in:variant_zero_keep_visible,unpublish_product',
            'auto_restock_enabled' => 'nullable|in:0,1,true,false',
            'price_increase_action' => 'nullable|string|in:auto_update_markup,keep_current_price',
            'price_decrease_action' => 'nullable|string|in:keep_higher_margin,update_lower_price',
        ]);

        // Normalize booleans to '1' / '0' for SiteSetting consistency
        $boolKeys = [
            'enabled', 'lightweight_mode', 'sync_variant_stock', 'force_us_location', 'published_only', 'smart_retry_enabled', 'direct_http_first',
            'tiered_sync_enabled', 'skip_cold_products', 'jit_sync_enabled', 'jit_on_product_view',
            'jit_on_add_to_cart', 'jit_on_checkout', 'daily_request_cap_enabled', 'auto_restock_enabled',
        ];
        foreach ($boolKeys as $bk) {
            if (array_key_exists($bk, $validated)) {
                $validated[$bk] = in_array((string) $validated[$bk], ['1', 'true'], true) ? '1' : '0';
            }
        }

        try {
            $updated = $this->smartSyncService->saveConfig($validated);
            return response()->json([
                'success' => true,
                'message' => 'Amazon Sync settings saved successfully!',
                'data' => $updated,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to save settings: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Trigger a manual Amazon Smart Sync batch.
     */
    public function triggerSync(Request $request)
    {
        $limit = $request->input('limit') ? (int) $request->input('limit') : null;
        $tier = (string) $request->input('tier', 'all');
        $force = (bool) $request->input('force', true);

        try {
            $summary = $this->smartSyncService->runSmartSync($limit, $tier, $force);
            return response()->json([
                'success' => true,
                'message' => $summary['message'] ?? "Amazon Smart Sync completed ({$summary['successful']}/{$summary['total_checked']} synced).",
                'data' => $summary,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Manual sync failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Test a sync provider with a sample ASIN.
     */
    public function testProvider(Request $request)
    {
        $provider = (string) $request->input('provider', 'brightdata_lightweight');
        $asin = $request->input('asin');

        try {
            $result = $this->smartSyncService->testProvider($provider, $asin);
            return response()->json([
                'success' => true,
                'message' => "Provider '{$provider}' connected successfully!",
                'data' => $result,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Reset daily or monthly API request usage counters.
     */
    public function resetUsage(Request $request)
    {
        $scope = (string) $request->input('scope', 'daily');
        try {
            $usage = $this->smartSyncService->resetUsage($scope);
            return response()->json([
                'success' => true,
                'message' => 'Usage counter reset successfully.',
                'data' => $usage,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Unpause one or all auto-paused ASINs.
     */
    public function unpauseAsins(Request $request)
    {
        $asin = $request->input('asin');
        try {
            $usage = $this->smartSyncService->unpauseAsins($asin);
            return response()->json([
                'success' => true,
                'message' => $asin ? "ASIN {$asin} unpaused." : 'All paused ASINs cleared.',
                'data' => $usage,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * List all Amazon products with pagination, search & filter for manual sync.
     */
    public function listProducts(Request $request)
    {
        try {
            $data = $this->smartSyncService->listAmazonProducts([
                'search' => $request->query('search', ''),
                'status' => $request->query('status', 'all'),
                'per_page' => $request->query('per_page', 20),
            ]);

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load Amazon products: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get all matching Amazon product IDs for chunked Sync All.
     */
    public function productIds(Request $request)
    {
        try {
            $ids = $this->smartSyncService->getAllAmazonProductIds(
                (string) $request->query('status', 'all'),
                (string) $request->query('search', '')
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'ids' => $ids,
                    'total' => count($ids),
                ],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch Amazon product IDs: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Manually sync specific Amazon product IDs (single, selected, or a chunk of Sync All).
     */
    public function syncProducts(Request $request)
    {
        $validated = $request->validate([
            'product_ids' => 'required|array|min:1|max:50',
            'product_ids.*' => 'integer',
        ]);

        try {
            $summary = $this->smartSyncService->syncSpecificProducts($validated['product_ids']);
            return response()->json([
                'success' => true,
                'message' => "Synced {$summary['successful']}/{$summary['total_checked']} Amazon product(s).",
                'data' => $summary,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Sync failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Save per-product markup override (percentage / fixed / auto).
     */
    public function updateProductMarkup(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|integer',
            'sync_markup_type' => 'nullable|string|in:auto,percentage,fixed',
            'sync_markup_value' => 'nullable|numeric|min:0|max:10000',
        ]);

        try {
            $data = $this->smartSyncService->updateProductMarkup(
                (int) $validated['product_id'],
                $validated['sync_markup_type'] ?? null,
                isset($validated['sync_markup_value']) ? (float) $validated['sync_markup_value'] : null
            );

            return response()->json([
                'success' => true,
                'message' => 'Product sync markup updated.',
                'data' => $data,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update product markup: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Paginated Amazon sync history logs (what changed, when synced, how long ago, store & Amazon links).
     */
    public function syncLogs(Request $request)
    {
        try {
            $data = $this->smartSyncService->listSyncHistory([
                'search' => $request->query('search', ''),
                'status' => $request->query('status', 'all'),
                'changes_only' => $request->query('changes_only', '0'),
                'per_page' => $request->query('per_page', 20),
            ]);

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load sync history: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * On-Demand Just-in-Time (JIT) sync endpoint for storefront product view / add-to-cart / checkout.
     */
    public function jitCheck(Request $request)
    {
        $trigger = (string) $request->input('trigger', 'checkout');
        $productIds = $request->input('product_ids');
        if (!is_array($productIds) && $request->filled('product_id')) {
            $productIds = [(int) $request->input('product_id')];
        }

        if (empty($productIds)) {
            return response()->json([
                'success' => false,
                'message' => 'product_id or product_ids is required.',
            ], 400);
        }

        $results = [];
        foreach (array_slice($productIds, 0, 10) as $pid) {
            $results[] = $this->smartSyncService->jitSyncProduct((int) $pid, $trigger);
        }

        return response()->json([
            'success' => true,
            'data' => $results,
        ]);
    }
}
