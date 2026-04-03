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
        $user = Auth::user();
        $items = $request->input('items', []); // Format: [['price_data' => [...], 'quantity' => 1]]
        $successUrl = $request->input('success_url', url('/payment/success'));
        $cancelUrl = $request->input('cancel_url', url('/payment/cancel'));
        $saveCard = $request->boolean('save_card', false);

        try {
            $session = $this->stripeService->createCheckoutSession($user, $items, $successUrl, $cancelUrl, $saveCard);
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
        $user = Auth::user();
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
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function updatePaymentIntent(Request $request)
    {
        $id = $request->input('payment_intent_id');
        $saveCard = $request->boolean('save_card', false);
        
        $data = [];
        if ($saveCard) {
            $data['setup_future_usage'] = 'off_session';
        }

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
     * List user's saved cards.
     */
    public function getCards()
    {
        $user = Auth::user();

        try {
            $cards = \App\Models\UserPaymentMethod::where('user_id', $user->id)
                ->orderBy('is_default', 'desc')
                ->orderBy('created_at', 'desc')
                ->get();
                
            return response()->json(['data' => $cards]);
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
            // Delete from Stripe
            $this->stripeService->deleteCard($id);

            // Delete from Local Database
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
}
