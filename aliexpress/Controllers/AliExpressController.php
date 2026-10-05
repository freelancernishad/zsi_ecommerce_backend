<?php

namespace AliExpress\Controllers;

use App\Http\Controllers\Controller;
use AliExpress\Services\AliExpressService;
use AliExpress\Services\AliExpressImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Exception;

class AliExpressController extends Controller
{
    protected AliExpressService $aliService;
    protected AliExpressImportService $importService;

    public function __construct(AliExpressService $aliService, AliExpressImportService $importService)
    {
        $this->aliService = $aliService;
        $this->importService = $importService;
    }

    /**
     * Search products live on AliExpress.
     */
    public function search(Request $request)
    {
        $keyword = $request->query('keyword', 'hoodie');
        $page = (int)$request->query('page', 1);
        $size = (int)$request->query('size', 20);
        $categoryId = $request->query('category_id');
        $countryCode = $request->query('country_code');
        $minPrice = $request->has('min_price') && $request->query('min_price') !== null ? (float)$request->query('min_price') : null;
        $maxPrice = $request->has('max_price') && $request->query('max_price') !== null ? (float)$request->query('max_price') : null;
        $freeShipping = $request->has('free_shipping') && $request->query('free_shipping') !== null ? (int)$request->query('free_shipping') : null;
        $videoOnly = $request->has('video_only') && (int)$request->query('video_only') === 1 ? 1 : null;

        try {
            $result = $this->aliService->searchProducts(
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
            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Parse AliExpress product details live by Product ID or URL.
     */
    public function parse(Request $request)
    {
        $productIdOrUrl = $request->query('url') ?: $request->query('product_id') ?: $request->query('id');
        if (empty($productIdOrUrl)) {
            return response()->json([
                'success' => false,
                'message' => 'AliExpress Product ID or URL parameter is required.',
            ], 400);
        }

        try {
            $data = $this->aliService->getProductDetail($productIdOrUrl);

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get AliExpress product details by Product ID route parameter.
     */
    public function detail($productId)
    {
        try {
            $data = $this->aliService->getProductDetail($productId);

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Import a single AliExpress product into the local Laravel database.
     */
    public function import(Request $request)
    {
        $request->validate([
            'product_id_or_url' => 'required|string',
            'profit_margin_percent' => 'nullable|numeric|min:0',
            'category_id' => 'nullable|exists:categories,id',
            'default_stock' => 'nullable|integer|min:1',
            'status' => 'nullable|string|in:published,draft',
        ]);

        try {
            $profitMargin = $request->has('profit_margin_percent')
                ? (float)$request->input('profit_margin_percent')
                : 20.0;

            $categoryId = $request->input('category_id') ? (int)$request->input('category_id') : null;
            $defaultStock = $request->input('default_stock') ? (int)$request->input('default_stock') : 50;
            $status = $request->input('status', 'published');

            $product = $this->importService->importAliExpressProduct(
                $request->input('product_id_or_url'),
                $profitMargin,
                $categoryId,
                $defaultStock,
                $status
            );

            return response()->json([
                'success' => true,
                'message' => 'AliExpress product imported successfully!',
                'data' => $product,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to import AliExpress product: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Bulk import multiple AliExpress products synchronously or trigger background batch.
     */
    public function bulkImport(Request $request)
    {
        $request->validate([
            'product_ids_or_urls' => 'required|array|min:1',
            'product_ids_or_urls.*' => 'required|string',
            'profit_margin_percent' => 'nullable|numeric|min:0',
            'category_id' => 'nullable|exists:categories,id',
            'default_stock' => 'nullable|integer|min:1',
            'status' => 'nullable|string|in:published,draft',
            'sync' => 'nullable|boolean',
        ]);

        $items = $request->input('product_ids_or_urls');
        $profitMargin = $request->has('profit_margin_percent') ? (float)$request->input('profit_margin_percent') : 20.0;
        $categoryId = $request->input('category_id') ? (int)$request->input('category_id') : null;
        $defaultStock = $request->input('default_stock') ? (int)$request->input('default_stock') : 50;
        $status = $request->input('status', 'published');
        $isSync = $request->boolean('sync', false);

        // If sync flag is true or item count <= 3, run inline synchronously
        if ($isSync || count($items) <= 3) {
            try {
                $result = $this->importService->bulkImportAliExpressProducts(
                    $items,
                    $profitMargin,
                    $categoryId,
                    $defaultStock,
                    $status
                );

                return response()->json([
                    'success' => true,
                    'message' => 'Bulk AliExpress import completed.',
                    'data' => $result,
                ]);
            } catch (Exception $e) {
                return response()->json([
                    'success' => false,
                    'message' => 'Bulk import failed: ' . $e->getMessage(),
                ], 500);
            }
        }

        // Async Background Batch File
        $batchId = 'ali_batch_' . uniqid() . '_' . time();
        $batchData = [
            'batch_id' => $batchId,
            'status' => 'pending',
            'total_items' => count($items),
            'processed_count' => 0,
            'successful_count' => 0,
            'failed_count' => 0,
            'items_input' => $items,
            'profit_margin_percent' => $profitMargin,
            'category_id' => $categoryId,
            'default_stock' => $defaultStock,
            'product_status' => $status,
            'created_at' => now()->toDateTimeString(),
            'items' => array_map(fn($item) => [
                'product_id_or_url' => $item,
                'status' => 'pending',
            ], $items),
        ];

        Storage::disk('local')->put("aliexpress_imports/{$batchId}.json", json_encode($batchData, JSON_PRETTY_PRINT));

        // Launch Artisan command in background CLI process
        try {
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                pclose(popen("start /B php artisan aliexpress:process-bulk-import {$batchId} > NUL 2>&1", "r"));
            } else {
                exec("php artisan aliexpress:process-bulk-import {$batchId} > /dev/null 2>&1 &");
            }
        } catch (Exception $e) {
            Log::warning("Failed to start background process for batch {$batchId}: " . $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => 'AliExpress bulk import batch created and queued for processing in background.',
            'batch_id' => $batchId,
            'data' => $batchData,
        ]);
    }

    /**
     * Get list of all AliExpress bulk import batch history.
     */
    public function getBulkImportBatches()
    {
        $files = Storage::disk('local')->files('aliexpress_imports');
        $batches = [];

        foreach ($files as $file) {
            if (str_ends_with($file, '.json')) {
                $content = json_decode(Storage::disk('local')->get($file), true);
                if ($content) {
                    $batches[] = $content;
                }
            }
        }

        usort($batches, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));

        return response()->json([
            'success' => true,
            'data' => array_slice($batches, 0, 30),
        ]);
    }

    /**
     * Get live progress status of a specific bulk import batch.
     */
    public function getBulkImportStatus($batchId)
    {
        $filePath = "aliexpress_imports/{$batchId}.json";
        if (!Storage::disk('local')->exists($filePath)) {
            return response()->json([
                'success' => false,
                'message' => 'Batch not found',
            ], 404);
        }

        $content = json_decode(Storage::disk('local')->get($filePath), true);

        return response()->json([
            'success' => true,
            'data' => $content,
        ]);
    }

    /**
     * Manually trigger background processing of a pending batch.
     */
    public function processBatch($batchId)
    {
        $filePath = "aliexpress_imports/{$batchId}.json";
        if (!Storage::disk('local')->exists($filePath)) {
            return response()->json([
                'success' => false,
                'message' => 'Batch file not found',
            ], 404);
        }

        try {
            Artisan::call('aliexpress:process-bulk-import', ['batchId' => $batchId]);

            return response()->json([
                'success' => true,
                'message' => "Batch {$batchId} process triggered successfully.",
                'output' => Artisan::output(),
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to trigger batch process: ' . $e->getMessage(),
            ], 500);
        }
    }
}
