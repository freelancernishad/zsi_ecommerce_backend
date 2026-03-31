<?php
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Support\Str;

$products = [
    [
        'name' => 'Nike Air Max Blue Edition',
        'slug' => Str::slug('Nike Air Max Blue Edition'),
        'description' => 'Premium blue athletic sneakers for ultimate comfort and performance.',
        'price' => 129.99,
        'original_price' => 150.00,
        'stock' => 100,
        'status' => 'published',
        'rating' => 4.8,
        'review_count' => 345,
        'brand_id' => 1, // Nike
        'image' => 'products/sneaker_blue.png'
    ],
    [
        'name' => 'Sony Wireless Noise Cancelling Headphones',
        'slug' => Str::slug('Sony Wireless Noise Cancelling Headphones'),
        'description' => 'Industry leading noise canceling over-ear headphones in matte black.',
        'price' => 299.99,
        'original_price' => 349.99,
        'stock' => 50,
        'status' => 'published',
        'rating' => 4.9,
        'review_count' => 890,
        'brand_id' => 9, // Sony (from before)
        'image' => 'products/headphone_wireless.png'
    ],
    [
        'name' => 'Apple Watch Series 9 Black',
        'slug' => Str::slug('Apple Watch Series 9 Black'),
        'description' => 'Advanced health and fitness smartwatch with a sleek black finish.',
        'price' => 399.00,
        'original_price' => 429.00,
        'stock' => 200,
        'status' => 'published',
        'rating' => 4.7,
        'review_count' => 1200,
        'brand_id' => 3, // Apple
        'image' => 'products/smartwatch_black.png'
    ],
    [
        'name' => 'Minimalist Fashion Olive Backpack',
        'slug' => Str::slug('Minimalist Fashion Olive Backpack'),
        'description' => 'Stylish and durable backpack perfect for daily commute and travel.',
        'price' => 79.50,
        'original_price' => 95.00,
        'stock' => 150,
        'status' => 'published',
        'rating' => 4.5,
        'review_count' => 210,
        'brand_id' => null,
        'image' => 'products/fashion_backpack.png'
    ],
];

foreach ($products as $data) {
    try {
        $p = Product::firstOrCreate(
            ['slug' => $data['slug']],
            [
                'name' => $data['name'],
                'description' => $data['description'],
                'price' => $data['price'],
                'original_price' => $data['original_price'],
                'stock_status' => 'in_stock',
                'status' => $data['status'],
                'brand_id' => $data['brand_id']
            ]
        );
        $p->rating = $data['rating'];
        $p->review_count = $data['review_count'];
        $p->save();

        ProductImage::updateOrCreate(
            ['product_id' => $p->id, 'image_path' => $data['image']],
            ['is_primary' => true]
        );

        echo "Seeded: {$p->name}\n";
    } catch (\Exception $e) {
         echo "Error: {$e->getMessage()}\n";
    }
}
