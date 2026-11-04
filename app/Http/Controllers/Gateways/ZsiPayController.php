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
     * Resolve the clean payments endpoint (guarantees ending in /v1/payments)
     */
    private function resolvePaymentsEndpoint(array $settings, ?string $overrideUrl = null): string
    {
        $raw = trim($overrideUrl ?: ($settings['zsi_pay_base_url'] ?? env('NEXT_PUBLIC_ZSI_PAY_BASE_URL', 'https://api-pay.zsi.ai/v1/payments')));
        if (empty($raw)) {
            return 'https://api-pay.zsi.ai/v1/payments';
        }
        $trimmed = rtrim($raw, '/');
        if (str_ends_with($trimmed, '/v1/payments')) {
            return $trimmed;
        }
        return $trimmed . '/v1/payments';
    }

    /**
     * Resolve the clean server root URL (e.g. https://api-pay.zsi.ai or http://localhost:5000) for legacy fallbacks
     */
    private function resolveServerBase(array $settings, ?string $overrideUrl = null): string
    {
        $endpoint = $this->resolvePaymentsEndpoint($settings, $overrideUrl);
        $parsed = parse_url($endpoint);
        if (!empty($parsed['scheme']) && !empty($parsed['host'])) {
            $port = !empty($parsed['port']) ? ':' . $parsed['port'] : '';
            return rtrim($parsed['scheme'] . '://' . $parsed['host'] . $port, '/');
        }
        return 'https://api-pay.zsi.ai';
    }

    /**
     * Create a Golden Mark / ZSI Pay Checkout Session & URL.
     * Uses the latest /v1/payments endpoint from zsi-pay-server.
     */
    public function checkout(Request $request)
    {
        try {
            // Load ZSI Pay Gateway settings dynamically from site_settings DB table
            $settings = SiteSetting::where('group', 'payments')
                ->where('key', 'like', 'zsi_pay_%')
                ->pluck('value', 'key')
                ->toArray();

            $isTest = $request->boolean('is_test', false);
            $enabled = filter_var($settings['zsi_pay_enabled'] ?? '0', FILTER_VALIDATE_BOOLEAN) || ($settings['zsi_pay_enabled'] ?? '0') === '1';
            
            if (!$enabled && !$isTest) {
                return response()->json([
                    'success' => false,
                    'message' => 'ZSI Pay is currently disabled.'
                ], 400);
            }

            $apiKey = trim($request->input('api_key') ?: ($settings['zsi_pay_api_key'] ?? ''));
            $apiSecret = trim($request->input('api_secret') ?: ($settings['zsi_pay_api_secret'] ?? ''));

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

            // Format items strictly conforming to zsi-pay-server /v1/payments schema:
            // price must be an integer, quantity must be positive integer.
            $v1Items = [];
            $itemsSum = 0;
            if (!empty($items) && is_array($items)) {
                foreach ($items as $idx => $item) {
                    $itemPrice = (int) max(1, round((float)($item['price'] ?? 1)));
                    $itemQty = max(1, (int) ($item['quantity'] ?? 1));
                    $itemsSum += ($itemPrice * $itemQty);

                    $v1Items[] = [
                        'id' => (string) ($item['id'] ?? $item['product_id'] ?? $idx + 1),
                        'name' => substr((string) ($item['name'] ?? $item['product_name'] ?? 'Product Item'), 0, 200),
                        'quantity' => $itemQty,
                        'price' => $itemPrice,
                    ];
                }
            } else {
                $fallbackPrice = (int) max(1, round($totalAmount > 0 ? $totalAmount : 1));
                $v1Items[] = [
                    'id' => '1',
                    'name' => 'Order ' . ($orderNumber ?: 'Payment'),
                    'quantity' => 1,
                    'price' => $fallbackPrice,
                ];
                $itemsSum = $fallbackPrice;
            }

            // Adjust target total so items sum matches total amount exactly
            $targetIntTotal = (int) max(1, round($totalAmount > 0 ? $totalAmount : $itemsSum));
            if ($targetIntTotal > $itemsSum) {
                $diff = $targetIntTotal - $itemsSum;
                if ($diff > 0) {
                    $v1Items[] = [
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
                'order_id' => (string) $orderId,
                'order_number' => (string) $orderNumber,
                'merchant_transaction_id' => (string) $orderNumber,
                'subtotal' => $subtotalAmount,
                'shipping' => $shippingCost,
                'tax' => $taxAmount,
                'discount' => $discountAmount,
                'total' => $totalAmount,
                'currency' => 'USD',
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
            ], (array) $request->input('metadata', []));

            $headers = [
                'x-zsi-api-key' => $apiKey,
                'x-zsi-api-secret' => $apiSecret,
                'api_key' => $apiKey,
                'api_secret' => $apiSecret,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ];

            $gatewayUrl = $request->input('gateway_url');
            $payServerBase = $this->resolveServerBase($settings, $gatewayUrl);
            $v1PaymentsUrl = $this->resolvePaymentsEndpoint($settings, $gatewayUrl);

            // PRIMARY SYSTEM (LATEST SYSTEM ADDED IN ZSI-PAY-SERVER): POST /v1/payments
            $v1PaymentsPayload = [
                'customer_email' => (string) $customerEmail,
                'success_url' => (string) $successUrl,
                'cancel_url' => (string) $cancelUrl,
                'merchant_transaction_id' => (string) $orderNumber,
                'items' => $v1Items,
                'metadata' => $metadata,
            ];
            Log::info("ZSI Pay Checkout Requesting /v1/payments: ", [
                'url' => $v1PaymentsUrl,
                'payload' => $v1PaymentsPayload
            ]);

            $response = Http::withHeaders($headers)->post($v1PaymentsUrl, $v1PaymentsPayload);
            $responseData = $response->json();

            Log::info("ZSI Pay /v1/payments Response status {$response->status()}: ", ['response' => $responseData]);

            $checkoutUrl = $responseData['data']['payment_url']
                ?? $responseData['data']['checkout_url']
                ?? $responseData['data']['url']
                ?? $responseData['payment_url']
                ?? $responseData['checkout_url']
                ?? $responseData['url']
                ?? null;

            $trackingId = $responseData['data']['tracking_id'] ?? null;
            $merchantTxId = $responseData['data']['merchant_transaction_id'] ?? $orderNumber;

            // SECONDARY FALLBACK (LEGACY SYSTEM): If /v1/payments failed, attempt legacy /api/v1/pay
            if (!$checkoutUrl || !$response->successful()) {
                Log::warning("ZSI Pay /v1/payments did not return checkout URL, attempting legacy /api/v1/pay fallback...");

                $legacyPayload = [
                    'currency' => 'USD',
                    'customer_email' => (string) $customerEmail,
                    'items' => $v1Items,
                    'metadata' => $metadata,
                ];

                $legacyResponse = Http::withHeaders($headers)->post($payServerBase . '/api/v1/pay', $legacyPayload);
                $legacyData = $legacyResponse->json();

                if ($legacyResponse->successful()) {
                    $checkoutUrl = $legacyData['data']['checkout_url']
                        ?? $legacyData['data']['payment_url']
                        ?? $legacyData['data']['url']
                        ?? $legacyData['checkout_url']
                        ?? $legacyData['payment_url']
                        ?? null;
                    if ($checkoutUrl) {
                        $responseData = $legacyData;
                    }
                }
            }

            if ($checkoutUrl) {
                // Link order transaction ID if order exists
                if ($orderId || $orderNumber) {
                    \App\Models\Order::where(function ($q) use ($orderId, $orderNumber) {
                        if ($orderId) $q->where('id', $orderId);
                        if ($orderNumber) $q->orWhere('order_number', $orderNumber);
                    })->update([
                        'transaction_id' => $trackingId ?: $orderNumber,
                    ]);
                }

                return response()->json([
                    'success' => true,
                    'url' => $checkoutUrl,
                    'payment_url' => $checkoutUrl,
                    'checkout_url' => $checkoutUrl,
                    'tracking_id' => $trackingId,
                    'merchant_transaction_id' => $merchantTxId,
                    'data' => [
                        'url' => $checkoutUrl,
                        'payment_url' => $checkoutUrl,
                        'checkout_url' => $checkoutUrl,
                        'tracking_id' => $trackingId,
                        'merchant_transaction_id' => $merchantTxId,
                    ]
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
            $paymentStatus = strtolower($data['payment_status'] ?? $data['status'] ?? $payload['payment_status'] ?? '');

            $metadata = $data['metadata'] ?? $payload['metadata'] ?? [];
            $orderId = $metadata['order_id'] ?? $payload['order_id'] ?? null;
            $orderNumber = $metadata['order_number'] ?? $data['merchant_transaction_id'] ?? $payload['order_number'] ?? $payload['merchant_transaction_id'] ?? null;
            $transactionId = $data['provider_transaction_id'] 
                ?? $data['tracking_id'] 
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

            // Check if event is payment.succeeded or merchant.payment.succeeded or paymentStatus is succeeded/paid/completed
            $isSuccessEvent = in_array($eventType, ['payment.succeeded', 'merchant.payment.succeeded']) ||
                              in_array($paymentStatus, ['succeeded', 'paid', 'completed']);

            if ($isSuccessEvent) {
                $order->update([
                    'payment_status' => 'paid',
                    'status' => 'processing',
                    'transaction_id' => $transactionId ?: ($order->transaction_id ?: $orderNumber),
                    'payment_details' => array_merge($order->payment_details ?? [], [
                        'zsi_pay_webhook_event' => $eventType,
                        'zsi_pay_session_id' => $data['session_id'] ?? null,
                        'zsi_pay_tracking_id' => $data['tracking_id'] ?? null,
                        'zsi_pay_merchant_tx_id' => $orderNumber,
                        'paid_at' => $data['paid_at'] ?? now()->toIso8601String(),
                    ])
                ]);

                // Create status history record if OrderStatusHistory exists
                if (class_exists(\App\Models\OrderStatusHistory::class)) {
                    try {
                        \App\Models\OrderStatusHistory::create([
                            'order_id' => $order->id,
                            'status' => 'processing',
                            'comment' => 'Payment succeeded via ZSI Pay.'
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
     * Verify & track ZSI Pay session status by trackId or merchant_transaction_id.
     * Checks the latest /v1/payments/:merchant_transaction_id endpoint first.
     */
    public function verifySession(Request $request, $trackId)
    {
        try {
            Log::info("ZSI Pay verifySession requested for trackId: {$trackId}");

            $settings = SiteSetting::where('group', 'payments')
                ->where('key', 'like', 'zsi_pay_%')
                ->pluck('value', 'key')
                ->toArray();

            $payServerBase = $this->resolveServerBase($settings);
            $apiKey = trim($settings['zsi_pay_api_key'] ?? '');
            $apiSecret = trim($settings['zsi_pay_api_secret'] ?? '');

            $headers = [
                'Accept' => 'application/json',
                'x-zsi-api-key' => $apiKey,
                'x-zsi-api-secret' => $apiSecret,
                'api_key' => $apiKey,
                'api_secret' => $apiSecret,
            ];

            $responseData = null;
            $httpSuccess = false;
            $rawStatus = '';

            // Step 1: Check LATEST SYSTEM route: GET /v1/payments/:merchant_transaction_id
            $v1PaymentsGetUrl = $this->resolvePaymentsEndpoint($settings) . '/' . urlencode($trackId);
            try {
                $res = Http::withHeaders($headers)->get($v1PaymentsGetUrl);
                if ($res->successful()) {
                    $responseData = $res->json();
                    $httpSuccess = true;
                    $sessionData = $responseData['data'] ?? $responseData;
                    $rawStatus = strtolower($sessionData['status'] ?? $sessionData['payment_status'] ?? '');
                } else {
                    Log::warning("ZSI Pay /v1/payments GET status {$res->status()}: ", ['response' => $res->json()]);
                }
            } catch (\Exception $v1Err) {
                Log::warning("ZSI Pay /v1/payments GET exception: " . $v1Err->getMessage());
            }

            // Step 2: Fallback to LEGACY SYSTEM: GET /api/v1/session/track/:trackId
            if (!$httpSuccess || empty($rawStatus)) {
                $legacyEndpoints = [
                    $payServerBase . '/api/v1/session/track/' . urlencode($trackId),
                    $payServerBase . '/session/track/' . urlencode($trackId),
                ];

                foreach (array_unique($legacyEndpoints) as $endpoint) {
                    try {
                        $res = Http::withHeaders($headers)->get($endpoint);
                        if ($res->successful()) {
                            $responseData = $res->json();
                            $httpSuccess = true;
                            $sessionData = $responseData['data'] ?? $responseData;
                            $rawStatus = strtolower($sessionData['payment_status'] ?? $sessionData['status'] ?? $sessionData['state'] ?? '');
                            break;
                        }
                    } catch (\Exception $e) {
                        Log::warning("ZSI Pay legacy track exception on {$endpoint}: " . $e->getMessage());
                    }
                }
            }

            Log::info("ZSI Pay Track Final Result for {$trackId}:", ['httpSuccess' => $httpSuccess, 'status' => $rawStatus, 'data' => $responseData]);

            $sessionData = $responseData['data'] ?? $responseData ?? [];
            if (empty($rawStatus)) {
                $rawStatus = strtolower(
                    $sessionData['status'] ?? 
                    $sessionData['payment_status'] ?? 
                    $sessionData['state'] ?? 
                    ''
                );
            }

            $metadata = $sessionData['metadata'] ?? [];
            $orderId = $metadata['order_id'] ?? $request->input('order_id') ?? null;
            $orderNumber = $metadata['order_number'] ?? $sessionData['merchant_transaction_id'] ?? $request->input('order_number') ?? null;

            // Find matching Order by order_id, order_number, or numeric trackId
            $order = \App\Models\Order::where(function ($query) use ($orderId, $orderNumber, $trackId) {
                if ($orderId) {
                    $query->where('id', $orderId);
                }
                if ($orderNumber) {
                    $query->orWhere('order_number', $orderNumber);
                }
                if ($trackId) {
                    $query->orWhere('order_number', $trackId)
                          ->orWhere('transaction_id', $trackId);
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
                        'transaction_id' => $sessionData['tracking_id'] ?? $trackId,
                        'payment_details' => array_merge($order->payment_details ?? [], [
                            'zsi_pay_track_id' => $trackId,
                            'verified_at' => now()->toIso8601String(),
                            'zsi_pay_status' => $rawStatus,
                            'provider' => $sessionData['payment']['provider'] ?? null,
                            'method' => $sessionData['payment']['method'] ?? null,
                        ])
                    ]);

                    if (class_exists(\App\Models\OrderStatusHistory::class)) {
                        try {
                            \App\Models\OrderStatusHistory::create([
                                'order_id' => $order->id,
                                'status' => 'processing',
                                'comment' => 'Payment verified via ZSI Pay /v1/payments API.'
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
