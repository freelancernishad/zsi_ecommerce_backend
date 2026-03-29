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

            for ($i = 1; $i <= 5; $i++) {
                $product = \App\Models\Product::create([
                    'name' => "{$category->name} Product {$i}",
                    'slug' => "{$category->slug}-product-{$i}-" . time(),
                    'description' => "This is a detailed description for {$category->name} item {$i}.",
                    'price' => rand(500, 5000),
                    'original_price' => rand(600, 6000),
                    'stock_quantity' => rand(10, 100),
                    'category_id' => $category->id,
                    'status' => 'published',
                ]);

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
    }
}
