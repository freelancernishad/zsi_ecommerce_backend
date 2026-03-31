<?php

use App\Models\Brand;
use App\Models\Product;
use Illuminate\Support\Str;

$brandsData = [
    ['name' => 'Nike', 'description' => 'Just Do It', 'show_on_home' => true, 'home_order' => 1],
    ['name' => 'Adidas', 'description' => 'Impossible is Nothing', 'show_on_home' => true, 'home_order' => 2],
    ['name' => 'Apple', 'description' => 'Think Different', 'show_on_home' => true, 'home_order' => 3],
    ['name' => 'Samsung', 'description' => 'Do What You Can\'t', 'show_on_home' => true, 'home_order' => 4],
    ['name' => 'Sony', 'description' => 'Make.Believe', 'show_on_home' => false, 'home_order' => 0],
    ['name' => 'Puma', 'description' => 'Forever Faster', 'show_on_home' => true, 'home_order' => 5],
];

foreach ($brandsData as $data) {
    try {
        $brand = Brand::updateOrCreate(
            ['slug' => Str::slug($data['name'])],
            [
                'name' => $data['name'],
                'description' => $data['description'],
                'status' => 'active',
                'show_on_home' => $data['show_on_home'],
                'home_order' => $data['home_order']
            ]
        );
        echo "Created/Updated Brand: {$brand->name}\n";
    } catch (\Exception $e) {
        echo "Error creating brand {$data['name']}: " . $e->getMessage() . "\n";
    }
}

// Map some products to these brands randomly
$allBrands = Brand::all();
$products = Product::limit(30)->get();

if ($allBrands->count() > 0 && $products->count() > 0) {
    foreach ($products as $product) {
        $brand = $allBrands->random();
        $product->brand_id = $brand->id;
        $product->save();
        echo "Linked Product '{$product->name}' to Brand '{$brand->name}'\n";
    }
} else {
    echo "Warning: No brands or products found to link.\n";
}
