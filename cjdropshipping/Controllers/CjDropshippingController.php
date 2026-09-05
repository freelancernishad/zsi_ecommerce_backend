<?php

namespace CjDropshipping\Controllers;

use App\Http\Controllers\Controller;
use CjDropshipping\Services\CjDropshippingService;
use CjDropshipping\Services\CjImportService;
use App\Models\Order;
use Illuminate\Http\Request;
use Exception;

use Illuminate\Support\Facades\Storage;
use CjDropshipping\Jobs\ProcessCjBulkImportJob;

class CjDropshippingController extends Controller
{
    protected CjDropshippingService $cjService;
    protected CjImportService $cjImportService;

    public function __construct(CjDropshippingService $cjService, CjImportService $cjImportService)
    {
        $this->cjService = $cjService;
        $this->cjImportService = $cjImportService;
    }

    /**
     * Search products live on CJ Dropshipping.
     */
    public function search(Request $request)
    {
        $keyword = $request->query('keyword', 'hoodie');
        $page = (int) $request->query('page', 1);
        $size = (int) $request->query('size', 20);
        $categoryId = $request->query('category_id');
        $countryCode = $request->query('country_code');
        $minPrice = $request->has('min_price') && $request->query('min_price') !== null ? (float) $request->query('min_price') : null;
        $maxPrice = $request->has('max_price') && $request->query('max_price') !== null ? (float) $request->query('max_price') : null;
        $freeShipping = $request->has('free_shipping') && $request->query('free_shipping') !== null ? (int) $request->query('free_shipping') : null;
        $videoOnly = $request->has('video_only') && (int)$request->query('video_only') === 1 ? 10 : null;

        try {
            $result = $this->cjService->searchProducts(
                $keyword,
                $page,
                $size,
                $categoryId,
                $countryCode,
                $minPrice,
                $maxPrice,
                $freeShipping,
                $videoOnly
            );
            return response()->json($result);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get CJ product details by CJ Product ID (PID).
     */
    public function detail($pid)
    {
        try {
            $productData = $this->cjService->getProductDetail($pid);

            if (!$productData) {
                return response()->json([
                    'success' => false,
                    'message' => 'Product not found on CJ Dropshipping',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $productData,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Import a CJ product to local Laravel database.
     */
    public function import(Request $request)
    {
        $request->validate([
            'cj_product_id' => 'required|string',
            'profit_margin_percent' => 'nullable|numeric|min:0',
            'category_id' => 'nullable|exists:categories,id',
        ]);

        $cjProductId = $request->input('cj_product_id');
        $profitMargin = (float) $request->input('profit_margin_percent', 20);
        $categoryId = $request->input('category_id');

        try {
            $importedProduct = $this->cjImportService->importCjProduct(
                $cjProductId,
                $profitMargin,
                $categoryId ? (int) $categoryId : null
            );

            return response()->json([
                'success' => true,
                'message' => 'Product imported successfully to local store!',
                'data' => $importedProduct->load(['images', 'variants']),
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Bulk import multiple CJ products. Spawns an asynchronous background CLI process without cron job or queue workers.
     */
    public function bulkImport(Request $request)
    {
        $request->validate([
            'cj_product_ids' => 'required|array|min:1',
            'cj_product_ids.*' => 'required|string',
            'profit_margin_percent' => 'nullable|numeric|min:0',
            'category_id' => 'nullable|exists:categories,id',
            'sync' => 'nullable|boolean',
        ]);

        $cjProductIds = $request->input('cj_product_ids');
        $profitMargin = (float) $request->input('profit_margin_percent', 20);
        $categoryId = $request->input('category_id');
        $isSync = $request->boolean('sync', false);

        // If sync flag explicitly requested and single item, process synchronously
        if ($isSync && count($cjProductIds) === 1) {
            @set_time_limit(300);
            try {
                $result = $this->cjImportService->bulkImportCjProducts(
                    $cjProductIds,
                    $profitMargin,
                    $categoryId ? (int) $categoryId : null
                );

                return response()->json([
                    'success' => true,
                    'message' => "Successfully imported {$result['successful']} of {$result['total']} products to store!",
                    'data' => $result,
                ]);
            } catch (Exception $e) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 500);
            }
        }

        // Automatic Zero-Cron Background Execution: Save batch payload & spawn CLI process asynchronously
        $batchId = 'cj_batch_' . uniqid() . '_' . time();
        $batchPayload = [
            'batch_id' => $batchId,
            'status' => 'pending',
            'created_at' => now()->toDateTimeString(),
            'pids' => $cjProductIds,
            'profit_margin_percent' => $profitMargin,
            'category_id' => $categoryId ? (int) $categoryId : null,
            'total' => count($cjProductIds),
        ];

        Storage::disk('local')->put("cj_imports/{$batchId}.json", json_encode($batchPayload, JSON_PRETTY_PRINT));

        // Spawn background CLI process (works on both Windows & Linux without cron job or queue workers)
        $phpBinary = PHP_BINARY;
        $artisanPath = base_path('artisan');
        $cmd = "{$phpBinary} \"{$artisanPath}\" cj:process-bulk-import {$batchId}";

        $this->spawnBackgroundProcess($cmd);

        return response()->json([
            'success' => true,
            'background' => true,
            'batch_id' => $batchId,
            'total' => count($cjProductIds),
            'message' => "Bulk import of " . count($cjProductIds) . " products started in the background! You can safely navigate away.",
        ]);
    }

    /**
     * Check status of a background bulk import batch.
     */
    public function getBulkImportStatus($batchId)
    {
        $filePath = "cj_imports/{$batchId}.json";

        if (!Storage::disk('local')->exists($filePath)) {
            return response()->json([
                'success' => false,
                'message' => 'Batch file not found',
            ], 404);
        }

        $data = json_decode(Storage::disk('local')->get($filePath), true);

        return response()->json([
            'success' => true,
            'data' => $data,
        ])->header('Cache-Control', 'no-cache, no-store, max-age=0, must-revalidate');
    }

    /**
     * Get list of all CJ bulk import batches history.
     */
    public function getBulkImportBatches()
    {
        $files = Storage::disk('local')->files('cj_imports');
        $batches = [];

        foreach ($files as $file) {
            if (str_ends_with($file, '.json')) {
                $content = Storage::disk('local')->get($file);
                $data = json_decode($content, true);
                if ($data && isset($data['batch_id'])) {
                    $batches[] = $data;
                }
            }
        }

        usort($batches, function ($a, $b) {
            return strcmp($b['created_at'] ?? '', $a['created_at'] ?? '');
        });

        return response()->json([
            'success' => true,
            'data' => array_slice($batches, 0, 50),
        ])->header('Cache-Control', 'no-cache, no-store, max-age=0, must-revalidate');
    }

    /**
     * Cross-platform helper to launch asynchronous background CLI command.
     */
    protected function spawnBackgroundProcess(string $cmd): void
    {
        if (str_starts_with(strtoupper(PHP_OS), 'WIN')) {
            pclose(popen("start \"\" /B {$cmd}", "r"));
        } else {
            exec("{$cmd} > /dev/null 2>&1 &");
        }
    }

    /**
     * Calculate shipping rate for a variant to a destination country.
     */
    public function calculateShipping(Request $request)
    {
        $request->validate([
            'end_country_code' => 'required|string|size:2',
            'vid' => 'required|string',
            'quantity' => 'nullable|integer|min:1',
        ]);

        try {
            $freight = $this->cjService->calculateShipping(
                strtoupper($request->input('end_country_code')),
                $request->input('vid'),
                (int) $request->input('quantity', 1)
            );

            return response()->json([
                'success' => true,
                'data' => $freight,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Fulfill an order with CJ Dropshipping.
     */
    public function fulfillOrder($orderId)
    {
        $order = Order::with(['items.product', 'items.variant'])->findOrFail($orderId);

        $cjItems = [];
        foreach ($order->items as $item) {
            if ($item->variant && $item->variant->cj_vid) {
                $cjItems[] = [
                    'vid' => $item->variant->cj_vid,
                    'quantity' => $item->quantity,
                ];
            }
        }

        if (empty($cjItems)) {
            return response()->json([
                'success' => false,
                'message' => 'No CJ Dropshipping items found in this order.',
            ], 400);
        }

        $orderPayload = [
            'orderNumber' => $order->order_number,
            'shippingZip' => $order->shipping_zip_code ?? '',
            'shippingCountryCode' => $order->shipping_country ?? 'US',
            'shippingCity' => $order->shipping_city ?? '',
            'shippingAddress' => $order->shipping_street_address ?? '',
            'shippingCustomerName' => trim(($order->shipping_first_name ?? '') . ' ' . ($order->shipping_last_name ?? '')),
            'shippingPhone' => $order->shipping_phone ?? '',
            'products' => $cjItems,
        ];

        try {
            $cjResponse = $this->cjService->createOrder($orderPayload);

            if (isset($cjResponse['code']) && $cjResponse['code'] === 200) {
                $order->update([
                    'cj_order_id' => $cjResponse['data']['orderId'] ?? ('CJ-' . time()),
                    'cj_order_status' => 'Submitted',
                    'fulfillment_status' => 'processing',
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Order submitted to CJ Dropshipping successfully!',
                    'data' => $cjResponse['data'] ?? [],
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => $cjResponse['message'] ?? 'CJ Order submission failed',
            ], 500);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
