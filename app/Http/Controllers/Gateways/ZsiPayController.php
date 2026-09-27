<?php

namespace App\Http\Controllers\Gateways;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ZsiPayController extends Controller
{
    /**
     * Create a Golden Mark / ZSI Pay Checkout Session & URL.
     */
    public function checkout(Request $request)
    {
        try {
            // Load ZSI Pay Gateway settings dynamically from site_settings DB table
            $settings = SiteSetting::where('group', 'payments')
                ->where('key', 'like', 'zsi_pay_%')
                ->pluck('value', 'key')
                ->toArray();

            $enabled = filter_var($settings['zsi_pay_enabled'] ?? '0', FILTER_VALIDATE_BOOLEAN) || ($settings['zsi_pay_enabled'] ?? '0') === '1';
            
            if (!$enabled) {
                return response()->json([
                    'success' => false,
                    'message' => 'Golden Mark Money Gateway (ZSI Pay) is currently disabled.'
                ], 400);
            }

            $baseUrl = rtrim($settings['zsi_pay_base_url'] ?? (env('NEXT_PUBLIC_ZSI_PAY_BASE_URL') ?: 'http://localhost:5000/api/v1'), '/');
            $apiKey = $settings['zsi_pay_api_key'] ?? '';
            $apiSecret = $settings['zsi_pay_api_secret'] ?? '';

            if (empty($apiKey) || empty($apiSecret)) {
                return response()->json([
                    'success' => false,
                    'message' => 'ZSI Pay API credentials (API Key & Secret Key) are missing in payment settings.'
                ], 400);
            }

            $customerEmail = $request->input('customer_email', 'guest@goldenmark.com');
            $items = $request->input('items', []);
            $orderId = $request->input('order_id');
            $orderNumber = $request->input('order_number');
            $totalAmount = (float) $request->input('total', 0);

            // Format items for ZSI Pay API payload
            $formattedItems = [];
            if (!empty($items) && is_array($items)) {
                foreach ($items as $item) {
                    $formattedItems[] = [
                        'name' => substr($item['name'] ?? $item['product_name'] ?? 'Product Item', 0, 150),
                        'quantity' => (int) ($item['quantity'] ?? 1),
                        'price' => (float) ($item['price'] ?? 1.00),
                    ];
                }
            } else {
                $formattedItems[] = [
                    'name' => 'Order ' . ($orderNumber ?? $orderId ?? 'Payment'),
                    'quantity' => 1,
                    'price' => $totalAmount > 0 ? $totalAmount : 1.00,
                ];
            }

            $payPayload = [
                'currency' => 'USD',
                'customer_email' => $customerEmail,
                'items' => $formattedItems,
                'metadata' => [
                    'order_id' => (string) $orderId,
                    'order_number' => (string) $orderNumber,
                ]
            ];

            $payEndpoint = $baseUrl . '/pay';

            // Send HTTP request to Golden Mark Money Gateway API Server
            $response = Http::withHeaders([
                'api_key' => $apiKey,
                'api_secret' => $apiSecret,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])->post($payEndpoint, $payPayload);

            $responseData = $response->json();

            if ($response->successful() && isset($responseData['data']['checkout_url'])) {
                $checkoutUrl = $responseData['data']['checkout_url'];
                return response()->json([
                    'success' => true,
                    'url' => $checkoutUrl,
                    'checkout_url' => $checkoutUrl,
                    'data' => $responseData['data']
                ]);
            }

            $errorMessage = $responseData['message'] ?? $responseData['error'] ?? 'Failed to initialize ZSI Pay checkout URL.';
            Log::error("ZSI Pay Checkout API Error: ", ['status' => $response->status(), 'response' => $responseData]);

            return response()->json([
                'success' => false,
                'message' => $errorMessage
            ], $response->status() >= 400 ? $response->status() : 400);

        } catch (\Exception $e) {
            Log::error("ZSI Pay Checkout Exception: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error initializing ZSI Pay payment: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Webhook Handler for ZSI Pay Gateway callbacks.
     */
    public function webhook(Request $request)
    {
        Log::info("ZSI Pay Webhook Received: ", $request->all());
        return response()->json(['success' => true, 'message' => 'Webhook received']);
    }
}
