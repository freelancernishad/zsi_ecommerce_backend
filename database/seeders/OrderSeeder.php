<?php

namespace Database\Seeders;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Address;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

class OrderSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Disable foreign key checks to truncate
        Schema::disableForeignKeyConstraints();
        Order::truncate();
        OrderItem::truncate();
        \App\Models\Tax::truncate();
        \App\Models\ShippingMethod::truncate();
        Schema::enableForeignKeyConstraints();

        $user = User::first() ?? User::factory()->create();
        
        // Create Sample Taxes
        \App\Models\Tax::create(['country' => 'USA', 'state' => 'IL', 'tax_rate' => 5.00]);
        \App\Models\Tax::create(['country' => 'USA', 'state' => 'NY', 'tax_rate' => 8.50]);
        \App\Models\Tax::create(['country' => 'USA', 'state' => 'CA', 'tax_rate' => 7.25]);

        // Create Sample Shipping Methods
        $smFedEx = \App\Models\ShippingMethod::create(['name' => 'FedEx Ground', 'cost' => 30.00]);
        $smDHL = \App\Models\ShippingMethod::create(['name' => 'DHL Express', 'cost' => 25.00]);
        $smStandard = \App\Models\ShippingMethod::create(['name' => 'Standard Shipping', 'cost' => 10.00]);
        $shippingMethods = [$smFedEx, $smDHL, $smStandard];

        // Ensure some addresses exist
        $address = Address::where('user_id', $user->id)->first();
        if (!$address) {
            $address = Address::create([
                'user_id' => $user->id,
                'first_name' => 'David',
                'last_name' => 'Warner',
                'email' => $user->email,
                'phone' => '123456789',
                'country' => 'USA',
                'street_address' => 'Flat 20, High Street',
                'city' => 'Chicago',
                'state' => 'IL',
                'zip_code' => '60601',
                'type' => 'shipping',
                'is_default' => true
            ]);
        }

        $products = Product::take(10)->get();
        if ($products->isEmpty()) {
            $products = Product::factory(10)->create();
        }

        $statuses = [
            ['payment' => 'paid', 'fulfillment' => 'unfulfilled', 'delivery' => 'pending'],
            ['payment' => 'unpaid', 'fulfillment' => 'unfulfilled', 'delivery' => 'processing'],
            ['payment' => 'paid', 'fulfillment' => 'fulfilled', 'delivery' => 'shipped'],
            ['payment' => 'paid', 'fulfillment' => 'fulfilled', 'delivery' => 'delivered'],
            ['payment' => 'refunded', 'fulfillment' => 'fulfilled', 'delivery' => 'returned'],
            ['payment' => 'unpaid', 'fulfillment' => 'unfulfilled', 'delivery' => 'cancelled'],
        ];

        for ($i = 1; $i <= 20; $i++) {
            $s = $statuses[array_rand($statuses)];
            
            // Random items first to calculate subtotal
            $itemCount = rand(1, 4);
            $itemsData = [];
            $subtotal = 0;
            
            for ($j = 0; $j < $itemCount; $j++) {
                $p = $products->random();
                $qty = rand(1, 5);
                $price = $p->price ?? 50.00;
                $itemsData[] = [
                    'product_id' => $p->id,
                    'quantity' => $qty,
                    'price' => $price,
                ];
                $subtotal += ($price * $qty);
            }

            $discount = rand(0, 100);
            
            // Re-fetch or create a specific address state for better testing
            $targetStates = ['IL', 'NY', 'CA'];
            $orderState = $targetStates[array_rand($targetStates)];
            
            // Find tax for this state
            $taxRecord = \App\Models\Tax::where('state', $orderState)->first();
            $taxRate = $taxRecord ? $taxRecord->tax_rate : 5.00;
            $taxAmount = $subtotal * ($taxRate / 100);

            // Select shipping method
            $sm = $shippingMethods[array_rand($shippingMethods)];
            $shippingCost = $sm->cost;
            
            $total = $subtotal + $shippingCost + $taxAmount - $discount;

            $order = Order::create([
                'order_number' => 'ORD-' . strtoupper(bin2hex(random_bytes(3))),
                'user_id' => $user->id,
                'subtotal' => $subtotal,
                'discount' => $discount,
                'shipping_cost' => $shippingCost,
                'tax' => $taxAmount,
                'total' => $total,
                'status' => $s['delivery'],
                'payment_method' => 'visa',
                'payment_method_name' => 'Visa 4242',
                'payment_gateway' => 'Stripe',
                'transaction_id' => 'ch_' . bin2hex(random_bytes(12)),
                'payment_details' => json_encode(['brand' => 'visa', 'last4' => '4242', 'exp_month' => 12, 'exp_year' => 2026]),
                'shipping_method' => $sm->name,
                'payment_status' => $s['payment'],
                'fulfillment_status' => $s['fulfillment'],
                'delivery_status' => $s['delivery'],
                'shipping_address_id' => $address->id,
                'shipping_first_name' => $address->first_name,
                'shipping_last_name' => $address->last_name,
                'shipping_email' => $address->email,
                'shipping_phone' => $address->phone,
                'shipping_country' => $address->country,
                'shipping_street_address' => $address->street_address,
                'shipping_city' => $address->city,
                'shipping_state' => $address->state,
                'shipping_zip_code' => $address->zip_code,
            ]);

            foreach ($itemsData as $item) {
                OrderItem::create(array_merge($item, ['order_id' => $order->id]));
            }
        }
    }
}
