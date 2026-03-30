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
        Schema::enableForeignKeyConstraints();

        $user = User::first() ?? User::factory()->create();
        
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
            
            // Random shipping method and cost
            $shippingMethods = [
                ['name' => 'FedEx', 'cost' => 30.00],
                ['name' => 'DHL', 'cost' => 25.00],
                ['name' => 'Express', 'cost' => 20.00],
                ['name' => 'Standard', 'cost' => 10.00],
            ];
            $sm = $shippingMethods[array_rand($shippingMethods)];
            $shipping = $sm['cost'];
            
            // Calculate Tax (5%)
            $tax = $subtotal * 0.05;
            
            $total = $subtotal + $shipping + $tax - $discount;

            $order = Order::create([
                'order_number' => 'ORD-' . strtoupper(bin2hex(random_bytes(3))),
                'user_id' => $user->id,
                'subtotal' => $subtotal,
                'discount' => $discount,
                'shipping_cost' => $shipping,
                'tax' => $tax,
                'total' => $total,
                'status' => $s['delivery'],
                'payment_method' => 'visa',
                'shipping_method' => $sm['name'],
                'payment_status' => $s['payment'],
                'fulfillment_status' => $s['fulfillment'],
                'delivery_status' => $s['delivery'],
                'shipping_address_id' => $address->id,
            ]);

            foreach ($itemsData as $item) {
                OrderItem::create(array_merge($item, ['order_id' => $order->id]));
            }
        }
    }
}
