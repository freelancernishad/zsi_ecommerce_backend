<?php

namespace Tests\Feature;

use App\Http\Controllers\Gateways\ZsiPayController;
use App\Http\Controllers\QuickPaymentController;
use App\Models\Cart;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\QuickPayment;
use App\Models\SiteSetting;
use App\Models\StripeLog;
use App\Services\Gateways\StripeWebhookService;
use App\Services\TenantDatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TenantWebhookRoutingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Reset tenant state and deterministic tenant domain map before each test
        Config::set('tenants.domains', [
            'goldenmarkstore.com' => 'mustgyyu_zsi_ecommerce_db',
            'www.goldenmarkstore.com' => 'mustgyyu_zsi_ecommerce_db',
            'bismillahfashionusa.com' => 'mustgyyu_bismillahfashionusa_db',
            'www.bismillahfashionusa.com' => 'mustgyyu_bismillahfashionusa_db',
            'ebsdeals.com' => 'mustgyyu_ebsdeals',
            'everythingpet.org' => 'mustgyyu_everythingpet',
            'localhost' => 'goldenmarkstore_db',
        ]);
        Config::set('tenants.fallback', 'goldenmarkstore_db');
        Config::set('tenants.original_default_database', 'goldenmarkstore_db');
        Config::set('database.connections.mysql.database', 'goldenmarkstore_db');
        Config::set('tenants.current_database', 'goldenmarkstore_db');
        Config::set('tenants.current_domain', null);
        Config::set('services.stripe.webhook', null);
        Config::set('services.stripe.secret', null);

        Schema::dropIfExists('order_status_histories');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('carts');
        Schema::dropIfExists('site_settings');
        Schema::dropIfExists('stripe_logs');
        Schema::dropIfExists('quick_payments');
        Schema::dropIfExists('system_settings');
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('stripe_id')->nullable();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number')->unique();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->decimal('original_subtotal', 10, 2)->default(0);
            $table->decimal('subtotal', 10, 2)->default(0);
            $table->decimal('discount', 10, 2)->default(0);
            $table->decimal('shipping_cost', 10, 2)->default(0);
            $table->decimal('tax', 10, 2)->default(0);
            $table->decimal('total', 10, 2)->default(0);
            $table->string('status')->default('pending');
            $table->string('payment_method')->nullable();
            $table->string('payment_status')->default('pending');
            $table->string('transaction_id')->nullable();
            $table->json('payment_details')->nullable();
            $table->timestamps();
        });

        Schema::create('order_status_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->string('status');
            $table->string('location')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->integer('quantity')->default(1);
            $table->timestamps();
        });

        Schema::create('site_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('group')->nullable();
            $table->string('type')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::create('stripe_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('plan_id')->nullable();
            $table->string('type')->nullable();
            $table->string('stripe_customer_id')->nullable();
            $table->string('session_id')->nullable();
            $table->string('subscription_id')->nullable();
            $table->string('payment_intent_id')->nullable();
            $table->decimal('amount', 10, 2)->default(0);
            $table->string('currency')->nullable();
            $table->string('status')->nullable();
            $table->string('product_name')->nullable();
            $table->timestamp('next_payment_date')->nullable();
            $table->string('next_payment_status')->nullable();
            $table->string('interval')->nullable();
            $table->integer('interval_count')->nullable();
            $table->json('payload')->nullable();
            $table->json('meta_data')->nullable();
            $table->timestamps();
        });

        Schema::create('quick_payments', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->text('reason');
            $table->decimal('amount', 10, 2);
            $table->string('currency')->default('USD');
            $table->string('status')->default('pending');
            $table->string('payment_method')->nullable();
            $table->string('transaction_id')->nullable();
            $table->timestamps();
        });
    }

    public function test_resolve_from_request_supports_query_header_origin_host_and_fallback(): void
    {
        $queryReq = Request::create('/api/payment/stripe/webhook?tenant=ebsdeals.com', 'POST');
        $resolvedQuery = TenantDatabaseManager::resolveFromRequest($queryReq);
        $this->assertSame('ebsdeals.com', $resolvedQuery['domain']);
        $this->assertSame('mustgyyu_ebsdeals', $resolvedQuery['database']);

        $headerReq = Request::create('/api/orders', 'POST', [], [], [], [
            'HTTP_X_TENANT_DOMAIN' => 'everythingpet.org',
        ]);
        $resolvedHeader = TenantDatabaseManager::resolveFromRequest($headerReq);
        $this->assertSame('everythingpet.org', $resolvedHeader['domain']);
        $this->assertSame('mustgyyu_everythingpet', $resolvedHeader['database']);

        $originReq = Request::create('/api/orders', 'POST', [], [], [], [
            'HTTP_ORIGIN' => 'https://www.bismillahfashionusa.com',
        ]);
        $resolvedOrigin = TenantDatabaseManager::resolveFromRequest($originReq);
        $this->assertSame('www.bismillahfashionusa.com', $resolvedOrigin['domain']);
        $this->assertSame('mustgyyu_bismillahfashionusa_db', $resolvedOrigin['database']);

        $hostReq = Request::create('https://api.goldenmarkstore.com/api/orders', 'GET');
        $resolvedHost = TenantDatabaseManager::resolveFromRequest($hostReq);
        $this->assertSame('goldenmarkstore.com', $resolvedHost['domain']);
        $this->assertSame('mustgyyu_zsi_ecommerce_db', $resolvedHost['database']);

        $unknownReq = Request::create('https://unknown-site.example/api/orders', 'GET');
        $resolvedUnknown = TenantDatabaseManager::resolveFromRequest($unknownReq);
        $this->assertNull($resolvedUnknown['domain']);
        $this->assertSame(config('tenants.fallback'), $resolvedUnknown['database']);
    }

    public function test_switch_by_domain_or_database_enforces_whitelist(): void
    {
        $switched = TenantDatabaseManager::switchByDomainOrDatabase('ebsdeals.com', null);
        $this->assertTrue($switched);
        $this->assertSame('mustgyyu_ebsdeals', Config::get('database.connections.mysql.database'));

        $switchedByDb = TenantDatabaseManager::switchByDomainOrDatabase(null, 'mustgyyu_everythingpet');
        $this->assertTrue($switchedByDb);
        $this->assertSame('mustgyyu_everythingpet', Config::get('database.connections.mysql.database'));

        $rejected = TenantDatabaseManager::switchByDomainOrDatabase('evil-attacker.com', 'mysql');
        $this->assertFalse($rejected);
        $this->assertSame('mustgyyu_everythingpet', Config::get('database.connections.mysql.database'));
    }

    public function test_extract_tenant_metadata_from_payload_covers_zsi_and_stripe_shapes(): void
    {
        $zsiMeta = TenantDatabaseManager::extractTenantMetadataFromPayload([
            'type' => 'payment.succeeded',
            'data' => [
                'metadata' => [
                    'tenant_domain' => 'ebsdeals.com',
                    'tenant_db' => 'mustgyyu_ebsdeals',
                ],
            ],
        ]);
        $this->assertSame('ebsdeals.com', $zsiMeta['tenant_domain']);
        $this->assertSame('mustgyyu_ebsdeals', $zsiMeta['tenant_db']);

        $stripeSessionMeta = TenantDatabaseManager::extractTenantMetadataFromPayload([
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_test_1',
                    'metadata' => [
                        'tenant_domain' => 'everythingpet.org',
                        'tenant_db' => 'mustgyyu_everythingpet',
                    ],
                ],
            ],
        ]);
        $this->assertSame('everythingpet.org', $stripeSessionMeta['tenant_domain']);
        $this->assertSame('mustgyyu_everythingpet', $stripeSessionMeta['tenant_db']);

        $stripeInvoiceMeta = TenantDatabaseManager::extractTenantMetadataFromPayload([
            'type' => 'invoice.payment_succeeded',
            'data' => [
                'object' => [
                    'id' => 'in_test_1',
                    'parent' => [
                        'subscription_details' => [
                            'metadata' => [
                                'tenant_domain' => 'bismillahfashionusa.com',
                                'tenant_db' => 'mustgyyu_bismillahfashionusa_db',
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        $this->assertSame('bismillahfashionusa.com', $stripeInvoiceMeta['tenant_domain']);
        $this->assertSame('mustgyyu_bismillahfashionusa_db', $stripeInvoiceMeta['tenant_db']);
    }

    public function test_zsipay_webhook_switches_tenant_prevents_id_collision_and_is_idempotent(): void
    {
        // Order #1 is a different order; Order #2 is the real tenant order we want updated
        $otherOrder = Order::create([
            'order_number' => 'ORD-OTHER-001',
            'total' => 50,
            'payment_status' => 'pending',
            'status' => 'pending',
        ]);

        $targetOrder = Order::create([
            'order_number' => 'ORD-TENANT-999',
            'user_id' => 42,
            'total' => 120,
            'payment_status' => 'pending',
            'status' => 'pending',
        ]);

        Cart::create([
            'user_id' => 42,
            'product_id' => 1,
            'quantity' => 2,
        ]);

        $payload = [
            'type' => 'payment.succeeded',
            'data' => [
                'payment_status' => 'succeeded',
                'tracking_id' => 'trk_zsi_12345',
                'merchant_transaction_id' => 'ORD-TENANT-999',
                'metadata' => [
                    // Deliberately pass mismatched order_id = $otherOrder->id to assert order_number takes precedence
                    'order_id' => (string) $otherOrder->id,
                    'order_number' => 'ORD-TENANT-999',
                    'tenant_domain' => 'everythingpet.org',
                    'tenant_db' => 'mustgyyu_everythingpet',
                ],
            ],
        ];

        $controller = new ZsiPayController();
        $request = Request::create('/api/payment/zsipay/webhook', 'POST', $payload);
        $response = $controller->webhook($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('mustgyyu_everythingpet', Config::get('database.connections.mysql.database'));

        $otherOrder->refresh();
        $targetOrder->refresh();

        $this->assertSame('pending', $otherOrder->payment_status);
        $this->assertSame('paid', $targetOrder->payment_status);
        $this->assertSame('processing', $targetOrder->status);
        $this->assertSame('trk_zsi_12345', $targetOrder->transaction_id);
        $this->assertSame('trk_zsi_12345', $targetOrder->payment_details['zsi_pay_tracking_id'] ?? null);
        $this->assertSame(0, Cart::where('user_id', 42)->count());
        $this->assertSame(1, OrderStatusHistory::where('order_id', $targetOrder->id)->count());
        $this->assertSame(
            'Payment succeeded via ZSI Pay.',
            OrderStatusHistory::where('order_id', $targetOrder->id)->value('description')
        );

        // Second identical webhook delivery must be idempotent
        $secondResponse = $controller->webhook(Request::create('/api/payment/zsipay/webhook', 'POST', $payload));
        $this->assertSame(200, $secondResponse->getStatusCode());
        $this->assertSame(1, OrderStatusHistory::where('order_id', $targetOrder->id)->count());
    }

    public function test_stripe_webhook_switches_tenant_verifies_signature_and_marks_order_paid(): void
    {
        $tenantWebhookSecret = 'whsec_tenant_ebsdeals_secret';
        SiteSetting::setValue('stripe_webhook_secret', $tenantWebhookSecret, 'payments');

        $order = Order::create([
            'order_number' => 'ORD-STRIPE-777',
            'user_id' => 99,
            'total' => 250,
            'payment_status' => 'pending',
            'status' => 'pending',
        ]);

        StripeLog::create([
            'user_id' => 99,
            'type' => 'checkout',
            'session_id' => 'cs_test_tenant_777',
            'amount' => 250,
            'currency' => 'usd',
            'status' => 'unpaid',
        ]);

        Cart::create([
            'user_id' => 99,
            'product_id' => 10,
            'quantity' => 1,
        ]);

        $eventPayload = json_encode([
            'id' => 'evt_test_stripe_1',
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_test_tenant_777',
                    'object' => 'checkout.session',
                    'mode' => 'payment',
                    'payment_status' => 'paid',
                    'payment_intent' => 'pi_test_tenant_777',
                    'subscription' => null,
                    'amount_total' => 25000,
                    'metadata' => [
                        'order_id' => (string) $order->id,
                        'order_number' => 'ORD-STRIPE-777',
                        'tenant_domain' => 'ebsdeals.com',
                        'tenant_db' => 'mustgyyu_ebsdeals',
                    ],
                ],
            ],
        ]);

        $timestamp = time();
        $signedPayload = "{$timestamp}.{$eventPayload}";
        $signature = hash_hmac('sha256', $signedPayload, $tenantWebhookSecret);
        $sigHeader = "t={$timestamp},v1={$signature}";

        $service = new StripeWebhookService();

        // Invalid signature must be rejected with 400
        $badResponse = $service->handleWebhook($eventPayload, "t={$timestamp},v1=bad_signature");
        $this->assertSame(400, $badResponse->getStatusCode());

        // Valid signature must succeed and switch tenant + mark order paid
        $response = $service->handleWebhook($eventPayload, $sigHeader);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('mustgyyu_ebsdeals', Config::get('database.connections.mysql.database'));

        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('processing', $order->status);
        $this->assertSame('pi_test_tenant_777', $order->transaction_id);
        $this->assertSame(0, Cart::where('user_id', 99)->count());
        $this->assertSame(1, OrderStatusHistory::where('order_id', $order->id)->count());
    }

    public function test_quick_payment_webhook_switches_tenant_and_completes_payment(): void
    {
        $payment = QuickPayment::create([
            'name' => 'Tenant Customer',
            'email' => 'customer@bismillahfashionusa.com',
            'reason' => 'Invoice #55',
            'amount' => 75.00,
            'currency' => 'USD',
            'status' => 'pending',
            'payment_method' => 'stripe',
            'transaction_id' => 'cs_quick_tenant_55',
        ]);

        $payload = json_encode([
            'id' => 'evt_quick_1',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_quick_tenant_55',
                    'payment_intent' => 'pi_quick_tenant_55',
                    'metadata' => [
                        'payment_id' => (string) $payment->id,
                        'type' => 'quick_payment',
                        'tenant_domain' => 'bismillahfashionusa.com',
                        'tenant_db' => 'mustgyyu_bismillahfashionusa_db',
                    ],
                ],
            ],
        ]);

        $controller = new QuickPaymentController();
        $request = Request::create('/api/quick-payments/webhook', 'POST', [], [], [], [], $payload);
        $response = $controller->webhook($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('mustgyyu_bismillahfashionusa_db', Config::get('database.connections.mysql.database'));

        $payment->refresh();
        $this->assertSame('completed', $payment->status);
        $this->assertSame('pi_quick_tenant_55', $payment->transaction_id);
    }
}
