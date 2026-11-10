<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\InventorySyncService;
use App\Models\InventorySyncLog;
use App\Models\Product;
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
            'default_markup_percent' => 'nullable|numeric|min:0|max:500',
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
            return response()->json([
                'success' => true,
                'message' => 'Product resync completed: ' . ($result['status'] ?? 'ok'),
                'data' => $result,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Retry failed: ' . $e->getMessage(),
            ], 500);
        }
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

