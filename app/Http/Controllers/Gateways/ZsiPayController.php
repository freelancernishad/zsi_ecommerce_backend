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
                    'message' => 'Zsi pay is currently disabled.'
                ], 400);
            }

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
            $orderNumber = $request->input('order_number') ?: ($request->input('merchant_transaction_id') ?: ('ORD-' . ($orderId ?: time())));
            $totalAmount = (float) $request->input('total', 0);
            $subtotalAmount = (float) $request->input('subtotal', 0);
            $shippingCost = (float) $request->input('shipping_cost', 0);
            $taxAmount = (float) $request->input('tax', 0);
            $discountAmount = (float) $request->input('discount', 0);

            // Format items strictly conforming to zsi-pay-server validation schema
            $formattedItems = [];
            $itemsSum = 0.0;
            if (!empty($items) && is_array($items)) {
                foreach ($items as $idx => $item) {
                    $itemPrice = (float) ($item['price'] ?? 1.0);
                    if ($itemPrice <= 0) $itemPrice = 1.0;
                    $itemQty = max(1, (int) ($item['quantity'] ?? 1));
                    $itemsSum += ($itemPrice * $itemQty);

                    $formattedItems[] = [
                        'id' => (string) ($item['id'] ?? $item['product_id'] ?? $idx + 1),
                        'name' => substr((string) ($item['name'] ?? $item['product_name'] ?? 'Product Item'), 0, 200),
                        'quantity' => $itemQty,
                        'price' => round($itemPrice, 2),
                    ];
                }
            } else {
                $fallbackPrice = round($totalAmount > 0 ? $totalAmount : 1.0, 2);
                $formattedItems[] = [
                    'id' => '1',
                    'name' => 'Order ' . ($orderNumber ?: 'Payment'),
                    'quantity' => 1,
                    'price' => $fallbackPrice,
                ];
                $itemsSum = $fallbackPrice;
            }

            // Target total adjustment so items sum matches total amount exactly
            $targetTotal = round($totalAmount > 0 ? $totalAmount : $itemsSum, 2);
            if ($targetTotal > $itemsSum) {
                $diff = round($targetTotal - $itemsSum, 2);
                if ($diff > 0) {
                    $formattedItems[] = [
                        'id' => 'shipping_and_tax',
                        'name' => 'Shipping & Taxes',
                        'quantity' => 1,
                        'price' => $diff,
                    ];
                }
            }

            $clientOrigin = $request->header('origin') ?: config('app.frontend_url', 'http://localhost:3000');
            $successUrl = $request->input('success_url') ?: (rtrim($clientOrigin, '/') . '/checkout/success?merchant_transaction_id=' . urlencode((string)$orderNumber) . '&provider=zsipay');
            $cancelUrl = $request->input('cancel_url') ?: (rtrim($clientOrigin, '/') . '/checkout?cancelled=1');

            $metadata = array_merge([
                'merchant_transaction_id' => (string) $orderNumber,
                'order_id' => (string) $orderId,
                'order_number' => (string) $orderNumber,
                'subtotal' => $subtotalAmount,
                'shipping' => $shippingCost,
                'tax' => $taxAmount,
                'discount' => $discountAmount,
                'total' => $targetTotal,
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
            ], (array) $request->input('metadata', []));

            $headers = [
                'api_key' => $apiKey,
                'api_secret' => $apiSecret,
                'x-zsi-api-key' => $apiKey,
                'x-zsi-api-secret' => $apiSecret,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ];

            // Resolve server host base URL
            $payServerBase = 'https://api-pay.zsi.ai';
            if (!empty($settings['zsi_pay_base_url'])) {
                $parsed = parse_url($settings['zsi_pay_base_url']);
                if (!empty($parsed['scheme']) && !empty($parsed['host'])) {
                    $payServerBase = $parsed['scheme'] . '://' . $parsed['host'];
                }
            }

            // Attempt 1: Call /api/v1/pay (Universal session creation in zsi-pay-server)
            $payPayload = [
                'currency' => 'USD',
                'customer_email' => (string) $customerEmail,
                'items' => $formattedItems,
                'metadata' => $metadata,
            ];

            $response = Http::withHeaders($headers)->post($payServerBase . '/api/v1/pay', $payPayload);
            $responseData = $response->json();

            $checkoutUrl = $responseData['data']['checkout_url']
                ?? $responseData['data']['payment_url']
                ?? $responseData['checkout_url']
                ?? $responseData['payment_url']
                ?? null;

            // Attempt 2: If /api/v1/pay did not return a checkout URL, fallback to /v1/payments
            if (!$checkoutUrl || !$response->successful()) {
                $v1PaymentsPayload = [
                    'customer_email' => (string) $customerEmail,
                    'success_url' => (string) $successUrl,
                    'cancel_url' => (string) $cancelUrl,
                    'merchant_transaction_id' => (string) $orderNumber,
                    'items' => array_map(function ($item) {
                        return [
                            'id' => (string) $item['id'],
                            'name' => (string) $item['name'],
                            'quantity' => (int) $item['quantity'],
                            'price' => (int) max(1, round($item['price'])),
                        ];
                    }, $formattedItems),
                    'metadata' => $metadata,
                ];

                $v1Response = Http::withHeaders($headers)->post($payServerBase . '/v1/payments', $v1PaymentsPayload);
                $v1Data = $v1Response->json();

                if ($v1Response->successful() && !empty($v1Data['data']['payment_url'])) {
                    $checkoutUrl = $v1Data['data']['payment_url'];
                    $responseData = $v1Data;
                }
            }

            if ($checkoutUrl) {
                return response()->json([
                    'success' => true,
                    'url' => $checkoutUrl,
                    'payment_url' => $checkoutUrl,
                    'checkout_url' => $checkoutUrl,
                    'data' => $responseData['data'] ?? ['checkout_url' => $checkoutUrl]
                ]);
            }

            $errorMessage = $responseData['message'] ?? $responseData['error'] ?? 'Failed to initialize Zsi pay checkout URL.';
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
                            'comment' => 'Payment succeeded via Zsi pay.'
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

    /**
     * Verify & track ZSI Pay session status by trackId.
     */
    public function verifySession(Request $request, $trackId)
    {
        try {
            Log::info("ZSI Pay verifySession requested for trackId: {$trackId}");

            $settings = SiteSetting::where('group', 'payments')
                ->where('key', 'like', 'zsi_pay_%')
                ->pluck('value', 'key')
                ->toArray();

            $baseUrl = rtrim($settings['zsi_pay_base_url'] ?? (env('NEXT_PUBLIC_ZSI_PAY_BASE_URL') ?: 'https://api-pay.zsi.ai/api/v1'), '/');
            $apiKey = $settings['zsi_pay_api_key'] ?? '';
            $apiSecret = $settings['zsi_pay_api_secret'] ?? '';

            // Endpoints to try: configured base_url track endpoint AND production api-pay.zsi.ai track endpoint
            $endpointsToTry = [
                $baseUrl . '/session/track/' . $trackId,
                'https://api-pay.zsi.ai/api/v1/session/track/' . $trackId,
            ];

            $responseData = null;
            $httpSuccess = false;

            foreach (array_unique($endpointsToTry) as $endpoint) {
                try {
                    $headers = [
                        'Accept' => 'application/json',
                    ];
                    if (!empty($apiKey)) $headers['api_key'] = $apiKey;
                    if (!empty($apiSecret)) $headers['api_secret'] = $apiSecret;

                    $res = Http::withHeaders($headers)->get($endpoint);

                    if ($res->successful()) {
                        $responseData = $res->json();
                        $httpSuccess = true;
                        break;
                    } else {
                        Log::warning("ZSI Pay track API status {$res->status()} on {$endpoint}: ", ['response' => $res->json()]);
                        if (!$responseData) {
                            $responseData = $res->json();
                        }
                    }
                } catch (\Exception $httpErr) {
                    Log::warning("ZSI Pay track HTTP exception on {$endpoint}: " . $httpErr->getMessage());
                }
            }

            Log::info("ZSI Pay Track Final Result for {$trackId}:", ['httpSuccess' => $httpSuccess, 'data' => $responseData]);

            $sessionData = $responseData['data'] ?? $responseData ?? [];
            $rawStatus = strtolower(
                $sessionData['payment_status'] ?? 
                $sessionData['status'] ?? 
                $sessionData['state'] ?? 
                ''
            );

            $metadata = $sessionData['metadata'] ?? [];
            $orderId = $metadata['order_id'] ?? $request->input('order_id') ?? null;
            $orderNumber = $metadata['order_number'] ?? $request->input('order_number') ?? null;

            // Find matching Order by order_id, order_number, or numeric trackId
            $order = \App\Models\Order::where(function ($query) use ($orderId, $orderNumber, $trackId) {
                if ($orderId) {
                    $query->where('id', $orderId);
                }
                if ($orderNumber) {
                    $query->orWhere('order_number', $orderNumber);
                }
                if (is_numeric($trackId)) {
                    $query->orWhere('id', $trackId);
                }
            })->with(['items.product.images', 'items.variant'])->first();

            // Fallback: If not found by metadata, search order where transaction_id = trackId or search recent order
            if (!$order) {
                $order = \App\Models\Order::where('transaction_id', $trackId)
                    ->with(['items.product.images', 'items.variant'])
                    ->first();
            }

            $authUser = auth('api')->user() ?? \Illuminate\Support\Facades\Auth::user();
            if (!$order && $authUser) {
                $order = \App\Models\Order::where('user_id', $authUser->id)
                    ->latest()
                    ->with(['items.product.images', 'items.variant'])
                    ->first();
            }

            if (!$order) {
                $order = \App\Models\Order::latest()->with(['items.product.images', 'items.variant'])->first();
            }

            if (!$order) {
                return response()->json([
                    'success' => false,
                    'is_paid' => false,
                    'message' => 'Order not found for trackId: ' . $trackId
                ], 404);
            }

            $isSuccessful = in_array($rawStatus, ['completed', 'succeeded', 'paid', 'success', 'approved']);
            $isFailed = in_array($rawStatus, ['failed', 'cancelled', 'canceled', 'declined', 'expired']);

            if ($isSuccessful) {
                if ($order->payment_status !== 'paid') {
                    $order->update([
                        'payment_status' => 'paid',
                        'status' => 'processing',
                        'transaction_id' => $trackId,
                        'payment_details' => array_merge($order->payment_details ?? [], [
                            'zsi_pay_track_id' => $trackId,
                            'verified_at' => now()->toIso8601String(),
                            'zsi_pay_status' => $rawStatus,
                        ])
                    ]);

                    if (class_exists(\App\Models\OrderStatusHistory::class)) {
                        try {
                            \App\Models\OrderStatusHistory::create([
                                'order_id' => $order->id,
                                'status' => 'processing',
                                'comment' => 'Payment verified via ZSI Pay Track API.'
                            ]);
                        } catch (\Exception $e) {}
                    }

                    if ($order->user_id) {
                        try {
                            \App\Models\Cart::where('user_id', $order->user_id)->delete();
                        } catch (\Exception $e) {}
                    }
                }

                return response()->json([
                    'success' => true,
                    'is_paid' => true,
                    'status' => 'paid',
                    'message' => 'Payment completed successfully.',
                    'order' => $order,
                    'track_data' => $sessionData
                ]);
            } elseif ($isFailed) {
                if ($order->payment_status !== 'failed' && $order->payment_status !== 'paid') {
                    $order->update([
                        'payment_status' => 'failed',
                        'status' => 'cancelled',
                        'payment_details' => array_merge($order->payment_details ?? [], [
                            'zsi_pay_track_id' => $trackId,
                            'verified_at' => now()->toIso8601String(),
                            'zsi_pay_status' => $rawStatus,
                        ])
                    ]);
                }

                return response()->json([
                    'success' => true,
                    'is_paid' => false,
                    'status' => 'failed',
                    'message' => 'Payment was failed or cancelled by user.',
                    'order' => $order,
                    'track_data' => $sessionData
                ]);
            } else {
                // If API didn't return an explicit failed status, check order's current payment_status
                $isPaid = ($order->payment_status === 'paid');
                return response()->json([
                    'success' => true,
                    'is_paid' => $isPaid,
                    'status' => $order->payment_status ?: 'pending',
                    'message' => $isPaid ? 'Payment completed.' : 'Payment status: ' . ($rawStatus ?: $order->payment_status),
                    'order' => $order,
                    'track_data' => $sessionData
                ]);
            }

        } catch (\Exception $e) {
            Log::error("ZSI Pay verifySession Exception: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error verifying payment: ' . $e->getMessage()
            ], 500);
        }
    }
}
