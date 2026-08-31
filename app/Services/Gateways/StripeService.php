<?php

namespace App\Services\Gateways;

use Stripe\Stripe;
use Stripe\Customer;
use Stripe\PaymentIntent;
use Stripe\SetupIntent;
use Stripe\PaymentMethod;
use Stripe\Checkout\Session as CheckoutSession;
use App\Models\User;
use App\Models\UserPaymentMethod;
use Exception;

class StripeService
{
    public function __construct()
    {
        $secretKey = \App\Models\SiteSetting::where('key', 'stripe_secret_key')->value('value')
            ?: config('services.stripe.secret');
        if ($secretKey) {
            Stripe::setApiKey($secretKey);
        }
    }

    /**
     * Create or retrieve a Stripe customer for a user.
     */
    public function createOrGetCustomer(User $user)
    {
        if ($user->stripe_id) {
            try {
                return Customer::retrieve($user->stripe_id);
            } catch (Exception $e) {
                // If retrieval fails (e.g., customer deleted in Stripe), create a new one
            }
        }

        $customer = Customer::create([
            'email' => $user->email,
            'name' => $user->name,
            'metadata' => [
                'user_id' => $user->id,
            ],
        ]);

        $user->update(['stripe_id' => $customer->id]);

        return $customer;
    }

    /**
     * Create a Checkout Session for one-time payments.
     */
    public function createCheckoutSession(User $user, array $items, string $successUrl, string $cancelUrl, bool $saveCard = false, array $metadata = [], array $extra_params = [])
    {
        $customer = $this->createOrGetCustomer($user);

        $params = [
            'customer' => $customer->id,
            'payment_method_types' => ['card'],
            'line_items' => $items,
            'mode' => 'payment',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => $metadata,
            'allow_promotion_codes' => true,
        ];

        $params = array_merge($params, $extra_params);

        if (isset($params['discounts']) || isset($params['coupon'])) {
            unset($params['allow_promotion_codes']);
        }

        if ($saveCard) {
            $params['payment_intent_data'] = [
                'setup_future_usage' => 'off_session', 
            ];
        }

        $session = CheckoutSession::create($params);

        \App\Models\StripeLog::create([
            'user_id' => $user->id,
            'plan_id' => $metadata['plan_id'] ?? null,
            'type' => 'checkout',
            'stripe_customer_id' => $customer->id,
            'session_id' => $session->id,
            'payment_intent_id' => $session->payment_intent,
            'amount' => $session->amount_total ? $session->amount_total / 100 : 0,
            'currency' => $session->currency,
            'status' => $session->payment_status,
            'payload' => $session->toArray(),
            'meta_data' => $metadata,
        ]);

        return $session;
    }

    /**
     * Create a Checkout Session for subscriptions.
     */
    public function createSubscriptionSession(User $user, string $priceId, string $successUrl, string $cancelUrl, array $metadata = [])
    {
        $customer = $this->createOrGetCustomer($user);

        $session = CheckoutSession::create([
            'customer' => $customer->id,
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price' => $priceId,
                'quantity' => 1,
            ]],
            'mode' => 'subscription',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => $metadata,
            'allow_promotion_codes' => true,
        ]);

        if (isset($metadata['coupon_id']) || isset($extra_params['discounts'])) {
             // Re-create session params if needed, but actually createSubscriptionSession doesn't take extra_params in signature
             // So this method might not even support discounts yet.
             // Let's leave it for now as it wasn't the one erroring.
        }

        \App\Models\StripeLog::create([
            'user_id' => $user->id,
            'plan_id' => $metadata['plan_id'] ?? null,
            'type' => 'subscription',
            'stripe_customer_id' => $customer->id,
            'session_id' => $session->id,
            'subscription_id' => $session->subscription,
            'amount' => $session->amount_total ? $session->amount_total : 0,
            'currency' => $session->currency,
            'status' => $session->status,
            'interval' => 'month', // Default assumption for fixed price if not fetched
            'interval_count' => 1,
            'payload' => $session->toArray(),
            'meta_data' => $metadata,
        ]);

        return $session;
    }

    /**
     * Create a subscription session with dynamic price (custom interval).
     * 
     * @param array $priceData ['amount', 'currency', 'interval', 'interval_count', 'product_name', 'duration_in_months']
     */
    public function createCustomSubscriptionSession(User $user, array $priceData, string $successUrl, string $cancelUrl, array $metadata = [], array $extra_params = [])
    {
        $customer = $this->createOrGetCustomer($user);

        $subscriptionData = [];

        // Calculate cancel_at if duration is provided (e.g., charge for 8 months)
        if (isset($priceData['duration_in_months']) && $priceData['duration_in_months'] > 0) {
             // Calculate end timestamp based on interval and count * duration
             // Logic: If interval is month, count is 1, duration is 8 -> 8 months later
             // Note: This is an approximation. Precise billing cycles are handled by Stripe.
             // We set cancel_at to: now + (interval_count * duration_in_months)
             
             $now = \Carbon\Carbon::now();
             $interval = $priceData['interval']; // day, week, month, year
             $count = $priceData['interval_count'] ?? 1;
             $totalDuration = $count * $priceData['duration_in_months'];
             
             if ($interval === 'day') $now->addDays($totalDuration);
             elseif ($interval === 'week') $now->addWeeks($totalDuration);
             elseif ($interval === 'month') $now->addMonths($totalDuration);
             elseif ($interval === 'year') $now->addYears($totalDuration);
             
             $subscriptionData['cancel_at'] = $now->timestamp;
        }

        // Price data should include: amount, currency, interval, interval_count, product_name
        $params = array_merge([
            'customer' => $customer->id,
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => $priceData['currency'] ?? 'usd',
                    'product_data' => [
                        'name' => $priceData['product_name'] ?? 'Subscription Plan',
                    ],
                    'unit_amount' => $priceData['amount'],
                    'recurring' => [
                        'interval' => $priceData['interval'], // day, week, month, year
                        'interval_count' => $priceData['interval_count'] ?? 1, // count if interval=day and interval_count=2 so every 2 day charge 
                    ],
                ],
                'quantity' => 1,
            ]],
            'mode' => 'subscription',
            'subscription_data' => $subscriptionData,
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => $metadata,
            'allow_promotion_codes' => true,
        ], $extra_params);

        if (isset($params['discounts']) || isset($params['coupon'])) {
            unset($params['allow_promotion_codes']);
        }

        $session = CheckoutSession::create($params);

        \App\Models\StripeLog::create([
            'user_id' => $user->id,
            'plan_id' => $metadata['plan_id'] ?? null,
            'type' => 'subscription_custom',
            'stripe_customer_id' => $customer->id,
            'session_id' => $session->id,
            'subscription_id' => $session->subscription,
            'amount' => $priceData['amount'] / 100,
            'currency' => $priceData['currency'] ?? 'usd',
            'status' => $session->status,
            'product_name' => $priceData['product_name'] ?? 'Subscription Plan',
            'interval' => $priceData['interval'],
            'interval_count' => $priceData['interval_count'] ?? 1,
            'next_payment_status' => 'scheduled', // Initial status
            'payload' => $session->toArray(),
            'meta_data' => $metadata,
        ]);

        return $session;
    }

    /**
     * Cancel a subcription immediately.
     */
    public function cancelSubscription(string $subscriptionId)
    {
        $subscription = \Stripe\Subscription::retrieve($subscriptionId);
        return $subscription->cancel();
    }

    /**
     * Create a Payment Intent.
     */
    public function createPaymentIntent(User $user, int $amount, string $currency = 'usd', array $metadata = [], bool $setupFutureUsage = false, string $paymentMethodId = null)
    {
        $secretKey = \App\Models\SiteSetting::where('key', 'stripe_secret_key')->value('value')
            ?: config('services.stripe.secret');

        if (!$secretKey || (!str_starts_with($secretKey, 'sk_') && !str_starts_with($secretKey, 'rk_'))) {
            throw new Exception("Stripe API Secret Key is unconfigured or invalid. Please configure it in Admin Settings.");
        }

        $customer = $this->createOrGetCustomer($user);

        $params = [
            'amount' => $amount,
            'currency' => $currency,
            'customer' => $customer->id,
            'metadata' => $metadata,
            'automatic_payment_methods' => [
                'enabled' => true,
            ],
        ];

        if ($paymentMethodId) {
            $params['payment_method'] = $paymentMethodId;
            // If we have a payment method, we can disable automatic methods or keep them
            // Usually for saved cards we want to confirm it.
        }

        if ($setupFutureUsage) {
            $params['setup_future_usage'] = 'off_session';
        }

        $intent = PaymentIntent::create($params);

        \App\Models\StripeLog::create([
            'user_id' => $user->id,
            'type' => 'payment_intent',
            'stripe_customer_id' => $customer->id,
            'payment_intent_id' => $intent->id,
            'amount' => $amount / 100,
            'currency' => $currency,
            'status' => $intent->status,
            'payload' => $intent->toArray(),
        ]);

        return $intent;
    }

    /**
     * Update an existing Payment Intent.
     */
    public function updatePaymentIntent(string $paymentIntentId, array $data)
    {
        return PaymentIntent::update($paymentIntentId, $data);
    }

    /**
     * Create a Setup Intent for saving a card.
     */
    public function createSetupIntent(User $user)
    {
        $secretKey = \App\Models\SiteSetting::where('key', 'stripe_secret_key')->value('value')
            ?: config('services.stripe.secret');

        if (!$secretKey || (!str_starts_with($secretKey, 'sk_') && !str_starts_with($secretKey, 'rk_'))) {
            throw new Exception("Stripe API Secret Key is unconfigured or invalid. Please configure it in Admin Settings.");
        }

        $customer = $this->createOrGetCustomer($user);

        $intent = SetupIntent::create([
            'customer' => $customer->id,
            'payment_method_types' => ['card'],
        ]);

        \App\Models\StripeLog::create([
            'user_id' => $user->id,
            'type' => 'setup_intent',
            'stripe_customer_id' => $customer->id,
            'payment_intent_id' => $intent->id,
            'status' => $intent->status,
            'payload' => $intent->toArray(),
        ]);

        return $intent;
    }

    /**
     * List saved cards for a user.
     */
    public function listCards(User $user)
    {
        if (!$user->stripe_id) {
            return [];
        }

        return PaymentMethod::all([
            'customer' => $user->stripe_id,
            'type' => 'card',
        ]);
    }

    /**
     * Delete a saved card.
     */
    public function deleteCard(string $paymentMethodId)
    {
        $paymentMethod = PaymentMethod::retrieve($paymentMethodId);
        return $paymentMethod->detach();
    }

    /**
     * Retrieve payment details from a Checkout Session.
     */
    public function retrieveSessionPaymentDetails(string $sessionId)
    {
        $session = CheckoutSession::retrieve([
            'id' => $sessionId,
            'expand' => ['payment_intent.payment_method', 'subscription.default_payment_method', 'setup_intent.payment_method'],
        ]);

        $paymentMethod = null;

        if ($session->payment_intent && $session->payment_intent->payment_method) {
            $paymentMethod = $session->payment_intent->payment_method;
        } elseif ($session->subscription && $session->subscription->default_payment_method) {
            $paymentMethod = $session->subscription->default_payment_method;
        } elseif ($session->setup_intent && $session->setup_intent->payment_method) {
            $paymentMethod = $session->setup_intent->payment_method;
        }

        if (!$paymentMethod) {
            return null;
        }

        $details = [
            'type' => $paymentMethod->type,
            'name' => $paymentMethod->billing_details->name,
            'email' => $paymentMethod->billing_details->email,
            'phone' => $paymentMethod->billing_details->phone,
            'address' => $paymentMethod->billing_details->address ? $paymentMethod->billing_details->address->toArray() : null,
        ];

        if ($paymentMethod->type === 'card') {
            $details = array_merge($details, [
                'brand' => $paymentMethod->card->brand,
                'last4' => $paymentMethod->card->last4,
                'exp_month' => $paymentMethod->card->exp_month,
                'exp_year' => $paymentMethod->card->exp_year,
                'country' => $paymentMethod->card->country,
                'funding' => $paymentMethod->card->funding, // credit, debit, prepaid
                'wallet' => $paymentMethod->card->wallet ? $paymentMethod->card->wallet->type : null, // apple_pay, google_pay
            ]);
        } elseif ($paymentMethod->type === 'us_bank_account') {
            $details = array_merge($details, [
                'bank_name' => $paymentMethod->us_bank_account->bank_name,
                'last4' => $paymentMethod->us_bank_account->last4,
                'account_holder_type' => $paymentMethod->us_bank_account->account_holder_type, // individual, company
                'routing_number' => $paymentMethod->us_bank_account->routing_number,
            ]);
        } elseif ($paymentMethod->type === 'sepa_debit') {
            $details = array_merge($details, [
                'bank_code' => $paymentMethod->sepa_debit->bank_code,
                'last4' => $paymentMethod->sepa_debit->last4,
                'country' => $paymentMethod->sepa_debit->country,
            ]);
        }
        
        // Add more types as needed (alipay, paypal, etc.)

        return $details;
    }

    /**
     * Sync a Stripe payment method to the local database.
     */
    public function syncPaymentMethod(User $user, $paymentMethod)
    {
        if (is_string($paymentMethod)) {
            $paymentMethod = PaymentMethod::retrieve($paymentMethod);
        }

        $expiryDate = str_pad($paymentMethod->card->exp_month, 2, '0', STR_PAD_LEFT) . '/' . substr($paymentMethod->card->exp_year, -2);

        // Check if this card already exists locally by Stripe ID
        $localMethod = UserPaymentMethod::where('user_id', $user->id)
            ->where('stripe_payment_method_id', $paymentMethod->id)
            ->first();

        // If not found by ID, try matching by last4/expiry to link existing records
        if (!$localMethod) {
            $localMethod = UserPaymentMethod::where('user_id', $user->id)
                ->where('last4', $paymentMethod->card->last4)
                ->where('expiry_date', $expiryDate)
                ->whereNull('stripe_payment_method_id')
                ->first();
        }

        $brand = strtolower($paymentMethod->card->brand);
        $icon = "images/card-brands/{$brand}.svg";

        if (!$localMethod) {
            // Create new record
            $hasOtherMethods = UserPaymentMethod::where('user_id', $user->id)->exists();

            $localMethod = UserPaymentMethod::create([
                'user_id' => $user->id,
                'stripe_payment_method_id' => $paymentMethod->id,
                'type' => $paymentMethod->card->brand,
                'brand' => $paymentMethod->card->brand,
                'last4' => $paymentMethod->card->last4,
                'expiry_date' => $expiryDate,
                'cardholder_name' => $paymentMethod->billing_details->name ?? 'Member',
                'is_default' => !$hasOtherMethods,
                'brand_icon' => $icon,
            ]);
        } else {
            // Update existing record with the new Stripe ID and normalized data
            $localMethod->update([
                'stripe_payment_method_id' => $paymentMethod->id,
                'type' => $paymentMethod->card->brand,
                'brand' => $paymentMethod->card->brand,
                'cardholder_name' => $paymentMethod->billing_details->name ?? $localMethod->cardholder_name,
                'brand_icon' => $icon,
            ]);
        }

        return $localMethod;
    }

    /**
     * Set a payment method as default for a customer.
     */
    public function setDefaultPaymentMethod(User $user, string $paymentMethodId)
    {
        $customer = $this->createOrGetCustomer($user);

        return Customer::update($customer->id, [
            'invoice_settings' => [
                'default_payment_method' => $paymentMethodId,
            ],
        ]);
    }

    /**
     * Sync a payment method using a SetupIntent ID.
     */
    public function syncPaymentMethodFromSetupIntent(User $user, string $setupIntentId)
    {
        $setupIntent = SetupIntent::retrieve($setupIntentId);
        
        if ($setupIntent->status !== 'succeeded') {
            throw new Exception("Setup intent not succeeded. Status: " . $setupIntent->status);
        }

        if (!$setupIntent->payment_method) {
            throw new Exception("No payment method associated with this Setup Intent");
        }

        return $this->syncPaymentMethod($user, $setupIntent->payment_method);
    }

    /**
     * Calculate Tax using Stripe Tax API, falling back to local database tax rates.
     */
    public function calculateTax(array $addressData, float $amount, array $lineItems = [])
    {
        $country = trim($addressData['country'] ?? '');
        $state = trim($addressData['state'] ?? '');
        $city = trim($addressData['city'] ?? '');
        $postalCode = trim($addressData['postalCode'] ?? $addressData['zip_code'] ?? '');
        $line1 = trim($addressData['addressLine1'] ?? $addressData['street_address'] ?? '');

        // Convert common country names to 2-letter ISO country codes
        $isoCountry = $this->getCountryIsoCode($country);

        $secretKey = \App\Models\SiteSetting::where('key', 'stripe_secret_key')->value('value')
            ?: config('services.stripe.secret');

        if ($secretKey && !empty($isoCountry)) {
            try {
                Stripe::setApiKey($secretKey);

                $items = [];
                if (!empty($lineItems)) {
                    foreach ($lineItems as $idx => $item) {
                        $itemPrice = (float) ($item['price'] ?? 0);
                        $itemQty = (int) ($item['quantity'] ?? 1);
                        $itemAmount = round($itemPrice * $itemQty * 100);
                        if ($itemAmount > 0) {
                            $items[] = [
                                'amount' => (int) $itemAmount,
                                'reference' => 'Item_' . ($idx + 1),
                                'tax_behavior' => 'exclusive',
                                'tax_code' => 'txcd_99999999',
                            ];
                        }
                    }
                }

                if (empty($items) && $amount > 0) {
                    $items[] = [
                        'amount' => (int) round($amount * 100),
                        'reference' => 'Subtotal',
                        'tax_behavior' => 'exclusive',
                        'tax_code' => 'txcd_99999999',
                    ];
                }

                if (!empty($items)) {
                    $customerDetails = [
                        'address' => array_filter([
                            'country' => $isoCountry,
                            'state' => $state,
                            'city' => $city,
                            'postal_code' => $postalCode,
                            'line1' => $line1,
                        ]),
                        'address_source' => 'shipping',
                    ];

                    $calculation = \Stripe\Tax\Calculation::create([
                        'currency' => 'usd',
                        'line_items' => $items,
                        'customer_details' => $customerDetails,
                    ]);

                    $taxCents = $calculation->tax_amount_exclusive ?? 0;
                    $taxAmount = round($taxCents / 100, 2);

                    return [
                        'success' => true,
                        'tax_amount' => $taxAmount,
                        'source' => 'stripe',
                        'stripe_calculation_id' => $calculation->id ?? null,
                    ];
                }
            } catch (Exception $e) {
                \Illuminate\Support\Facades\Log::warning("Stripe Tax calculation failed: " . $e->getMessage());
            }
        }

        // Local fallback calculation
        $taxRate = 0;
        if ($country && $state) {
            $countryVariants = array_values(array_unique(array_filter([
                strtolower(trim($country)),
                strtolower($isoCountry),
                ($isoCountry === 'US') ? 'united states' : null
            ])));

            $stateVariants = $this->getStateVariants($state);

            $taxRecord = \Illuminate\Support\Facades\DB::table('taxes')
                ->whereIn(\Illuminate\Support\Facades\DB::raw('LOWER(TRIM(country))'), $countryVariants)
                ->whereIn(\Illuminate\Support\Facades\DB::raw('LOWER(TRIM(state))'), $stateVariants)
                ->first();

            if (!$taxRecord) {
                $taxRecord = \Illuminate\Support\Facades\DB::table('taxes')
                    ->where(function($q) use ($countryVariants) {
                        $q->whereIn(\Illuminate\Support\Facades\DB::raw('LOWER(TRIM(country))'), $countryVariants)
                          ->orWhere('country', '*')->orWhere('country', 'All');
                    })
                    ->where(function($q) use ($stateVariants) {
                        $q->whereIn(\Illuminate\Support\Facades\DB::raw('LOWER(TRIM(state))'), $stateVariants)
                          ->orWhere('state', '*')->orWhere('state', 'All');
                    })
                    ->first();
            }

            if ($taxRecord) {
                $taxRate = (float) $taxRecord->tax_rate;
            }
        }

        $taxAmount = round(($amount * $taxRate) / 100, 2);

        return [
            'success' => true,
            'tax_amount' => $taxAmount,
            'tax_rate' => $taxRate,
            'source' => 'local',
        ];
    }

    private function getCountryIsoCode($countryName)
    {
        if (empty($countryName)) return '';
        $c = strtoupper(trim($countryName));
        if (strlen($c) === 2) return $c;

        $map = [
            'UNITED STATES' => 'US',
            'UNITED STATES OF AMERICA' => 'US',
            'USA' => 'US',
            'CANADA' => 'CA',
            'UNITED KINGDOM' => 'GB',
            'UK' => 'GB',
            'GREAT BRITAIN' => 'GB',
            'AUSTRALIA' => 'AU',
            'GERMANY' => 'DE',
            'FRANCE' => 'FR',
            'BANGLADESH' => 'BD',
            'INDIA' => 'IN',
            'ITALY' => 'IT',
            'SPAIN' => 'ES',
            'MEXICO' => 'MX',
            'BRAZIL' => 'BR',
            'JAPAN' => 'JP',
            'CHINA' => 'CN',
            'NETHERLANDS' => 'NL',
            'SWITZERLAND' => 'CH',
            'SWEDEN' => 'SE',
            'NORWAY' => 'NO',
            'DENMARK' => 'DK',
            'SINGAPORE' => 'SG',
            'UNITED ARAB EMIRATES' => 'AE',
            'UAE' => 'AE',
            'SAUDI ARABIA' => 'SA',
        ];

        return $map[$c] ?? '';
    }

    private function getStateVariants($stateName)
    {
        if (empty($stateName)) return [];
        $s = strtolower(trim($stateName));
        $map = [
            'alabama' => 'al', 'alaska' => 'ak', 'arizona' => 'az', 'arkansas' => 'ar', 'california' => 'ca',
            'colorado' => 'co', 'connecticut' => 'ct', 'delaware' => 'de', 'florida' => 'fl', 'georgia' => 'ga',
            'hawaii' => 'hi', 'idaho' => 'id', 'illinois' => 'il', 'indiana' => 'in', 'iowa' => 'ia',
            'kansas' => 'ks', 'kentucky' => 'ky', 'louisiana' => 'la', 'maine' => 'me', 'maryland' => 'md',
            'massachusetts' => 'ma', 'michigan' => 'mi', 'minnesota' => 'mn', 'mississippi' => 'ms', 'missouri' => 'mo',
            'montana' => 'mt', 'nebraska' => 'ne', 'nevada' => 'nv', 'new hampshire' => 'nh', 'new jersey' => 'nj',
            'new mexico' => 'nm', 'new york' => 'ny', 'north carolina' => 'nc', 'north dakota' => 'nd', 'ohio' => 'oh',
            'oklahoma' => 'ok', 'oregon' => 'or', 'pennsylvania' => 'pa', 'rhode island' => 'ri', 'south carolina' => 'sc',
            'south dakota' => 'sd', 'tennessee' => 'tn', 'texas' => 'tx', 'utah' => 'ut', 'vermont' => 'vt',
            'virginia' => 'va', 'washington' => 'wa', 'west virginia' => 'wv', 'wisconsin' => 'wi', 'wyoming' => 'wy'
        ];

        $variants = [$s];
        if (isset($map[$s])) {
            $variants[] = $map[$s];
        } else {
            $flipped = array_flip($map);
            if (isset($flipped[$s])) {
                $variants[] = $flipped[$s];
            }
        }
        return array_values(array_unique($variants));
    }
}
