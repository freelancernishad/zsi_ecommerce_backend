<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Product;
use App\Models\Address;
use App\Models\Wishlist;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Review;
use App\Models\UserPaymentMethod;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UserDashboardSeeder extends Seeder
{
    public function run()
    {
        $email = 'freelancernishad123@gmail.com';
        $user = User::where('email', $email)->first();

        if (!$user) {
            $this->command->error("User with email {$email} not found!");
            return;
        }

        $userId = $user->id;
        $products = Product::where('status', 'published')->orWhere('status', 'active')->limit(15)->get();

        if ($products->count() < 10) {
            $products = Product::limit(15)->get(); // Final fallback
        }
        
        if ($products->count() === 0) {
            $this->command->error("No products found in DB!");
            return;
        }

        // 1. Seed Addresses (Keep existing)
        Address::updateOrCreate(
            ['user_id' => $userId, 'type' => 'shipping'],
            [
                'first_name' => 'Nishad',
                'last_name' => 'Majumder',
                'email' => $email,
                'phone' => '01700000000',
                'street_address' => 'House 12, Road 5, Block B',
                'city' => 'Dhaka',
                'state' => 'Dhaka',
                'zip_code' => '1212',
                'country' => 'Bangladesh',
                'is_default' => true
            ]
        );

        Address::updateOrCreate(
            ['user_id' => $userId, 'type' => 'billing'],
            [
                'first_name' => 'Nishad',
                'last_name' => 'Freelancer',
                'email' => $email,
                'phone' => '01800000000',
                'street_address' => 'ZSI Tech, Level 4, IT Plaza',
                'city' => 'Chittagong',
                'state' => 'Chittagong',
                'zip_code' => '4000',
                'country' => 'Bangladesh',
                'is_default' => false
            ]
        );

        // 2. Seed Wishlist (Keep existing)
        foreach ($products->slice(0, 3) as $p) {
            Wishlist::updateOrCreate([
                'user_id' => $userId,
                'product_id' => $p->id
            ]);
        }

        // 3. Seed Orders for all statuses
        
        // --- DELIVERED (Reviewed) ---
        $orderDelivered = Order::create([
            'user_id' => $userId,
            'order_number' => 'ORD-' . strtoupper(uniqid()),
            'status' => 'delivered',
            'subtotal' => 500,
            'discount' => 50,
            'shipping_cost' => 60,
            'tax' => 25,
            'total' => 535,
            'payment_method' => 'Visa',
            'shipping_street_address' => 'House 12, Road 5, Block B',
            'shipping_city' => 'Dhaka',
            'shipping_zip_code' => '1212',
            'shipping_country' => 'Bangladesh',
        ]);
        OrderItem::create(['order_id' => $orderDelivered->id, 'product_id' => $products[0]->id, 'quantity' => 1, 'price' => $products[0]->price]);
        OrderStatusHistory::create(['order_id' => $orderDelivered->id, 'status' => 'pending', 'description' => 'Order placed.', 'location' => 'System']);
        OrderStatusHistory::create(['order_id' => $orderDelivered->id, 'status' => 'delivered', 'description' => 'Delivered to customer.', 'location' => 'Front Door']);
        Review::create(['user_id' => $userId, 'product_id' => $products[0]->id, 'rating' => 5, 'comment' => 'Great product!', 'status' => 'active']);

        // --- DELIVERED (Pending Review) ---
        $orderPendingReview = Order::create([
            'user_id' => $userId,
            'order_number' => 'ORD-' . strtoupper(uniqid()),
            'status' => 'delivered',
            'subtotal' => 120,
            'discount' => 0,
            'shipping_cost' => 40,
            'tax' => 10,
            'total' => 170,
            'payment_method' => 'MasterCard',
            'shipping_street_address' => 'House 12, Road 5, Block B',
            'shipping_city' => 'Dhaka',
            'shipping_zip_code' => '1212',
            'shipping_country' => 'Bangladesh',
        ]);
        OrderItem::create(['order_id' => $orderPendingReview->id, 'product_id' => $products[1]->id, 'quantity' => 1, 'price' => $products[1]->price]);
        OrderStatusHistory::create(['order_id' => $orderPendingReview->id, 'status' => 'pending', 'description' => 'Order confirmed.', 'location' => 'System']);
        OrderStatusHistory::create(['order_id' => $orderPendingReview->id, 'status' => 'delivered', 'description' => 'Arrived at destination.', 'location' => 'Client Location']);

        // --- PENDING ---
        $orderPending = Order::create([
            'user_id' => $userId,
            'order_number' => 'ORD-' . strtoupper(uniqid()),
            'status' => 'pending',
            'subtotal' => 200,
            'discount' => 0,
            'shipping_cost' => 50,
            'tax' => 15,
            'total' => 265,
            'payment_method' => 'Cash on Delivery',
            'shipping_street_address' => 'House 12, Road 5, Block B',
            'shipping_city' => 'Dhaka',
            'shipping_zip_code' => '1212',
            'shipping_country' => 'Bangladesh',
        ]);
        OrderItem::create(['order_id' => $orderPending->id, 'product_id' => $products[2]->id, 'quantity' => 1, 'price' => $products[2]->price]);
        OrderStatusHistory::create(['order_id' => $orderPending->id, 'status' => 'pending', 'description' => 'Awaiting payment confirmation.', 'location' => 'System']);

        // --- PROCESSING ---
        $orderProcessing = Order::create([
            'user_id' => $userId,
            'order_number' => 'ORD-' . strtoupper(uniqid()),
            'status' => 'processing',
            'subtotal' => 750,
            'discount' => 0,
            'shipping_cost' => 60,
            'tax' => 45,
            'total' => 855,
            'payment_method' => 'Visa',
            'shipping_street_address' => 'House 12, Road 5, Block B',
            'shipping_city' => 'Dhaka',
            'shipping_zip_code' => '1212',
            'shipping_country' => 'Bangladesh',
        ]);
        OrderItem::create(['order_id' => $orderProcessing->id, 'product_id' => $products[3]->id, 'quantity' => 1, 'price' => $products[3]->price]);
        OrderStatusHistory::create(['order_id' => $orderProcessing->id, 'status' => 'pending', 'description' => 'Order received.', 'location' => 'System']);
        OrderStatusHistory::create(['order_id' => $orderProcessing->id, 'status' => 'processing', 'description' => 'Packing your items.', 'location' => 'Warehouse A']);

        // --- SHIPPED ---
        $orderShipped = Order::create([
            'user_id' => $userId,
            'order_number' => 'ORD-' . strtoupper(uniqid()),
            'status' => 'shipped',
            'subtotal' => 300,
            'discount' => 0,
            'shipping_cost' => 60,
            'tax' => 20,
            'total' => 380,
            'payment_method' => 'Visa',
            'shipping_street_address' => 'House 12, Road 5, Block B',
            'shipping_city' => 'Dhaka',
            'shipping_zip_code' => '1212',
            'shipping_country' => 'Bangladesh',
        ]);
        OrderItem::create(['order_id' => $orderShipped->id, 'product_id' => $products[4]->id, 'quantity' => 1, 'price' => $products[4]->price]);
        OrderStatusHistory::create(['order_id' => $orderShipped->id, 'status' => 'pending', 'description' => 'Order ready.', 'location' => 'System']);
        OrderStatusHistory::create(['order_id' => $orderShipped->id, 'status' => 'shipped', 'description' => 'Package is on the way.', 'location' => 'Transit Hub']);

        // --- CANCELLED ---
        $orderCancelled = Order::create([
            'user_id' => $userId,
            'order_number' => 'ORD-' . strtoupper(uniqid()),
            'status' => 'cancelled',
            'subtotal' => 450,
            'discount' => 0,
            'shipping_cost' => 0,
            'tax' => 0,
            'total' => 450,
            'payment_method' => 'Visa',
            'shipping_street_address' => 'House 12, Road 5, Block B',
            'shipping_city' => 'Dhaka',
            'shipping_zip_code' => '1212',
            'shipping_country' => 'Bangladesh',
        ]);
        OrderItem::create(['order_id' => $orderCancelled->id, 'product_id' => $products[5]->id, 'quantity' => 1, 'price' => $products[5]->price]);
        OrderStatusHistory::create(['order_id' => $orderCancelled->id, 'status' => 'pending', 'description' => 'Order initiated.', 'location' => 'System']);
        OrderStatusHistory::create(['order_id' => $orderCancelled->id, 'status' => 'cancelled', 'description' => 'Cancelled by user.', 'location' => 'System']);

        // --- REFUNDED (For Returns tab) ---
        // Note: Logic in frontend checks if status contains 'refund' or 'return'
        $orderRefunded = Order::create([
            'user_id' => $userId,
            'order_number' => 'ORD-' . strtoupper(uniqid()),
            'status' => 'refunded',
            'subtotal' => 600,
            'discount' => 0,
            'shipping_cost' => 0,
            'tax' => 0,
            'total' => 600,
            'payment_method' => 'Visa',
            'shipping_street_address' => 'House 12, Road 5, Block B',
            'shipping_city' => 'Dhaka',
            'shipping_zip_code' => '1212',
            'shipping_country' => 'Bangladesh',
        ]);
        OrderItem::create(['order_id' => $orderRefunded->id, 'product_id' => $products[6]->id, 'quantity' => 1, 'price' => $products[6]->price]);
        OrderStatusHistory::create(['order_id' => $orderRefunded->id, 'status' => 'delivered', 'description' => 'Delivered initially.', 'location' => 'Client']);
        OrderStatusHistory::create(['order_id' => $orderRefunded->id, 'status' => 'refunded', 'description' => 'Return processed and refunded.', 'location' => 'Finance Dept']);

        // 5. Seed Payment Methods (Keep existing)
        UserPaymentMethod::updateOrCreate(
            ['user_id' => $userId, 'last4' => '4242'],
            [
                'type' => 'Visa',
                'expiry_date' => '12/28',
                'cardholder_name' => 'Nishad Majumder',
                'is_default' => true
            ]
        );

        UserPaymentMethod::updateOrCreate(
            ['user_id' => $userId, 'last4' => '5555'],
            [
                'type' => 'MasterCard',
                'expiry_date' => '05/30',
                'cardholder_name' => 'Nishad Majumder',
                'is_default' => false
            ]
        );

        $this->command->info("Comprehensive Dashboard seed data for {$email} created successfully!");
    }
}
