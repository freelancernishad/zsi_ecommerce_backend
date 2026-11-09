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

    /**
     * Get AliExpress connection and OAuth status.
     */
    public function status()
    {
        $settings = \App\Models\SiteSetting::where('group', 'aliexpress')->pluck('value', 'key')->toArray();

        $getVal = function ($key, $default = '') use ($settings) {
            $val = $settings[$key] ?? config("aliexpress.{$key}", $default);
            if (is_array($val)) return (string)($val[0] ?? $default);
            return (string)($val ?? $default);
        };

        $appKey = $getVal('aliexpress_app_key', config('aliexpress.app_key', ''));
        $appSecret = $getVal('aliexpress_app_secret', config('aliexpress.app_secret', ''));
        $accessToken = $getVal('aliexpress_access_token', '');
        $refreshToken = $getVal('aliexpress_refresh_token', '');
        $tokenExpiresAt = $getVal('aliexpress_token_expires_at', '');
        $userNick = $getVal('aliexpress_user_nick', '');
        $rapidApiKey = $getVal('aliexpress_rapidapi_key', config('aliexpress.rapidapi_key', ''));

        $isTokenExpired = false;
        if (!empty($tokenExpiresAt)) {
            $isTokenExpired = now()->greaterThan(\Carbon\Carbon::parse($tokenExpiresAt));
        }

        return response()->json([
            'success' => true,
            'data' => [
                'has_app_key' => !empty($appKey),
                'has_app_secret' => !empty($appSecret),
                'has_access_token' => !empty($accessToken),
                'has_refresh_token' => !empty($refreshToken),
                'has_rapidapi_key' => !empty($rapidApiKey),
                'is_connected' => !empty($appKey) && !empty($appSecret) && (!empty($accessToken) || !empty($rapidApiKey)),
                'user_nick' => $userNick,
                'token_expires_at' => $tokenExpiresAt,
                'is_token_expired' => $isTokenExpired,
                'default_profit_margin' => (float)$getVal('aliexpress_default_profit_margin', (string)config('aliexpress.default_profit_margin', 20.0)),
                'default_stock' => (int)$getVal('aliexpress_default_stock', (string)config('aliexpress.default_stock', 50)),
                'ship_to_country' => $getVal('aliexpress_ship_to_country', config('aliexpress.ship_to_country', 'US')),
                'currency' => $getVal('aliexpress_currency', config('aliexpress.currency', 'USD')),
            ],
        ]);
    }

    /**
     * Get OAuth authorization URL for AliExpress Open Platform login.
     */
    public function oauthUrl(Request $request)
    {
        $redirectUri = $request->query('redirect_uri') ?: url('/admin/settings/aliexpress/callback');

        try {
            $url = $this->aliService->getOAuthAuthorizationUrl($redirectUri);
            return response()->json([
                'success' => true,
                'data' => [
                    'authorization_url' => $url,
                    'redirect_uri' => $redirectUri,
                ],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Exchange OAuth code for Access Token and Refresh Token.
     */
    public function exchangeToken(Request $request)
    {
        $request->validate([
            'code' => 'required|string',
        ]);

        try {
            $data = $this->aliService->createTokenFromCode($request->input('code'));
            return response()->json([
                'success' => true,
                'message' => 'AliExpress OAuth token generated successfully!',
                'data' => $data,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Token exchange failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Refresh AliExpress Access Token.
     */
    public function refreshToken()
    {
        try {
            $data = $this->aliService->refreshAccessToken();
            return response()->json([
                'success' => true,
                'message' => 'AliExpress Access Token renewed successfully!',
                'data' => $data,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Token refresh failed: ' . $e->getMessage(),
            ], 500);
        }
    }
}
