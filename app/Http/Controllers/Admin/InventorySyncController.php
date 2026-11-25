<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AmazonSmartSyncService;
use App\Services\InventorySyncService;
use App\Models\InventorySyncLog;
use App\Models\Product;
use App\Models\SiteSetting;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Exception;

class InventorySyncController extends Controller
{
    protected InventorySyncService $syncService;

    public function __construct(InventorySyncService $syncService)
    {
        $this->syncService = $syncService;
    }

    /**
     * Get system status, configuration, stats, and server cron instructions.
     */
    public function status()
    {
        try {
            $data = $this->syncService->getSystemStatus();
            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load sync status: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update inventory synchronization settings.
     */
    public function updateSettings(Request $request)
    {
        $validated = $request->validate([
            'auto_sync_enabled' => 'nullable|string|in:0,1',
            'sync_frequency_hours' => 'nullable|integer|in:1,2,4,6,12,24',
            'sync_aliexpress' => 'nullable|string|in:0,1',
            'sync_cjdropshipping' => 'nullable|string|in:0,1',
            'sync_amazon' => 'nullable|string|in:0,1',
            'batch_limit' => 'nullable|integer|min:5|max:200',
            'request_timeout_seconds' => 'nullable|integer|min:5|max:120',
            'retry_attempts' => 'nullable|integer|min:0|max:5',
            'rate_limit_delay_ms' => 'nullable|integer|min:100|max:10000',
            'stock_out_action' => 'nullable|string|in:variant_zero_keep_visible,unpublish_product',
            'auto_restock_enabled' => 'nullable|string|in:0,1',
            'price_increase_action' => 'nullable|string|in:auto_update_markup,keep_current_price',
            'price_decrease_action' => 'nullable|string|in:keep_higher_margin,update_lower_price',
            'default_markup_percent' => 'nullable|numeric|min:-99|max:500',
        ]);

        try {
            $updatedConfig = $this->syncService->saveConfig($validated);
            return response()->json([
                'success' => true,
                'message' => 'Inventory auto-sync settings saved successfully!',
                'data' => $updatedConfig,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to save settings: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Manually trigger inventory sync for one or all channels.
     */
    public function triggerSync(Request $request)
    {
        $channel = $request->input('channel', 'all');
        $limit = $request->input('limit') ? (int)$request->input('limit') : null;

        try {
            $summary = $this->syncService->syncAll($channel, $limit);
            return response()->json([
                'success' => true,
                'message' => "Inventory sync completed for channel: {$channel}",
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
     * Get paginated inventory sync logs.
     */
    public function logs(Request $request)
    {
        $supplier = $request->query('supplier');
        $status = $request->query('status');
        $changesOnly = $request->query('changes_only');
        $perPage = (int)$request->query('per_page', 20);

        $query = InventorySyncLog::query()->latest();

        if ($supplier && $supplier !== 'all') {
            $query->where('supplier', $supplier);
        }
        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }
        if ($changesOnly === '1' || $changesOnly === 'true') {
            $query->where('changes_detected', true);
        }

        $logs = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $logs,
        ]);
    }

    /**
     * Get imported products currently detected as out of stock.
     */
    public function outOfStock(Request $request)
    {
        $perPage = (int)$request->query('per_page', 20);

        $products = Product::where(function ($q) {
            $q->where('is_aliexpress_product', true)
              ->orWhere('is_cj_product', true)
              ->orWhere('is_amazon_product', true);
        })
        ->where(function ($q) {
            $q->where('stock_quantity', '<=', 0)
              ->orWhere('status', 'out_of_stock');
        })
        ->with('variants')
        ->latest('updated_at')
        ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $products,
        ]);
    }

    /**
     * Retry syncing a single product or log entry.
     */
    public function retryProduct(Request $request)
    {
        $productId = $request->input('product_id');
        $logId = $request->input('log_id');

        if (!$productId && $logId) {
            $log = InventorySyncLog::find($logId);
            $productId = $log?->product_id;
        }

        if (!$productId) {
            return response()->json([
                'success' => false,
                'message' => 'Product ID or valid Log ID is required for retry.',
            ], 400);
        }

        try {
            $result = $this->syncService->syncProductById((int)$productId);
            $freshProduct = Product::find((int)$productId);
            $cooldownHours = InventorySyncService::DETAIL_VIEW_COOLDOWN_HOURS;

            $rawSyncedAt = null;
            if ($freshProduct) {
                if ($freshProduct->is_amazon_product && !empty($freshProduct->amazon_asin)) {
                    $rawSyncedAt = $freshProduct->amazon_synced_at;
                } elseif ($freshProduct->is_cj_product && !empty($freshProduct->cj_product_id)) {
                    $rawSyncedAt = $freshProduct->cj_synced_at;
                } elseif ($freshProduct->is_aliexpress_product && !empty($freshProduct->aliexpress_product_id)) {
                    $rawSyncedAt = $freshProduct->aliexpress_synced_at;
                }
            }

            $syncedCarbon = !empty($rawSyncedAt) ? Carbon::parse($rawSyncedAt) : null;
            $nextSyncCarbon = $syncedCarbon ? $syncedCarbon->copy()->addHours($cooldownHours) : null;

            $payload = array_merge($result, [
                'price' => $freshProduct ? (float) $freshProduct->price : ($result['new_price'] ?? null),
                'stock_quantity' => $freshProduct ? (int) $freshProduct->stock_quantity : ($result['new_stock'] ?? null),
                'product_status' => $freshProduct?->status,
                'last_synced_at' => $syncedCarbon ? $syncedCarbon->toIso8601String() : null,
                'last_synced_ago' => $syncedCarbon ? $syncedCarbon->diffForHumans() : null,
                'next_sync_at' => $nextSyncCarbon ? $nextSyncCarbon->toIso8601String() : null,
            ]);

            $isSuccess = ($result['status'] ?? 'success') !== 'failed';

            return response()->json([
                'success' => $isSuccess,
                'message' => $isSuccess
                    ? 'Product resynced successfully' . (!empty($result['repaired']) ? ' (and repaired)' : '') . '.'
                    : ($result['error'] ?? 'Product resync failed.'),
                'data' => $payload,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Retry failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get paginated detail-page view sync logs (or all sync logs) with product links and next-sync countdowns.
     */
    public function detailViewLogs(Request $request)
    {
        $scope = strtolower(trim((string) $request->query('scope', 'detail_view')));
        $supplier = strtolower(trim((string) $request->query('supplier', 'all')));
        $status = strtolower(trim((string) $request->query('status', 'all')));
        $changesOnly = in_array((string) $request->query('changes_only', '0'), ['1', 'true'], true);
        $search = trim((string) $request->query('search', ''));
        $perPage = min(100, max(5, (int) $request->query('per_page', 20)));
        $cooldownHours = InventorySyncService::DETAIL_VIEW_COOLDOWN_HOURS;
        $now = now();

        $applyDetailViewScope = function ($q) {
            $q->where(function ($sub) {
                $sub->where('details->trigger', 'detail_view')
                    ->orWhere('details->engine', 'amazon_detail_view_repair');
            });
        };

        $query = InventorySyncLog::query()
            ->with([
                'product' => function ($q) {
                    $q->select([
                        'id', 'name', 'slug', 'sku', 'price', 'stock_quantity', 'status',
                        'is_amazon_product', 'is_cj_product', 'is_aliexpress_product',
                        'amazon_asin', 'amazon_url', 'cj_product_id',
                        'aliexpress_product_id', 'aliexpress_url',
                        'amazon_synced_at', 'cj_synced_at', 'aliexpress_synced_at',
                    ])->with(['images' => function ($imgQ) {
                        $imgQ->orderByDesc('is_primary')->orderBy('id');
                    }]);
                },
            ])
            ->latest('id');

        if ($scope !== 'all') {
            $applyDetailViewScope($query);
        }

        if ($supplier && $supplier !== 'all') {
            if ($supplier === 'cj') {
                $supplier = 'cjdropshipping';
            }
            $query->where('supplier', $supplier);
        }

        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        if ($changesOnly) {
            $query->where('changes_detected', true);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('product_name', 'like', "%{$search}%")
                  ->orWhere('supplier_id', 'like', "%{$search}%")
                  ->orWhereHas('product', function ($pq) use ($search) {
                      $pq->where('sku', 'like', "%{$search}%")
                         ->orWhere('slug', 'like', "%{$search}%");
                  });
            });
        }

        $paginated = $query->paginate($perPage);

        $items = $paginated->getCollection()->map(function (InventorySyncLog $log) use ($cooldownHours, $now) {
            $product = $log->product;
            $details = is_array($log->details) ? $log->details : [];

            $supplierKey = strtolower((string) $log->supplier);
            $supplierId = (string) ($log->supplier_id ?? '');

            $supplierUrl = null;
            if ($supplierKey === 'amazon') {
                $asin = strtoupper($supplierId ?: ($product?->amazon_asin ?? ''));
                $supplierUrl = $product?->amazon_url ?: ($asin !== '' ? "https://www.amazon.com/dp/{$asin}" : null);
            } elseif ($supplierKey === 'aliexpress') {
                $aliId = $supplierId ?: ($product?->aliexpress_product_id ?? '');
                $supplierUrl = $product?->aliexpress_url ?: ($aliId !== '' ? "https://www.aliexpress.com/item/{$aliId}.html" : null);
            } elseif ($supplierKey === 'cjdropshipping' || $supplierKey === 'cj') {
                $cjPid = $supplierId ?: ($product?->cj_product_id ?? '');
                $supplierUrl = $cjPid !== '' ? "https://cjdropshipping.com/product/-p-{$cjPid}.html" : null;
            }

            $rawProductSyncedAt = null;
            if ($product) {
                if ($supplierKey === 'amazon' || $product->is_amazon_product) {
                    $rawProductSyncedAt = $product->amazon_synced_at;
                } elseif ($supplierKey === 'cjdropshipping' || $supplierKey === 'cj' || $product->is_cj_product) {
                    $rawProductSyncedAt = $product->cj_synced_at;
                } elseif ($supplierKey === 'aliexpress' || $product->is_aliexpress_product) {
                    $rawProductSyncedAt = $product->aliexpress_synced_at;
                }
            }
            if (!$rawProductSyncedAt && $log->status === 'success') {
                $rawProductSyncedAt = $log->created_at;
            }

            $productSyncedCarbon = $rawProductSyncedAt ? Carbon::parse($rawProductSyncedAt) : null;
            $nextSyncCarbon = $productSyncedCarbon ? $productSyncedCarbon->copy()->addHours($cooldownHours) : null;

            $oldPrice = $log->old_price !== null ? (float) $log->old_price : null;
            $newPrice = $log->new_price !== null ? (float) $log->new_price : null;
            $oldStock = $log->old_stock !== null ? (int) $log->old_stock : null;
            $newStock = $log->new_stock !== null ? (int) $log->new_stock : null;

            $priceChanged = $oldPrice !== null && $newPrice !== null && abs($oldPrice - $newPrice) > 0.001;
            $stockChanged = $oldStock !== null && $newStock !== null && $oldStock !== $newStock;
            $engine = (string) ($details['engine'] ?? '');
            $isRepaired = (bool) ($details['repaired'] ?? ($engine === 'amazon_detail_view_repair'));
            $trigger = (string) ($details['trigger'] ?? ($engine === 'amazon_detail_view_repair' ? 'detail_view' : 'cron'));
            $primaryImage = $product?->images?->first()?->image_path;

            return [
                'id' => $log->id,
                'product_id' => $log->product_id,
                'product_name' => $product?->name ?: ($log->product_name ?: "Product #{$log->product_id}"),
                'product_slug' => $product?->slug,
                'product_sku' => $product?->sku,
                'product_image' => $primaryImage,
                'product_status' => $product?->status,
                'current_price' => $product ? (float) $product->price : $newPrice,
                'current_stock' => $product ? (int) $product->stock_quantity : $newStock,
                'supplier' => $supplierKey,
                'supplier_id' => $supplierId,
                'supplier_url' => $supplierUrl,
                'status' => $log->status,
                'changes_detected' => (bool) $log->changes_detected,
                'trigger' => $trigger,
                'repaired' => $isRepaired,
                'provider' => $details['provider'] ?? ($engine ?: null),
                'old_price' => $oldPrice,
                'new_price' => $newPrice,
                'price_changed' => $priceChanged,
                'old_stock' => $oldStock,
                'new_stock' => $newStock,
                'stock_changed' => $stockChanged,
                'old_status' => $details['old_status'] ?? null,
                'new_status' => $details['new_status'] ?? null,
                'error_message' => $log->error_message,
                'synced_at' => $log->created_at?->toIso8601String(),
                'synced_at_formatted' => $log->created_at?->toDateTimeString(),
                'synced_ago' => $log->created_at?->diffForHumans($now),
                'product_last_synced_at' => $productSyncedCarbon?->toIso8601String(),
                'next_sync_at' => $nextSyncCarbon?->toIso8601String(),
                'next_sync_in_seconds' => $nextSyncCarbon ? max(0, $now->diffInSeconds($nextSyncCarbon, false)) : 0,
                'can_auto_sync_now' => $nextSyncCarbon === null || $nextSyncCarbon->lte($now),
            ];
        })->values();

        // Compute KPI summary across detail-view logs (independent of current pagination/filters)
        $detailBase = InventorySyncLog::query();
        $applyDetailViewScope($detailBase);

        $totalDetailViewSyncs = (clone $detailBase)->count();
        $todayDetailViewSyncs = (clone $detailBase)->where('created_at', '>=', $now->copy()->startOfDay())->count();
        $changesCount = (clone $detailBase)->where('changes_detected', true)->count();
        $failedCount = (clone $detailBase)->where('status', 'error')->count();
        $repairedCount = (clone $detailBase)->where(function ($q) {
            $q->where('details->repaired', true)
              ->orWhere('details->engine', 'amazon_detail_view_repair');
        })->count();

        $amazonSetting = SiteSetting::where('key', 'amazon_sync_on_product_view')->first();
        $amazonSmartConfig = app(AmazonSmartSyncService::class)->getConfig();
        $amazonViewToggle = $amazonSetting !== null
            ? in_array((string) $amazonSetting->value, ['1', 'true'], true)
            : (!empty($amazonSmartConfig['jit_sync_enabled']) && !empty($amazonSmartConfig['jit_on_product_view']));

        $cjViewToggle = in_array((string) SiteSetting::getValue('cj_sync_on_product_view', '0'), ['1', 'true'], true);
        $aliViewToggle = in_array((string) SiteSetting::getValue('aliexpress_sync_on_product_view', '0'), ['1', 'true'], true);

        return response()->json([
            'success' => true,
            'summary' => [
                'total_detail_view_syncs' => $totalDetailViewSyncs,
                'today_detail_view_syncs' => $todayDetailViewSyncs,
                'changes_count' => $changesCount,
                'repaired_count' => $repairedCount,
                'failed_count' => $failedCount,
                'cooldown_hours' => $cooldownHours,
                'toggles' => [
                    'amazon' => $amazonViewToggle,
                    'cjdropshipping' => $cjViewToggle,
                    'aliexpress' => $aliViewToggle,
                ],
            ],
            'data' => [
                'data' => $items,
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'from' => $paginated->firstItem() ?? 0,
                'to' => $paginated->lastItem() ?? 0,
            ],
        ]);
    }

    /**
     * Retry all products that recently failed or timed out.
     */
    public function retryFailed(Request $request)
    {
        $limit = (int)$request->input('limit', 20);

        try {
            $summary = $this->syncService->retryFailedProducts($limit);
            return response()->json([
                'success' => true,
                'message' => "Retried {$summary['retried_count']} failed products",
                'data' => $summary,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Bulk retry failed: ' . $e->getMessage(),
            ], 500);
        }
    }
}

