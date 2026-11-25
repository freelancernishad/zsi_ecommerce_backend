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
     * Compare a previewed product across AliExpress, CJ Dropshipping, and Amazon.
     */
    public function compare(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'source_supplier' => 'required|string|in:aliexpress,amazon,cj',
            'product_id' => 'nullable|string|max:150',
            'title' => 'required|string|max:500',
            'price' => 'nullable|numeric|min:0',
            'image' => 'nullable|string|max:2000',
            'url' => 'nullable|string|max:2000',
            'brand' => 'nullable|string|max:200',
            'custom_query' => 'nullable|string|max:250',
            'limit' => 'nullable|integer|min:1|max:25',
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
}
