<?php

namespace App\Http\Controllers\Gateways;

use App\Http\Controllers\Controller;
use App\Services\Gateways\StripeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\UserPaymentMethod;

class StripeController extends Controller
{
    protected $stripeService;

    public function __construct(StripeService $stripeService)
    {
        $this->stripeService = $stripeService;
    }

    /**
     * Create a Checkout Session.
     */
    public function checkout(Request $request)
    {
        $user = auth('api')->user() ?? Auth::user();
        $items = $request->input('items', []); // Format: [['price_data' => [...], 'quantity' => 1]]
        $shippingCost = (float) $request->input('shipping_cost', 0);
        $taxAmount = (float) $request->input('tax', $request->input('tax_amount', 0));
        $discountAmount = (float) $request->input('discount', 0);

        if ($shippingCost > 0) {
            $items[] = [
                'price_data' => [
                    'currency' => 'usd',
                    'product_data' => [
                        'name' => 'Shipping Fee',
                    ],
                    'unit_amount' => (int) round($shippingCost * 100),
                ],
                'quantity' => 1,
            ];
        }

        if ($taxAmount > 0) {
            $items[] = [
                'price_data' => [
                    'currency' => 'usd',
                    'product_data' => [
                        'name' => 'Sales Tax',
                    ],
                    'unit_amount' => (int) round($taxAmount * 100),
                ],
                'quantity' => 1,
            ];
        }

        $extraParams = [];
        if ($discountAmount > 0) {
            try {
                $coupon = \Stripe\Coupon::create([
                    'amount_off' => (int) round($discountAmount * 100),
                    'currency' => 'usd',
                    'duration' => 'once',
                    'name' => 'Order Discount',
                ]);
                $extraParams['discounts'] = [['coupon' => $coupon->id]];
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning("Stripe Checkout coupon creation failed: " . $e->getMessage());
            }
        }

        $successUrl = $request->input('success_url', url('/payment/success'));
        $cancelUrl = $request->input('cancel_url', url('/payment/cancel'));
        $saveCard = $request->boolean('save_card', false);

        try {
            $session = $this->stripeService->createCheckoutSession($user, $items, $successUrl, $cancelUrl, $saveCard, [], $extraParams);
            return response()->json(['id' => $session->id, 'url' => $session->url]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Create a Subscription Session.
     */
    public function subscribe(Request $request)
    {
        $user = Auth::user();
        $priceId = $request->input('price_id');
        $successUrl = $request->input('success_url', url('/success'));
        $cancelUrl = $request->input('cancel_url', url('/cancel'));

        try {
            if ($priceId) {
                $session = $this->stripeService->createSubscriptionSession($user, $priceId, $successUrl, $cancelUrl);
            } else {
                // If no price_id, use custom dynamic price parameters
                $priceData = [
                    'amount' => $request->input('amount'),
                    'currency' => $request->input('currency', 'usd'),
                    'interval' => $request->input('interval', 'month'), // day, week, month, year
                    'interval_count' => $request->input('interval_count', 1),
                    'product_name' => $request->input('product_name', 'Subscription Plan'),
                    'duration_in_months' => $request->input('duration_in_months'), // e.g. 8 for 8 months
                ];
                $session = $this->stripeService->createCustomSubscriptionSession($user, $priceData, $successUrl, $cancelUrl);
            }
            return response()->json(['id' => $session->id, 'url' => $session->url]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Cancel a Subscription.
     */
    public function cancelSubscription(Request $request)
    {
        $subscriptionId = $request->input('subscription_id');

        try {
            $this->stripeService->cancelSubscription($subscriptionId);
            return response()->json(['message' => 'Subscription canceled successfully']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Create a Payment Intent.
     */
    public function paymentIntent(Request $request)
    {
        $user = auth('api')->user() ?? Auth::user();
        $amount = $request->input('amount');
        $currency = $request->input('currency', 'usd');
        $metadata = $request->input('metadata', []);
        $successUrl = $request->input('success_url', url('/payment/success'));
        $saveCard = $request->boolean('save_card', false);
        $paymentMethodId = $request->input('payment_method_id');

        try {
            $intent = $this->stripeService->createPaymentIntent($user, $amount, $currency, $metadata, $saveCard, $paymentMethodId);
            return response()->json([
                'client_secret' => $intent->client_secret,
                'success_url' => $successUrl
            ]);
        } catch (\Exception $e) {
            if ($paymentMethodId && (str_contains($e->getMessage(), 'No such payment_method') || str_contains($e->getMessage(), 'resource_missing'))) {
                UserPaymentMethod::where('stripe_payment_method_id', $paymentMethodId)
                    ->where('user_id', $user->id)
                    ->delete();
                return response()->json([
                    'error' => 'The selected saved card is no longer valid for this Stripe account. Please re-enter your card details.'
                ], 400);
            }
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function updatePaymentIntent(Request $request)
    {
        $id = $request->input('payment_intent_id');
        $data = $request->input('data', []);

        try {
            if (!empty($data)) {
                $this->stripeService->updatePaymentIntent($id, $data);
            }
            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Save a Card (Setup Intent).
     */
    public function saveCard(Request $request)
    {
        $user = Auth::user();

        try {
            $intent = $this->stripeService->createSetupIntent($user);
            return response()->json(['client_secret' => $intent->client_secret]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Confirm a saved card after Stripe setup success.
     */
    public function confirmSaveCard(Request $request)
    {
        $user = Auth::user();
        $setupIntentId = $request->input('setup_intent_id');

        if (!$setupIntentId) {
            return response()->json(['error' => 'Setup Intent ID is required'], 400);
        }

        try {
            // Use Stripe Service to sync the payment method to our DB synchronously
            $paymentMethod = $this->stripeService->syncPaymentMethodFromSetupIntent($user, $setupIntentId);
            return response()->json(['data' => $paymentMethod, 'message' => 'Card confirmed and saved successfully']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * List user's saved cards.
     */
    public function getCards()
    {
        $user = Auth::user();

        $stripeEnabled = \App\Models\SiteSetting::where('key', 'stripe_enabled')->value('value');
        $secretKey = \App\Models\SiteSetting::where('key', 'stripe_secret_key')->value('value')
            ?: config('services.stripe.secret');

        $isConfigured = ($stripeEnabled === '1' || $stripeEnabled === 1 || $stripeEnabled === true || $stripeEnabled === 'true')
            && !empty($secretKey)
            && (str_starts_with($secretKey, 'sk_') || str_starts_with($secretKey, 'rk_'));

        if (!$isConfigured) {
            return response()->json(['data' => []]);
        }

        try {
            $cards = \App\Models\UserPaymentMethod::where('user_id', $user->id)
                ->orderBy('is_default', 'desc')
                ->orderBy('created_at', 'desc')
                ->get();

            $validCards = [];
            foreach ($cards as $card) {
                if (!empty($card->stripe_payment_method_id)) {
                    try {
                        // Verify if this card ID is valid on the currently configured Stripe Account
                        \Stripe\PaymentMethod::retrieve($card->stripe_payment_method_id);
                        $validCards[] = $card;
                    } catch (\Exception $e) {
                        // Card is invalid or belongs to an old/different Stripe account -> Auto Purge
                        $card->delete();
                    }
                }
            }
                
            return response()->json(['data' => array_values($validCards)]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Delete a saved card.
     */
    public function deleteCard($id)
    {
        try {
            // Delete from Stripe (ignore exception if card doesn't exist on current account)
            try {
                $this->stripeService->deleteCard($id);
            } catch (\Exception $e) {
                // Ignore
            }

            // Always delete from Local Database
            UserPaymentMethod::where('stripe_payment_method_id', $id)
                ->where('user_id', Auth::id())
                ->delete();

            return response()->json(['message' => 'Card deleted successfully']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Verify and sync a single card from Stripe.
     */
    public function verifyCard($id)
    {
        $user = Auth::user();
        try {
            $card = $this->stripeService->syncPaymentMethod($user, $id);
            return response()->json(['data' => $card, 'message' => 'Card verified successfully']);
        } catch (\Exception $e) {
            // If card not found on Stripe, mark as action required
            $localMethod = UserPaymentMethod::where('stripe_payment_method_id', $id)
                ->where('user_id', $user->id)
                ->first();
            
            if ($localMethod) {
                $localMethod->update(['status' => 'action_required']);
            }

            return response()->json(['error' => 'Card could not be verified on Stripe. Action required.'], 400);
        }
    }

    /**
     * Set a payment method as default.
     */
    public function setDefaultCard($id)
    {
        $user = Auth::user();

        try {
            // 1. Update Stripe
            $this->stripeService->setDefaultPaymentMethod($user, $id);

            // 2. Clear other defaults in DB
            UserPaymentMethod::where('user_id', $user->id)
                ->where('is_default', true)
                ->update(['is_default' => false]);

            // 3. Set new default in DB
            UserPaymentMethod::where('user_id', $user->id)
                ->where('stripe_payment_method_id', $id)
                ->update(['is_default' => true]);

            return response()->json(['message' => 'Default payment method updated successfully']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Get payment details from a session ID.
     */
    public function getPaymentDetails($sessionId)
    {
        try {
            $details = $this->stripeService->retrieveSessionPaymentDetails($sessionId);
            return response()->json(['data' => $details]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Calculate Tax via Stripe Tax API or local fallback.
     */
    public function calculateTax(Request $request)
    {
        $addressData = $request->input('address_data', []);
        $amount = (float) $request->input('amount', 0);
        $lineItems = $request->input('items', []);

        try {
            $result = $this->stripeService->calculateTax($addressData, $amount, $lineItems);
            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'tax_amount' => 0,
                'error' => $e->getMessage()
            ], 400);
        }
    }
}
