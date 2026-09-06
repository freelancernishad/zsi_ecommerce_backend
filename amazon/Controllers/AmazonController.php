<?php

namespace Amazon\Controllers;

use App\Http\Controllers\Controller;
use Amazon\Services\AmazonScraperService;
use Amazon\Services\AmazonImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Exception;

class AmazonController extends Controller
{
    protected AmazonScraperService $scraperService;
    protected AmazonImportService $importService;

    public function __construct(AmazonScraperService $scraperService, AmazonImportService $importService)
    {
        $this->scraperService = $scraperService;
        $this->importService = $importService;
    }

    /**
     * Search products live on Amazon by keyword and filters.
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
        $videoOnly = $request->has('video_only') && (int)$request->query('video_only') === 1 ? 1 : null;

        try {
            $result = $this->scraperService->searchProducts(
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
     * Scrape and parse Amazon product details live by ASIN or URL.
     */
    public function parse(Request $request)
    {
        $asinOrUrl = $request->query('url') ?: $request->query('asin');
        if (empty($asinOrUrl)) {
            return response()->json([
                'success' => false,
                'message' => 'Amazon ASIN or URL parameter is required.',
            ], 400);
        }

        try {
            $data = $this->scraperService->getProductDetail($asinOrUrl);

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
     * Get Amazon product details by ASIN route parameter.
     */
    public function detail($asin)
    {
        try {
            $data = $this->scraperService->getProductDetail($asin);

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
     * Import a single Amazon product into the local Laravel database.
     */
    public function import(Request $request)
    {
        $request->validate([
            'asin_or_url' => 'required|string',
            'profit_margin_percent' => 'nullable|numeric|min:0',
            'category_id' => 'nullable|exists:categories,id',
            'default_stock' => 'nullable|integer|min:1',
            'status' => 'nullable|string|in:published,draft',
        ]);

        $asinOrUrl = $request->input('asin_or_url');
        $profitMargin = (float) $request->input('profit_margin_percent', 15);
        $categoryId = $request->input('category_id');
        $defaultStock = (int) $request->input('default_stock', 20);
        $status = $request->input('status', 'published');

        try {
            $importedProduct = $this->importService->importAmazonProduct(
                $asinOrUrl,
                $profitMargin,
                $categoryId ? (int) $categoryId : null,
                $defaultStock,
                $status
            );

            return response()->json([
                'success' => true,
                'message' => 'Amazon product imported successfully to local store!',
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
     * Bulk import multiple Amazon products asynchronously.
     */
    public function bulkImport(Request $request)
    {
        $request->validate([
            'asins_or_urls' => 'required|array|min:1',
            'asins_or_urls.*' => 'required|string',
            'profit_margin_percent' => 'nullable|numeric|min:0',
            'category_id' => 'nullable|exists:categories,id',
            'default_stock' => 'nullable|integer|min:1',
            'status' => 'nullable|string|in:published,draft',
            'sync' => 'nullable|boolean',
        ]);

        $items = $request->input('asins_or_urls');
        $profitMargin = (float) $request->input('profit_margin_percent', 15);
        $categoryId = $request->input('category_id');
        $defaultStock = (int) $request->input('default_stock', 20);
        $status = $request->input('status', 'published');
        $isSync = $request->boolean('sync', false);

        // Process synchronously if requested or single item
        if ($isSync || count($items) === 1) {
            @set_time_limit(300);
            try {
                $result = $this->importService->bulkImportAmazonProducts(
                    $items,
                    $profitMargin,
                    $categoryId ? (int) $categoryId : null,
                    $defaultStock,
                    $status
                );

                return response()->json([
                    'success' => true,
                    'message' => "Successfully imported {$result['successful']} of {$result['total']} Amazon products to store!",
                    'data' => $result,
                ]);
            } catch (Exception $e) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 500);
            }
        }

        // Asynchronous Background Execution
        $batchId = 'amz_batch_' . uniqid() . '_' . time();
        $batchPayload = [
            'batch_id' => $batchId,
            'status' => 'pending',
            'created_at' => now()->toDateTimeString(),
            'items' => $items,
            'profit_margin_percent' => $profitMargin,
            'category_id' => $categoryId ? (int) $categoryId : null,
            'default_stock' => $defaultStock,
            'publish_status' => $status,
            'total' => count($items),
        ];

        Storage::disk('local')->put("amazon_imports/{$batchId}.json", json_encode($batchPayload, JSON_PRETTY_PRINT));

        // Spawn background CLI command
        $phpBinary = PHP_BINARY;
        $artisanPath = base_path('artisan');
        $cmd = "{$phpBinary} \"{$artisanPath}\" amazon:process-bulk-import {$batchId}";

        $this->spawnBackgroundProcess($cmd);

        return response()->json([
            'success' => true,
            'background' => true,
            'batch_id' => $batchId,
            'total' => count($items),
            'message' => "Bulk import of " . count($items) . " Amazon items started in the background! You can safely navigate away.",
        ]);
    }

    /**
     * Check status of a background Amazon bulk import batch.
     */
    public function getBulkImportStatus($batchId)
    {
        $filePath = "amazon_imports/{$batchId}.json";

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
     * Get list of all Amazon bulk import batches history.
     */
    public function getBulkImportBatches()
    {
        $files = Storage::disk('local')->files('amazon_imports');
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
     * Cross-platform helper to launch background CLI command.
     */
    protected function spawnBackgroundProcess(string $cmd): void
    {
        if (str_starts_with(strtoupper(PHP_OS), 'WIN')) {
            pclose(popen("start \"\" /B {$cmd}", "r"));
        } else {
            exec("{$cmd} > /dev/null 2>&1 &");
        }
    }
}
