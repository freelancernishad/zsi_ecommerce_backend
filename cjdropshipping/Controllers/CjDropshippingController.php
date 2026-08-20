<?php

namespace CjDropshipping\Controllers;

use App\Http\Controllers\Controller;
use CjDropshipping\Services\CjDropshippingService;
use CjDropshipping\Services\CjImportService;
use App\Models\Order;
use Illuminate\Http\Request;
use Exception;

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

        try {
            $result = $this->cjService->searchProducts($keyword, $page, $size, $categoryId);
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
