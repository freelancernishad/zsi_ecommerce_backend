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
            $subtotalAmount = (float) $request->input('subtotal', 0);
            $shippingCost = (float) $request->input('shipping_cost', 0);
            $taxAmount = (float) $request->input('tax', 0);
            $discountAmount = (float) $request->input('discount', 0);

            // Format items for ZSI Pay API payload
            $formattedItems = [];
            $itemsSum = 0;
            if (!empty($items) && is_array($items)) {
                foreach ($items as $item) {
                    $itemPrice = (float) ($item['price'] ?? 1.00);
                    $itemQty = (int) ($item['quantity'] ?? 1);
                    $itemsSum += ($itemPrice * $itemQty);

                    $formattedItems[] = [
                        'name' => substr($item['name'] ?? $item['product_name'] ?? 'Product Item', 0, 150),
                        'quantity' => $itemQty,
                        'price' => $itemPrice,
                    ];
                }

                // Add Shipping Fee line item so ZSI Pay total includes shipping
                if ($shippingCost > 0) {
                    $formattedItems[] = [
                        'name' => 'Shipping Fee',
                        'quantity' => 1,
                        'price' => (float) $shippingCost,
                    ];
                    $itemsSum += (float) $shippingCost;
                }

                // Add Tax line item so ZSI Pay total includes tax
                if ($taxAmount > 0) {
                    $formattedItems[] = [
                        'name' => 'Estimated Tax',
                        'quantity' => 1,
                        'price' => (float) $taxAmount,
                    ];
                    $itemsSum += (float) $taxAmount;
                }

                // Add Discount line item if discount applied
                if ($discountAmount > 0) {
                    $formattedItems[] = [
                        'name' => 'Discount',
                        'quantity' => 1,
                        'price' => - (float) $discountAmount,
                    ];
                    $itemsSum -= (float) $discountAmount;
                }

                // Safety check: if totalAmount > 0 and differs from items sum, add adjustment line item
                if ($totalAmount > 0 && abs($totalAmount - $itemsSum) > 0.01) {
                    $diff = round($totalAmount - $itemsSum, 2);
                    if ($diff != 0) {
                        $formattedItems[] = [
                            'name' => $diff > 0 ? 'Fee / Adjustment' : 'Discount / Adjustment',
                            'quantity' => 1,
                            'price' => $diff,
                        ];
                    }
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
                    'subtotal' => (string) number_format($subtotalAmount, 2, '.', ''),
                    'shipping' => (string) number_format($shippingCost, 2, '.', ''),
                    'tax' => (string) number_format($taxAmount, 2, '.', ''),
                    'discount' => (string) number_format($discountAmount, 2, '.', ''),
                    'total' => (string) number_format($totalAmount > 0 ? $totalAmount : $itemsSum, 2, '.', ''),
                    'customer_email' => (string) $customerEmail,
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
        $payload = $request->all();
        Log::info("ZSI Pay Webhook Received: ", $payload);

        try {
            $eventType = $payload['type'] ?? $request->input('event_type') ?? null;
            $data = $payload['data'] ?? [];
            $paymentStatus = strtolower($data['payment_status'] ?? $payload['payment_status'] ?? '');

            $metadata = $data['metadata'] ?? $payload['metadata'] ?? [];
            $orderId = $metadata['order_id'] ?? $payload['order_id'] ?? null;
            $orderNumber = $metadata['order_number'] ?? $payload['order_number'] ?? null;
            $transactionId = $data['provider_transaction_id'] 
                ?? $data['session_token'] 
                ?? $data['session_id'] 
                ?? $data['invoice_id'] 
                ?? null;

            if (!$orderId && !$orderNumber) {
                Log::warning("ZSI Pay Webhook Missing Order Identifiers in metadata.");
                return response()->json(['success' => false, 'message' => 'Missing order identifiers'], 400);
            }

            // Find matching Order by order_id or order_number
            $order = \App\Models\Order::where(function ($query) use ($orderId, $orderNumber) {
                if ($orderId) {
                    $query->where('id', $orderId);
                }
                if ($orderNumber) {
                    $query->orWhere('order_number', $orderNumber);
                }
            })->first();

            if (!$order) {
                Log::warning("ZSI Pay Webhook: Order not found for order_id: {$orderId}, order_number: {$orderNumber}");
                return response()->json(['success' => false, 'message' => 'Order not found'], 404);
            }

            // Check if event is payment.succeeded or payment_status is succeeded
            if ($eventType === 'payment.succeeded' || $paymentStatus === 'succeeded' || $paymentStatus === 'paid') {
                $order->update([
                    'payment_status' => 'paid',
                    'status' => 'processing',
                    'transaction_id' => $transactionId ?: ($order->transaction_id ?: $orderNumber),
                    'payment_details' => array_merge($order->payment_details ?? [], [
                        'zsi_pay_webhook_event' => $eventType,
                        'zsi_pay_session_id' => $data['session_id'] ?? null,
                        'zsi_pay_provider_tx_id' => $data['provider_transaction_id'] ?? null,
                        'paid_at' => $data['paid_at'] ?? now()->toIso8601String(),
                    ])
                ]);

                // Create status history record if OrderStatusHistory exists
                if (class_exists(\App\Models\OrderStatusHistory::class)) {
                    try {
                        \App\Models\OrderStatusHistory::create([
                            'order_id' => $order->id,
                            'status' => 'processing',
                            'comment' => 'Payment succeeded via Golden Mark Money Gateway (ZSI Pay).'
                        ]);
                    } catch (\Exception $historyErr) {
                        Log::warning("Failed to create OrderStatusHistory: " . $historyErr->getMessage());
                    }
                }

                // Clear user cart if user_id is set
                if ($order->user_id) {
                    try {
                        \App\Models\Cart::where('user_id', $order->user_id)->delete();
                        Log::info("Cart cleared for user_id {$order->user_id} via ZSI Pay webhook.");
                    } catch (\Exception $cartErr) {
                        Log::warning("Failed to clear cart: " . $cartErr->getMessage());
                    }
                }

                Log::info("Order #{$order->id} ({$order->order_number}) successfully marked as PAID via ZSI Pay Webhook!");

                return response()->json([
                    'success' => true,
                    'message' => 'Payment successfully recorded for Order #' . $order->order_number
                ]);
            }

            Log::info("ZSI Pay Webhook event '{$eventType}' / status '{$paymentStatus}' logged for Order #{$order->id}.");
            return response()->json(['success' => true, 'message' => 'Webhook logged']);

        } catch (\Exception $e) {
            Log::error("ZSI Pay Webhook Exception: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'success' => false,
                'message' => 'Error processing webhook: ' . $e->getMessage()
            ], 500);
        }
    }
}
