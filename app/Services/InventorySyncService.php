<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\InventorySyncLog;
use App\Models\SiteSetting;
use AliExpress\Services\AliExpressService;
use CjDropshipping\Services\CjDropshippingService;
use Amazon\Services\AmazonScraperService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Exception;

class InventorySyncService
{
    protected AliExpressService $aliExpressService;
    protected CjDropshippingService $cjService;
    protected AmazonScraperService $amazonService;

    public function __construct(
        AliExpressService $aliExpressService,
        CjDropshippingService $cjService,
        AmazonScraperService $amazonService
    ) {
        $this->aliExpressService = $aliExpressService;
        $this->cjService = $cjService;
        $this->amazonService = $amazonService;
    }

    /**
     * Load inventory sync configuration from database or defaults.
     */
    public function getConfig(): array
    {
        $settings = SiteSetting::where('group', 'inventory_sync')->pluck('value', 'key')->toArray();

        $getVal = function ($key, $default) use ($settings) {
            $val = $settings[$key] ?? $default;
            if (is_array($val)) return $val[0] ?? $default;
            return $val;
        };

        return [
            'auto_sync_enabled' => (string)$getVal('auto_sync_enabled', '1') === '1',
            'sync_frequency_hours' => (int)$getVal('sync_frequency_hours', 6),
            'sync_aliexpress' => (string)$getVal('sync_aliexpress', '1') === '1',
            'sync_cjdropshipping' => (string)$getVal('sync_cjdropshipping', '1') === '1',
            'sync_amazon' => (string)$getVal('sync_amazon', '1') === '1',
            'batch_limit' => (int)$getVal('batch_limit', 50),
            'request_timeout_seconds' => (int)$getVal('request_timeout_seconds', 20),
            'retry_attempts' => (int)$getVal('retry_attempts', 2),
            'rate_limit_delay_ms' => (int)$getVal('rate_limit_delay_ms', 1200),
            'stock_out_action' => (string)$getVal('stock_out_action', 'variant_zero_keep_visible'), // 'variant_zero_keep_visible' | 'unpublish_product'
            'auto_restock_enabled' => (string)$getVal('auto_restock_enabled', '1') === '1',
            'price_increase_action' => (string)$getVal('price_increase_action', 'auto_update_markup'), // 'auto_update_markup' | 'keep_current_price'
            'price_decrease_action' => (string)$getVal('price_decrease_action', 'keep_higher_margin'), // 'keep_higher_margin' | 'update_lower_price'
            'default_markup_percent' => (float)$getVal('default_markup_percent', 20.0),
            'last_sync_at' => (string)$getVal('last_sync_at', ''),
            'last_sync_summary' => $getVal('last_sync_summary', null),
        ];
    }

    /**
     * Save updated inventory sync configuration.
     */
    public function saveConfig(array $newSettings): array
    {
        foreach ($newSettings as $k => $v) {
            SiteSetting::setValue($k, $v, 'inventory_sync');
        }
        return $this->getConfig();
    }

    /**
     * Run full synchronization across selected or all channels.
     */
    public function syncAll(string $channel = 'all', ?int $limit = null, ?callable $onProgress = null): array
    {
        @set_time_limit(1800); // 30 min maximum execution for large batches
        $config = $this->getConfig();
        $batchLimit = $limit ?: $config['batch_limit'];

        $summary = [
            'total_checked' => 0,
            'successful' => 0,
            'failed' => 0,
            'stock_changes' => 0,
            'price_changes' => 0,
            'out_of_stock_detected' => 0,
            'restocked' => 0,
            'channel' => $channel,
            'started_at' => now()->toDateTimeString(),
            'finished_at' => null,
            'logs' => [],
        ];

        // 1. AliExpress Products
        if (($channel === 'all' || $channel === 'aliexpress') && $config['sync_aliexpress']) {
            $aliProducts = Product::where('is_aliexpress_product', true)
                ->whereNotNull('aliexpress_product_id')
                ->with('variants')
                ->orderBy('aliexpress_synced_at', 'asc')
                ->take($batchLimit)
                ->get();

            foreach ($aliProducts as $idx => $prod) {
                if ($idx > 0 && $config['rate_limit_delay_ms'] > 0) {
                    usleep($config['rate_limit_delay_ms'] * 1000);
                }
                $result = $this->syncSingleAliExpressProduct($prod, $config);
                $this->accumulateSummary($summary, $result);
                if (is_callable($onProgress)) {
                    $onProgress($summary);
                }
            }
        }

        // 2. CJ Dropshipping Products
        if (($channel === 'all' || $channel === 'cjdropshipping') && $config['sync_cjdropshipping']) {
            $cjProducts = Product::where('is_cj_product', true)
                ->whereNotNull('cj_product_id')
                ->with('variants')
                ->orderBy('cj_synced_at', 'asc')
                ->take($batchLimit)
                ->get();

            foreach ($cjProducts as $idx => $prod) {
                if ($idx > 0 && $config['rate_limit_delay_ms'] > 0) {
                    usleep($config['rate_limit_delay_ms'] * 1000);
                }
                $result = $this->syncSingleCjProduct($prod, $config);
                $this->accumulateSummary($summary, $result);
                if (is_callable($onProgress)) {
                    $onProgress($summary);
                }
            }
        }

        // 3. Amazon Products
        if (($channel === 'all' || $channel === 'amazon') && $config['sync_amazon']) {
            $amzProducts = Product::where('is_amazon_product', true)
                ->whereNotNull('amazon_asin')
                ->with('variants')
                ->orderBy('amazon_synced_at', 'asc')
                ->take($batchLimit)
                ->get();

            foreach ($amzProducts as $idx => $prod) {
                if ($idx > 0 && $config['rate_limit_delay_ms'] > 0) {
                    usleep($config['rate_limit_delay_ms'] * 1000);
                }
                $result = $this->syncSingleAmazonProduct($prod, $config);
                $this->accumulateSummary($summary, $result);
                if (is_callable($onProgress)) {
                    $onProgress($summary);
                }
            }
        }

        $summary['finished_at'] = now()->toDateTimeString();

        // Update site settings with last sync status
        SiteSetting::setValue('last_sync_at', $summary['finished_at'], 'inventory_sync');
        SiteSetting::setValue('last_sync_summary', $summary, 'inventory_sync');

        return $summary;
    }

    /**
     * Synchronize a single AliExpress product stock and pricing.
     */
    public function syncSingleAliExpressProduct(Product $product, array $config): array
    {
        $supplierId = (string)$product->aliexpress_product_id;
        $maxRetries = max(1, $config['retry_attempts']);
        $rawDetail = null;
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $rawDetail = $this->aliExpressService->getProductDetail($supplierId);
                if (!empty($rawDetail) && isset($rawDetail['productId'])) {
                    break;
                }
            } catch (Exception $e) {
                $lastException = $e;
                if ($attempt < $maxRetries) {
                    usleep(500000); // 0.5s pause before retry
                }
            }
        }

        if (!$rawDetail || empty($rawDetail['productId'])) {
            $errMsg = $lastException ? $lastException->getMessage() : "Could not fetch details from AliExpress for Item ID {$supplierId}";
            InventorySyncLog::create([
                'product_id' => $product->id,
                'product_name' => $product->name,
                'supplier' => 'aliexpress',
                'supplier_id' => $supplierId,
                'status' => 'error',
                'changes_detected' => false,
                'error_message' => "Timeout/Error (Preserved existing stock): " . $errMsg,
            ]);

            return [
                'status' => 'failed',
                'product_id' => $product->id,
                'name' => $product->name,
                'error' => $errMsg,
            ];
        }

        return $this->applyStockAndPriceChanges($product, $rawDetail, 'aliexpress', $supplierId, $config);
    }

    /**
     * Synchronize a single CJ Dropshipping product stock and pricing.
     */
    public function syncSingleCjProduct(Product $product, array $config): array
    {
        $supplierId = (string)$product->cj_product_id;
        $maxRetries = max(1, $config['retry_attempts']);
        $rawDetail = null;
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $rawDetail = $this->cjService->getProductDetail($supplierId);
                if (!empty($rawDetail)) {
                    break;
                }
            } catch (Exception $e) {
                $lastException = $e;
                if ($attempt < $maxRetries) {
                    usleep(600000);
                }
            }
        }

        if (!$rawDetail) {
            $errMsg = $lastException ? $lastException->getMessage() : "CJ Dropshipping API returned empty details for PID {$supplierId}";
            InventorySyncLog::create([
                'product_id' => $product->id,
                'product_name' => $product->name,
                'supplier' => 'cjdropshipping',
                'supplier_id' => $supplierId,
                'status' => 'error',
                'changes_detected' => false,
                'error_message' => "Timeout/Error: " . $errMsg,
            ]);

            return [
                'status' => 'failed',
                'product_id' => $product->id,
                'name' => $product->name,
                'error' => $errMsg,
            ];
        }

        return $this->applyStockAndPriceChanges($product, $rawDetail, 'cjdropshipping', $supplierId, $config);
    }

    /**
     * Synchronize a single Amazon product stock and pricing.
     */
    public function syncSingleAmazonProduct(Product $product, array $config): array
    {
        $supplierId = (string)$product->amazon_asin;
        $maxRetries = max(1, $config['retry_attempts']);
        $rawDetail = null;
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $rawDetail = $this->amazonService->getProductDetail($supplierId);
                if (!empty($rawDetail)) {
                    break;
                }
            } catch (Exception $e) {
                $lastException = $e;
                if ($attempt < $maxRetries) {
                    usleep(1000000);
                }
            }
        }

        if (!$rawDetail) {
            $errMsg = $lastException ? $lastException->getMessage() : "Amazon scraper could not retrieve data for ASIN {$supplierId}";
            InventorySyncLog::create([
                'product_id' => $product->id,
                'product_name' => $product->name,
                'supplier' => 'amazon',
                'supplier_id' => $supplierId,
                'status' => 'error',
                'changes_detected' => false,
                'error_message' => "Timeout/Scraper Error: " . $errMsg,
            ]);

            return [
                'status' => 'failed',
                'product_id' => $product->id,
                'name' => $product->name,
                'error' => $errMsg,
            ];
        }

        return $this->applyStockAndPriceChanges($product, $rawDetail, 'amazon', $supplierId, $config);
    }

    /**
     * Apply variant-level and parent-level stock and price comparisons and updates.
     */
    protected function applyStockAndPriceChanges(Product $product, array $supplierData, string $channel, string $supplierId, array $config): array
    {
        return DB::transaction(function () use ($product, $supplierData, $channel, $supplierId, $config) {
            $oldProductStock = (int)($product->stock_quantity ?? $product->stock ?? 0);
            $oldProductPrice = (float)($product->price ?? 0);
            $oldProductStatus = (string)$product->status;

            $markupPercent = (float)($config['default_markup_percent'] ?? 20.0);
            $variantLogs = [];
            $stockChanged = false;
            $priceChanged = false;
            $totalNewStock = 0;

            $supplierVariants = $supplierData['variants'] ?? [];
            $localVariants = $product->variants()->get();

            // Match variants by supplier SKU / ID or label
            if ($localVariants->isNotEmpty() && !empty($supplierVariants)) {
                foreach ($localVariants as $lVar) {
                    $matchedSuppVar = null;
                    foreach ($supplierVariants as $sVar) {
                        $sSkuId = (string)($sVar['sku_id'] ?? $sVar['vid'] ?? $sVar['id'] ?? '');
                        if (
                            ($lVar->aliexpress_sku_id && $lVar->aliexpress_sku_id === $sSkuId) ||
                            ($lVar->cj_vid && $lVar->cj_vid === $sSkuId) ||
                            ($lVar->sku && $sSkuId && str_ends_with($lVar->sku, $sSkuId)) ||
                            (strtolower(trim($lVar->combination_label)) === strtolower(trim($sVar['name'] ?? '')))
                        ) {
                            $matchedSuppVar = $sVar;
                            break;
                        }
                    }

                    $oldVarStock = (int)($lVar->stock ?? $lVar->available_quantity ?? 0);
                    $oldVarPrice = (float)($lVar->price ?? 0);

                    if ($matchedSuppVar) {
                        $newVarStock = (int)($matchedSuppVar['stock'] ?? 0);
                        $suppCostPrice = (float)($matchedSuppVar['cost_price'] ?? $matchedSuppVar['costPrice'] ?? 0);
                    } else {
                        // Variant was removed by supplier -> 0 stock
                        $newVarStock = 0;
                        $suppCostPrice = 0;
                    }

                    $totalNewStock += $newVarStock;

                    // Calculate updated retail price if cost exists
                    $newVarPrice = $oldVarPrice;
                    if ($suppCostPrice > 0) {
                        $calcPrice = round($suppCostPrice * (1 + ($markupPercent / 100)), 2);
                        if ($calcPrice > $oldVarPrice && $config['price_increase_action'] === 'auto_update_markup') {
                            $newVarPrice = $calcPrice;
                            $priceChanged = true;
                        } elseif ($calcPrice < $oldVarPrice && $config['price_decrease_action'] === 'update_lower_price') {
                            $newVarPrice = $calcPrice;
                            $priceChanged = true;
                        }
                    }

                    if ($newVarStock !== $oldVarStock) {
                        $stockChanged = true;
                    }

                    // Save updated variant record
                    $lVar->stock = $newVarStock;
                    $lVar->available_quantity = $newVarStock;
                    $lVar->price = $newVarPrice;
                    $lVar->save();

                    $variantLogs[] = [
                        'variant_id' => $lVar->id,
                        'label' => $lVar->combination_label,
                        'old_stock' => $oldVarStock,
                        'new_stock' => $newVarStock,
                        'old_price' => $oldVarPrice,
                        'new_price' => $newVarPrice,
                        'is_out_of_stock' => $newVarStock <= 0,
                    ];
                }
            } else {
                // Single/Non-variant product
                $suppStock = (int)($supplierData['stock'] ?? 50);
                $suppCost = (float)($supplierData['costPrice'] ?? $supplierData['cost_price'] ?? 0);

                $totalNewStock = $suppStock;
                if ($suppStock !== $oldProductStock) {
                    $stockChanged = true;
                }

                $newProductPrice = $oldProductPrice;
                if ($suppCost > 0) {
                    $calcPrice = round($suppCost * (1 + ($markupPercent / 100)), 2);
                    if ($calcPrice > $oldProductPrice && $config['price_increase_action'] === 'auto_update_markup') {
                        $newProductPrice = $calcPrice;
                        $priceChanged = true;
                    } elseif ($calcPrice < $oldProductPrice && $config['price_decrease_action'] === 'update_lower_price') {
                        $newProductPrice = $calcPrice;
                        $priceChanged = true;
                    }
                }
            }

            // Status decision (Out of Stock vs Published)
            $newProductStatus = $oldProductStatus;
            $isOutOfStock = $totalNewStock <= 0;

            if ($isOutOfStock) {
                if ($config['stock_out_action'] === 'unpublish_product') {
                    $newProductStatus = 'draft';
                } else {
                    $newProductStatus = 'published'; // Kept published with 0 stock
                }
            } else {
                // If it was previously moved to draft due to stockout and auto_restock is enabled, restore to published
                if ($oldProductStatus === 'draft' && $config['auto_restock_enabled']) {
                    $newProductStatus = 'published';
                }
            }

            // Update Parent Product
            $product->stock_quantity = $totalNewStock;
            if (isset($newProductPrice)) {
                $product->price = $newProductPrice;
            }
            $product->status = $newProductStatus;

            if ($channel === 'aliexpress') {
                $product->aliexpress_synced_at = now();
            } elseif ($channel === 'cjdropshipping') {
                $product->cj_synced_at = now();
            } elseif ($channel === 'amazon') {
                $product->amazon_synced_at = now();
            }

            $product->save();

            $changesDetected = $stockChanged || $priceChanged || ($oldProductStatus !== $newProductStatus);

            // Create Log entry
            InventorySyncLog::create([
                'product_id' => $product->id,
                'product_name' => $product->name,
                'supplier' => $channel,
                'supplier_id' => $supplierId,
                'status' => 'success',
                'changes_detected' => $changesDetected,
                'old_stock' => $oldProductStock,
                'new_stock' => $totalNewStock,
                'old_price' => $oldProductPrice,
                'new_price' => $product->price,
                'details' => [
                    'old_status' => $oldProductStatus,
                    'new_status' => $newProductStatus,
                    'variants' => $variantLogs,
                ],
            ]);

            return [
                'status' => 'success',
                'product_id' => $product->id,
                'name' => $product->name,
                'changes_detected' => $changesDetected,
                'stock_changed' => $stockChanged,
                'price_changed' => $priceChanged,
                'is_out_of_stock' => $isOutOfStock,
                'restocked' => ($oldProductStatus === 'out_of_stock' && $newProductStatus === 'published'),
                'old_stock' => $oldProductStock,
                'new_stock' => $totalNewStock,
            ];
        });
    }

    /**
     * Helper to accumulate results into global sync summary.
     */
    protected function accumulateSummary(array &$summary, array $itemResult): void
    {
        $summary['total_checked']++;
        if ($itemResult['status'] === 'success') {
            $summary['successful']++;
            if (!empty($itemResult['stock_changed'])) $summary['stock_changes']++;
            if (!empty($itemResult['price_changed'])) $summary['price_changes']++;
            if (!empty($itemResult['is_out_of_stock'])) $summary['out_of_stock_detected']++;
            if (!empty($itemResult['restocked'])) $summary['restocked']++;
        } else {
            $summary['failed']++;
        }
        $summary['logs'][] = $itemResult;
    }

    /**
     * Get real-time health and statistics of the inventory sync system.
     */
    public function getSystemStatus(): array
    {
        $config = $this->getConfig();

        $totalAli = Product::where('is_aliexpress_product', true)->count();
        $totalCj = Product::where('is_cj_product', true)->count();
        $totalAmazon = Product::where('is_amazon_product', true)->count();

        $outOfStockCount = Product::where(function ($q) {
            $q->where('is_aliexpress_product', true)
              ->orWhere('is_cj_product', true)
              ->orWhere('is_amazon_product', true);
        })->where(function ($q) {
            $q->where('stock_quantity', '<=', 0)
              ->orWhere('status', 'out_of_stock');
        })->count();

        $recentLogs = InventorySyncLog::latest()->take(15)->get();

        return [
            'config' => $config,
            'statistics' => [
                'total_dropship_products' => $totalAli + $totalCj + $totalAmazon,
                'aliexpress_products' => $totalAli,
                'cjdropshipping_products' => $totalCj,
                'amazon_products' => $totalAmazon,
                'out_of_stock_products' => $outOfStockCount,
            ],
            'recent_logs' => $recentLogs,
            'server_environment' => [
                'php_binary' => PHP_BINARY,
                'base_path' => base_path(),
                'cron_command' => "* * * * * cd " . base_path() . " && " . PHP_BINARY . " artisan schedule:run >> /dev/null 2>&1",
                'cpanel_cron_minute' => "*",
                'cpanel_cron_command' => PHP_BINARY . " " . base_path('artisan') . " schedule:run >> /dev/null 2>&1",
            ],
        ];
    }

    /**
     * Resynchronize a single product by its database ID.
     */
    public function syncProductById(int $productId): array
    {
        $product = Product::with('variants')->findOrFail($productId);
        $config = $this->getConfig();

        if ($product->is_aliexpress_product && $product->aliexpress_product_id) {
            return $this->syncSingleAliExpressProduct($product, $config);
        } elseif ($product->is_cj_product && $product->cj_product_id) {
            return $this->syncSingleCjProduct($product, $config);
        } elseif ($product->is_amazon_product && $product->amazon_asin) {
            return $this->syncSingleAmazonProduct($product, $config);
        }

        throw new Exception("Product #{$productId} is not associated with AliExpress, CJ Dropshipping, or Amazon.");
    }

    /**
     * Retry syncing all products that recently encountered errors or timeouts.
     */
    public function retryFailedProducts(int $limit = 20): array
    {
        $failedProductIds = InventorySyncLog::where('status', 'error')
            ->whereNotNull('product_id')
            ->latest('id')
            ->take($limit * 3)
            ->pluck('product_id')
            ->unique()
            ->take($limit)
            ->toArray();

        $summary = [
            'retried_count' => count($failedProductIds),
            'successful' => 0,
            'failed' => 0,
            'results' => [],
        ];

        $config = $this->getConfig();
        foreach ($failedProductIds as $id) {
            try {
                $product = Product::with('variants')->find($id);
                if (!$product) continue;

                if ($product->is_aliexpress_product && $product->aliexpress_product_id) {
                    $res = $this->syncSingleAliExpressProduct($product, $config);
                } elseif ($product->is_cj_product && $product->cj_product_id) {
                    $res = $this->syncSingleCjProduct($product, $config);
                } elseif ($product->is_amazon_product && $product->amazon_asin) {
                    $res = $this->syncSingleAmazonProduct($product, $config);
                } else {
                    continue;
                }

                if (($res['status'] ?? '') === 'failed') {
                    $summary['failed']++;
                } else {
                    $summary['successful']++;
                }
                $summary['results'][] = $res;
            } catch (Exception $e) {
                $summary['failed']++;
            }
        }

        return $summary;
    }
}
