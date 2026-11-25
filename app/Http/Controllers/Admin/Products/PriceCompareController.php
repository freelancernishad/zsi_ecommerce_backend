<?php

namespace App\Http\Controllers\Admin\Products;

use App\Http\Controllers\Controller;
use App\Services\SupplierPriceCompareService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PriceCompareController extends Controller
{
    public function __construct(
        protected SupplierPriceCompareService $compareService
    ) {
    }

    /**
     * Compare a product across AliExpress, CJ Dropshipping, and Amazon.
     */
    public function compare(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'source_supplier' => 'required|string|in:aliexpress,amazon,cj,store',
            'product_id' => 'nullable|string|max:150',
            'title' => 'required|string|max:500',
            'price' => 'nullable|numeric|min:0',
            'image' => 'nullable|string|max:2000',
            'url' => 'nullable|string|max:2000',
            'brand' => 'nullable|string|max:200',
            'custom_query' => 'nullable|string|max:250',
            'limit' => 'nullable|integer|min:1|max:25',
            'page' => 'nullable|integer|min:1|max:5',
            'refresh' => 'nullable|boolean',
        ]);

        try {
            $result = $this->compareService->compare($validated);

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (\Throwable $e) {
            Log::error('Price comparison failed: ' . $e->getMessage(), [
                'source_supplier' => $validated['source_supplier'] ?? null,
                'title' => $validated['title'] ?? null,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to compare supplier prices: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Fetch live verified price, shipping cost, and stock for a single supplier candidate.
     */
    public function checkDetails(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'supplier' => 'required|string|in:aliexpress,amazon,cj',
            'product_id' => 'required|string|max:150',
            'country_code' => 'nullable|string|size:2',
        ]);

        try {
            $details = $this->compareService->checkCandidateDetails(
                $validated['supplier'],
                $validated['product_id'],
                $validated['country_code'] ?? 'US'
            );

            return response()->json([
                'success' => true,
                'data' => $details,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => true,
                'data' => [
                    'supplier' => $validated['supplier'],
                    'product_id' => $validated['product_id'],
                    'verified_price' => null,
                    'shipping' => null,
                    'total_cost' => null,
                    'delivery_estimate' => null,
                    'available' => null,
                    'stock' => null,
                    'rating' => null,
                    'variants_count' => null,
                    'checked_at' => now()->toIso8601String(),
                    'warning' => 'Live detail check skipped: ' . $e->getMessage(),
                ],
            ]);
        }
    }
}
