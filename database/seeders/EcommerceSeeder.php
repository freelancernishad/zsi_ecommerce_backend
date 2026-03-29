<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class EcommerceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            ['name' => 'Shoes', 'slug' => 'shoes'],
            ['name' => 'Clothing', 'slug' => 'clothing'],
            ['name' => 'Electronics', 'slug' => 'electronics'],
            ['name' => 'Accessories', 'slug' => 'accessories'],
        ];

        foreach ($categories as $cat) {
            $category = \App\Models\Category::updateOrCreate(['slug' => $cat['slug']], $cat);

            // Create 2 Subcategories for each main category
            for ($s = 1; $s <= 2; $s++) {
                $subCategory = \App\Models\Category::create([
                    'name' => "{$category->name} Sub {$s}",
                    'slug' => strtolower($category->name) . "-sub-{$s}-" . time(),
                    'parent_id' => $category->id,
                    'status' => 'active'
                ]);

                // Create products for subcategory
                for ($i = 1; $i <= 3; $i++) {
                    $product = \App\Models\Product::create([
                        'name' => "{$subCategory->name} Item {$i}",
                        'slug' => "{$subCategory->slug}-item-{$i}-" . time(),
                        'description' => "This is a detailed description for {$subCategory->name} item {$i}.",
                        'price' => rand(500, 5000),
                        'original_price' => rand(600, 6000),
                        'stock_quantity' => rand(10, 100),
                        'category_id' => $subCategory->id,
                        'status' => 'published',
                    ]);

                    \App\Models\ProductImage::create([
                        'product_id' => $product->id,
                        'image_path' => 'https://via.placeholder.com/600x600?text=' . urlencode($product->name),
                        'is_primary' => true,
                    ]);
                }
            }

            // Products for main category too
            for ($i = 1; $i <= 3; $i++) {

                // Dummy Image
                \App\Models\ProductImage::create([
                    'product_id' => $product->id,
                    'image_path' => 'https://via.placeholder.com/600x600?text=' . urlencode($product->name),
                    'is_primary' => true,
                ]);

                // Dummy Variant
                \App\Models\ProductVariant::create([
                    'product_id' => $product->id,
                    'color' => 'Black',
                    'size' => 'XL',
                    'stock' => 10,
                ]);
            }
        }
        // Create Some Dummy Orders to populate Total Sales
        $products = \App\Models\Product::all();
        $admin = \App\Models\Admin::first();
        $user = \App\Models\User::first() ?: \App\Models\User::factory()->create();

        for ($j = 1; $j <= 10; $j++) {
            $order = \App\Models\Order::create([
                'order_number' => 'ORD-' . strtoupper(bin2hex(random_bytes(4))),
                'user_id' => $user->id,
                'subtotal' => 0,
                'total' => 0,
                'status' => 'delivered',
                'payment_method' => 'cod',
            ]);

            $subtotal = 0;
            // Add 1-3 items per order
            $randomProducts = $products->random(rand(1, 3));
            foreach ($randomProducts as $prod) {
                $qty = rand(1, 5);
                $price = $prod->price;
                \App\Models\OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $prod->id,
                    'quantity' => $qty,
                    'price' => $price,
                ]);
                $subtotal += ($qty * $price);
            }

            $order->update([
                'subtotal' => $subtotal,
                'total' => $subtotal + 50, // + shipping
            ]);
        }
    }
}
