<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Category;
use Illuminate\Support\Str;

class ProductSystemSeeder extends Seeder
{
    public function run(): void
    {
        $products = [
            [
                'name' => 'iPhone 15 Pro Max',
                'category' => 'Electronics',
                'price' => 1199,
                'stock' => 45,
                'image' => 'https://images.unsplash.com/photo-1696446701796-da61225697cc?q=80&w=800&auto=format&fit=crop',
                'description' => 'Titanium design, A17 Pro chip, 48MP Main camera.',
            ],
            [
                'name' => 'Nike Air Jordan 1 Low',
                'category' => 'Shoes',
                'price' => 115,
                'stock' => 12,
                'image' => 'https://images.unsplash.com/photo-1600185365483-26d7a4cc7519?q=80&w=800&auto=format&fit=crop',
                'description' => 'Classic style meet modern comfort for all-day wear.',
            ],
            [
                'name' => 'Sony WH-1000XM5',
                'category' => 'Electronics',
                'price' => 399,
                'stock' => 5,
                'image' => 'https://images.unsplash.com/photo-1618366712010-8c0e2e0551ca?q=80&w=800&auto=format&fit=crop',
                'description' => 'Industry-leading noise cancellation and premium sound.',
            ],
            [
                'name' => 'Men Relaxed Fit T-Shirt',
                'category' => 'Clothing',
                'price' => 29,
                'stock' => 150,
                'image' => 'https://images.unsplash.com/photo-1521572163474-6864f9cf17ab?q=80&w=800&auto=format&fit=crop',
                'description' => 'Comfortable and stylish for everyday casual wear.',
            ],
            [
                'name' => 'Classic Leather Backpack',
                'category' => 'Accessories',
                'price' => 89,
                'stock' => 20,
                'image' => 'https://images.unsplash.com/photo-1548036328-c9fa89d128fa?q=80&w=800&auto=format&fit=crop',
                'description' => 'Durable leather backpack with multiple compartments.',
            ]
        ];

        foreach ($products as $p) {
            $category = Category::where('name', $p['category'])->first();
            if (!$category) continue;

            $product = Product::create([
                'name' => $p['name'],
                'slug' => Str::slug($p['name']) . '-' . time(),
                'description' => $p['description'],
                'short_description' => 'Premium quality ' . $p['name'],
                'price' => $p['price'],
                'original_price' => $p['price'] + 20,
                'stock_quantity' => $p['stock'],
                'category_id' => $category->id,
                'sku' => strtoupper(substr($p['category'], 0, 3)) . '-' . rand(1000, 9999),
                'status' => $p['stock'] < 10 ? 'draft' : 'published',
            ]);

            ProductImage::create([
                'product_id' => $product->id,
                'image_path' => $p['image'],
                'is_primary' => true,
            ]);
        }
    }
}
