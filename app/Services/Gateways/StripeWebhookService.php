<?php

namespace App\Services\Gateways;

use App\Events\StripePaymentEvent;
use App\Services\TenantDatabaseManager;
use Stripe\Webhook;
use Stripe\Stripe;
use Stripe\Exception\SignatureVerificationException;
use App\Models\StripeLog;
use Illuminate\Support\Facades\Log;

class StripeWebhookService
{
    public function handleWebhook($payload, $sigHeader)
    {
        $rawPayload = json_decode((string) $payload, true);
        if (is_array($rawPayload)) {
            $tenantMeta = TenantDatabaseManager::extractTenantMetadataFromPayload($rawPayload);
            TenantDatabaseManager::switchByDomainOrDatabase(
                $tenantMeta['tenant_domain'],
                $tenantMeta['tenant_db']
            );
        }

        $secretKey = \App\Models\SiteSetting::where('key', 'stripe_secret_key')->value('value')
            ?: config('services.stripe.secret');
        if ($secretKey) {
            Stripe::setApiKey($secretKey);
        }

        $endpointSecret = \App\Models\SiteSetting::where('key', 'stripe_webhook_secret')->value('value');
        if (!$endpointSecret) {
            try {
                $endpointSecret = \App\Models\SystemSetting::where('key', 'STRIPE_WEBHOOK_SECRET')->value('value');
            } catch (\Exception $e) {
                Log::error('Webhook Fallback DB Error: ' . $e->getMessage());
            }
        }
        $endpointSecret = $endpointSecret ?: config('services.stripe.webhook');

        Log::info('Stripe Webhook Heartbeat', [
            'url' => request()->fullUrl(),
            'method' => request()->method(),
            'ip' => request()->ip(),
            'cf_ip' => request()->header('CF-Connecting-IP'),
            'tenant_db' => TenantDatabaseManager::currentDatabase(),
            'signature_present' => !!$sigHeader,
            'payload_length' => strlen($payload),
            'secret_configured' => $endpointSecret ? 'Yes (starts with ' . substr($endpointSecret, 0, 8) . '...)' : 'No'
        ]);

        try {
            $event = Webhook::constructEvent(
                $payload, $sigHeader, $endpointSecret
            );
        } catch(\UnexpectedValueException $e) {
            // Invalid payload
            Log::error('Stripe Webhook Error: Invalid payload');
            return response()->json(['error' => 'Invalid payload'], 400);
        } catch(SignatureVerificationException $e) {
            // Invalid signature
            Log::error('Stripe Webhook Error: Invalid signature');
            return response()->json(['error' => 'Invalid signature'], 400);
        }

        // Handle the event
        switch ($event->type) {
            case 'checkout.session.completed':
            case 'checkout.session.async_payment_succeeded':
                $this->handleCheckoutSessionCompleted($event->data->object, $event->type);
                break;
            case 'payment_intent.succeeded':
                $this->handlePaymentIntentSucceeded($event->data->object, $event->type);
                break;
            case 'payment_intent.processing':
                $this->handlePaymentIntentProcessing($event->data->object, $event->type);
                break;
            case 'payment_intent.payment_failed':
                $this->handlePaymentIntentFailed($event->data->object, $event->type);
                break;
            case 'invoice.payment_succeeded':
                $this->handleInvoicePaymentSucceeded($event->data->object, $event->type);
                break;
            case 'customer.subscription.deleted':
                $this->handleSubscriptionDeleted($event->data->object, $event->type);
                break;
            case 'setup_intent.succeeded':
                $this->handleSetupIntentSucceeded($event->data->object, $event->type);
                break;
            case 'payment_method.detached':
                $this->handlePaymentMethodDetached($event->data->object, $event->type);
                break;
            // Add other event types as needed
            default:
                Log::info('Received unknown event type ' . $event->type);
                // Still dispatch event for unknown types so listeners can handle if needed
                 StripePaymentEvent::dispatch($event->type, $event->data->object->toArray(), 'received');
        }

        return response()->json(['status' => 'success']);
    }

    protected function handleCheckoutSessionCompleted($session, $eventType)
    {
        $log = StripeLog::where('session_id', $session->id)->first();

        if ($log) {
            $updateData = [
                'status' => $session->payment_status, // paid, unpaid, no_payment_required
                'payment_intent_id' => $session->payment_intent,
                'subscription_id' => $session->subscription, // Save subscription ID if available
                'payload' => array_merge($log->payload ?? [], ['webhook_event' => $eventType, 'session_details' => $session->toArray()]),
            ];

            // If this is a subscription checkout, retrieve details to set next_payment_date
            if ($session->subscription) {
                try {
                    $subscription = \Stripe\Subscription::retrieve($session->subscription);
                    
                    // Log::info("Webhook Subscription Object Dump:", $subscription->toArray()); // Commented out to reduce noise

                    $nextPaymentDate = null;

                    // Try top-level current_period_end
                    if (isset($subscription->current_period_end)) {
                         $nextPaymentDate = $subscription->current_period_end;
                    } 
                    // Fallback: Try first subscription item's current_period_end
                    elseif (isset($subscription->items->data[0]->current_period_end)) {
                         $nextPaymentDate = $subscription->items->data[0]->current_period_end;
                    }

                    $updateData['next_payment_date'] = $nextPaymentDate 
                        ? date('Y-m-d H:i:s', $nextPaymentDate) 
                        : null;

                    $updateData['next_payment_status'] = 'scheduled';
                    $updateData['product_name'] = $log->product_name; 
                } catch (\Exception $e) {
                    Log::error("Failed to retrieve subscription in webhook: " . $e->getMessage());
                }
            }

            $log->update($updateData);
            Log::info("StripeLog updated for session: {$session->id}");

            // Clear database cart items for the user upon successful payment
            if ($log->user_id) {
                try {
                    \App\Models\Cart::where('user_id', $log->user_id)->delete();
                    Log::info("Backend Cart cleared for user_id {$log->user_id} via checkout.session.completed webhook.");
                } catch (\Exception $e) {
                    Log::error("Failed to clear backend cart in webhook: " . $e->getMessage());
                }
            }
            
            // Dispatch generic event
            StripePaymentEvent::dispatch($eventType, $session->toArray(), 'success', $log->user_id);

            // --- Send Global Notification & Email (only for plan/subscription purchases) ---
            if ($log->user_id && ($log->plan_id || $session->mode === 'subscription')) {
                $user = \App\Models\User::find($log->user_id);
                if ($user) {
                    $planName = $log->product_name ?? 'Subscription';
                    $amount = number_format($session->amount_total / 100, 2);
                    
                    send_notification(
                        $user,
                        "Your purchase of {$planName} was successful.",
                        "Purchase Confirmation: {$planName}",
                        'emails.plans.purchase_confirmation',
                        [
                            'user_name' => $user->name,
                            'plan_name' => $planName,
                            'interval' => $session->mode === 'subscription' ? ($log->interval ?? 'Recurring') : 'One-Time',
                            'start_date' => now()->format('F j, Y'),
                            'next_payment_date' => $updateData['next_payment_date'] ?? 'N/A',
                            'amount' => $amount,
                        ],
                        'PlanSubscription',
                        $session->subscription ?? $session->id
                    );
                    Log::info("Global notification sent to user {$user->id} for purchase of {$planName}");
                }
            }
            // ----------------------------------------
        } else {
            Log::warning("StripeLog not found for session: {$session->id}");
            // Still dispatch event, but without user_id
            StripePaymentEvent::dispatch($eventType, $session->toArray(), 'success');
        }

        // Update matching e-commerce Order even if StripeLog is absent
        $sessionMetadata = isset($session->metadata) ? (array) (is_object($session->metadata) && method_exists($session->metadata, 'toArray') ? $session->metadata->toArray() : $session->metadata) : [];
        if (empty($sessionMetadata) && $log && is_array($log->meta_data)) {
            $sessionMetadata = $log->meta_data;
        }
        $this->markOrderPaidFromStripe(
            $sessionMetadata,
            $session->payment_intent ?: $session->id,
            $eventType,
            [
                'stripe_session_id' => $session->id ?? null,
                'stripe_payment_intent_id' => $session->payment_intent ?? null,
            ]
        );
    }

    protected function handleInvoicePaymentSucceeded($invoice, $eventType)
    {
        // Prevent duplicate logs for the initial subscription payment (already handled by checkout.session.completed)
        if (isset($invoice->billing_reason) && $invoice->billing_reason === 'subscription_create') {
            Log::info("Skipping invoice.payment_succeeded for subscription creation to avoid duplicate log: {$invoice->id}");
            return;
        }

        // This event happens for subscription renewals
        // We need to create a NEW log entry for each renewal payment
        
        $user = \App\Models\User::where('stripe_id', $invoice->customer)->first();
        $userId = $user ? $user->id : null;

        // Extract detailed info
        $lineItem = $invoice->lines->data[0] ?? null;
        $planName = $lineItem ? ($lineItem->description ?? 'Unknown Subscription') : 'Unknown';
        $periodStart = isset($lineItem->period->start) ? date('Y-m-d H:i:s', $lineItem->period->start) : null;
        $periodEnd = isset($lineItem->period->end) ? date('Y-m-d H:i:s', $lineItem->period->end) : null; // Effectively the next charge date
        
        $interval = $lineItem->price->recurring->interval ?? null;
        $intervalCount = $lineItem->price->recurring->interval_count ?? null;

        $log = StripeLog::create([
            'user_id' => $userId,
            'type' => 'subscription_renewal', // Distinct type for renewals
            'stripe_customer_id' => $invoice->customer,
            'session_id' => $invoice->subscription, // Using subscription ID as session ref (legacy)
            'subscription_id' => $invoice->subscription, // Storing explicitly
            'payment_intent_id' => $invoice->payment_intent,
            'amount' => $invoice->amount_paid / 100,
            'currency' => $invoice->currency,
            'status' => $invoice->status, // paid
            'product_name' => $planName,
            'next_payment_date' => $periodEnd,
            'next_payment_status' => $periodEnd ? 'scheduled' : 'none',
            'interval' => $interval,
            'interval_count' => $intervalCount,
            'payload' => [
                'webhook_event' => $eventType, 
                'subscription_name' => $planName,
                'current_period_start' => $periodStart,
                'next_payment_date' => $periodEnd,
                'next_payment_status' => $periodEnd ? 'scheduled' : 'none',
                'interval' => $interval,
                'interval_count' => $intervalCount,
                'invoice_details' => $invoice->toArray()
            ],
        ]);
        
        Log::info("StripeLog created for subscription renewal: {$invoice->id}", ['next_payment' => $periodEnd]);

        StripePaymentEvent::dispatch($eventType, $invoice->toArray(), 'success', $userId);
    }
    
    protected function handleSubscriptionDeleted($subscription, $eventType)
    {
        // Find the latest log for this subscription to update status or create a new log
        // Ideally we should create a new event log for the cancellation action
        $user = \App\Models\User::where('stripe_id', $subscription->customer)->first();
        $userId = $user ? $user->id : null;

        $log = StripeLog::create([
            'user_id' => $userId,
            'type' => 'subscription_canceled',
            'stripe_customer_id' => $subscription->customer,
            'subscription_id' => $subscription->id,
            'status' => 'canceled',
            'next_payment_status' => 'none',
            'payload' => [
                'webhook_event' => $eventType,
                'cancellation_details' => $subscription->toArray()
            ],
        ]);
        
        Log::info("StripeLog created for subscription cancellation: {$subscription->id}");
        
        StripePaymentEvent::dispatch($eventType, $subscription->toArray(), 'canceled', $userId);
    }

    protected function handlePaymentIntentSucceeded($paymentIntent, $eventType)
    {
        // Payment intents can be associated with logs via payment_intent_id
        $log = StripeLog::where('payment_intent_id', $paymentIntent->id)->first();

        if ($log) {
            $log->update([
                'status' => 'paid', // Explicitly set to 'paid' instead of 'succeeded'
                'payload' => array_merge($log->payload ?? [], ['webhook_event' => $eventType, 'intent_details' => $paymentIntent->toArray()]),
            ]);
            Log::info("StripeLog updated for payment intent: {$paymentIntent->id}");

            // Auto sync payment method to DB if user checked saveForFuture
            $paymentMethodId = is_string($paymentIntent->payment_method) 
                ? $paymentIntent->payment_method 
                : ($paymentIntent->payment_method->id ?? null);

            if ($paymentMethodId) {
                $userId = $log->user_id ?? null;
                if (!$userId && !empty($paymentIntent->customer)) {
                    $userId = \App\Models\User::where('stripe_id', $paymentIntent->customer)->value('id');
                }
                if ($userId) {
                    try {
                        \App\Models\Cart::where('user_id', $userId)->delete();
                        Log::info("Backend Cart cleared for user_id {$userId} via payment_intent.succeeded webhook.");
                    } catch (\Exception $e) {
                        Log::error("Failed to clear backend cart in webhook: " . $e->getMessage());
                    }

                    $user = \App\Models\User::find($userId);
                    if ($user) {
                        try {
                            app(StripeService::class)->syncPaymentMethod($user, $paymentMethodId);
                            Log::info("Synced payment method {$paymentMethodId} for user {$user->id} via payment_intent.succeeded webhook.");
                        } catch (\Exception $e) {
                            Log::error("Failed to sync payment method in webhook: " . $e->getMessage());
                        }
                    }
                }
            }
             
             StripePaymentEvent::dispatch($eventType, $paymentIntent->toArray(), 'success', $log->user_id);
        } else {
             StripePaymentEvent::dispatch($eventType, $paymentIntent->toArray(), 'success');
        }

        $intentMetadata = isset($paymentIntent->metadata) ? (array) (is_object($paymentIntent->metadata) && method_exists($paymentIntent->metadata, 'toArray') ? $paymentIntent->metadata->toArray() : $paymentIntent->metadata) : [];
        $this->markOrderPaidFromStripe(
            $intentMetadata,
            $paymentIntent->id ?? null,
            $eventType,
            [
                'stripe_payment_intent_id' => $paymentIntent->id ?? null,
            ]
        );
    }

    /**
     * Find a matching e-commerce Order from Stripe metadata or transaction_id.
     */
    protected function findOrderFromStripe(array $metadata, ?string $transactionId): ?\App\Models\Order
    {
        $orderId = $metadata['order_id'] ?? null;
        $orderNumber = $metadata['order_number'] ?? null;

        $order = null;
        if ($orderNumber) {
            if ($orderId) {
                $order = \App\Models\Order::where('id', $orderId)
                    ->where('order_number', $orderNumber)
                    ->first();
            }
            if (!$order) {
                $order = \App\Models\Order::where('order_number', $orderNumber)->first();
            }
        } elseif ($orderId) {
            $order = \App\Models\Order::where('id', $orderId)->first();
        }

        if (!$order && $transactionId) {
            $order = \App\Models\Order::where('transaction_id', $transactionId)->first();
        }

        return $order;
    }

    /**
     * Mark a matching e-commerce Order as paid/processing from Stripe webhook metadata.
     */
    protected function markOrderPaidFromStripe(array $metadata, ?string $transactionId, string $eventType, array $extraDetails = []): void
    {
        $orderId = $metadata['order_id'] ?? null;
        $orderNumber = $metadata['order_number'] ?? null;

        if (!$orderId && !$orderNumber && !$transactionId) {
            return;
        }

        $order = $this->findOrderFromStripe($metadata, $transactionId);

        if (!$order) {
            Log::warning("Stripe Webhook: Order not found in DB '" . TenantDatabaseManager::currentDatabase() . "' for order_id: {$orderId}, order_number: {$orderNumber}, transaction_id: {$transactionId}");
            return;
        }

        if ($order->payment_status === 'paid') {
            return;
        }

        $existingDetails = is_array($order->payment_details) ? $order->payment_details : [];

        $order->update([
            'payment_status' => 'paid',
            'status' => 'processing',
            'transaction_id' => $transactionId ?: $order->transaction_id,
            'payment_details' => array_merge($existingDetails, $extraDetails, [
                'stripe_webhook_event' => $eventType,
                'paid_at' => now()->toIso8601String(),
            ]),
        ]);

        if (class_exists(\App\Models\OrderStatusHistory::class)) {
            try {
                \App\Models\OrderStatusHistory::create([
                    'order_id' => $order->id,
                    'status' => 'processing',
                    'description' => 'Payment succeeded via Stripe.',
                ]);
            } catch (\Exception $e) {
                Log::warning("Failed to create OrderStatusHistory in Stripe webhook: " . $e->getMessage());
            }
        }

        if ($order->user_id) {
            try {
                \App\Models\Cart::where('user_id', $order->user_id)->delete();
            } catch (\Exception $e) {
                Log::warning("Failed to clear cart in Stripe webhook: " . $e->getMessage());
            }
        }

        Log::info("Order #{$order->id} ({$order->order_number}) marked as PAID via Stripe Webhook ({$eventType}) in DB '" . TenantDatabaseManager::currentDatabase() . "'.");

        app(\App\Services\OrderEmailService::class)->sendOrderConfirmation($order);
    }

    protected function handlePaymentIntentProcessing($paymentIntent, $eventType)
    {
        $log = StripeLog::where('payment_intent_id', $paymentIntent->id)->first();

        if ($log) {
            $log->update([
                'status' => 'processing',
                'payload' => array_merge($log->payload ?? [], ['webhook_event' => $eventType, 'intent_details' => $paymentIntent->toArray()]),
            ]);
            Log::info("StripeLog updated for processing payment intent: {$paymentIntent->id}");
            StripePaymentEvent::dispatch($eventType, $paymentIntent->toArray(), 'processing', $log->user_id);
        } else {
            StripePaymentEvent::dispatch($eventType, $paymentIntent->toArray(), 'processing');
        }

        $intentMetadata = isset($paymentIntent->metadata) ? (array) (is_object($paymentIntent->metadata) && method_exists($paymentIntent->metadata, 'toArray') ? $paymentIntent->metadata->toArray() : $paymentIntent->metadata) : [];
        $order = $this->findOrderFromStripe($intentMetadata, $paymentIntent->id ?? null);
        if ($order && $order->payment_status === 'pending') {
            $order->update([
                'payment_status' => 'processing',
            ]);
        }
    }

    protected function handlePaymentIntentFailed($paymentIntent, $eventType)
    {
        $log = StripeLog::where('payment_intent_id', $paymentIntent->id)->first();

        if ($log) {
            $log->update([
                'status' => $paymentIntent->status, // requires_payment_method, etc.
                'payload' => array_merge($log->payload ?? [], ['webhook_event' => $eventType, 'intent_details' => $paymentIntent->toArray()]),
            ]);
            Log::info("StripeLog updated for failed payment intent: {$paymentIntent->id}");
            
            StripePaymentEvent::dispatch($eventType, $paymentIntent->toArray(), 'failed', $log->user_id);
        } else {
            StripePaymentEvent::dispatch($eventType, $paymentIntent->toArray(), 'failed');
        }

        $intentMetadata = isset($paymentIntent->metadata) ? (array) (is_object($paymentIntent->metadata) && method_exists($paymentIntent->metadata, 'toArray') ? $paymentIntent->metadata->toArray() : $paymentIntent->metadata) : [];
        $order = $this->findOrderFromStripe($intentMetadata, $paymentIntent->id ?? null);
        if ($order && $order->payment_status !== 'paid') {
            $order->update([
                'payment_status' => 'failed',
            ]);
        }
    }

    protected function handleSetupIntentSucceeded($setupIntent, $eventType)
    {
        $customerId = $setupIntent->customer;
        $paymentMethodId = $setupIntent->payment_method;

        if (!$customerId || !$paymentMethodId) {
            return;
        }

        $user = \App\Models\User::where('stripe_id', $customerId)->first();

        if ($user) {
            $stripeService = app(StripeService::class);
            $stripeService->syncPaymentMethod($user, $paymentMethodId);
            
            Log::info("Synced payment method {$paymentMethodId} for user {$user->id} via webhook.");
            
            StripePaymentEvent::dispatch($eventType, $setupIntent->toArray(), 'success', $user->id);
        }
    }

    protected function handlePaymentMethodDetached($paymentMethod, $eventType)
    {
        $paymentMethodId = $paymentMethod->id;
        
        $localMethod = \App\Models\UserPaymentMethod::where('stripe_payment_method_id', $paymentMethodId)->first();

        if ($localMethod) {
            $localMethod->update(['status' => 'action_required']);
            Log::info("Marked payment method {$paymentMethodId} as action_required (detached).");

            StripePaymentEvent::dispatch($eventType, $paymentMethod->toArray(), 'action_required', $localMethod->user_id);
        }
    }
}
