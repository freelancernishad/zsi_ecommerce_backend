<?php

use App\Models\Brand;
use App\Models\Product;
use Illuminate\Support\Str;

$brandsData = [
    ['name' => 'Nike', 'description' => 'Just Do It', 'logo' => 'brands/nike.png', 'show_on_home' => true, 'home_order' => 1],
    ['name' => 'Adidas', 'description' => 'Impossible is Nothing', 'logo' => 'brands/adidas.png', 'show_on_home' => true, 'home_order' => 2],
    ['name' => 'Apple', 'description' => 'Think Different', 'logo' => 'brands/apple.png', 'show_on_home' => true, 'home_order' => 3],
    ['name' => 'Samsung', 'description' => 'Do What You Can\'t', 'logo' => 'brands/samsung.png', 'show_on_home' => true, 'home_order' => 4],
    ['name' => 'Rolex', 'description' => 'A Crown for Every Achievement', 'logo' => 'brands/rolex.png', 'show_on_home' => true, 'home_order' => 5],
    ['name' => 'Gucci', 'description' => 'Quality is remembered long after price is forgotten', 'logo' => 'brands/gucci.png', 'show_on_home' => true, 'home_order' => 6],
    ['name' => 'Toyota', 'description' => 'Let\'s Go Places', 'logo' => 'brands/toyota.png', 'show_on_home' => true, 'home_order' => 7],
    ['name' => 'Mercedes', 'description' => 'The Best or Nothing', 'logo' => 'brands/mercedes.png', 'show_on_home' => true, 'home_order' => 8],
    ['name' => 'Sony', 'description' => 'Make.Believe', 'logo' => null, 'show_on_home' => false, 'home_order' => 0],
    ['name' => 'Puma', 'description' => 'Forever Faster', 'logo' => null, 'show_on_home' => true, 'home_order' => 9],
];

foreach ($brandsData as $data) {
    try {
        $brand = Brand::updateOrCreate(
            ['slug' => Str::slug($data['name'])],
            [
                'name' => $data['name'],
                'description' => $data['description'],
                'logo' => $data['logo'],
                'status' => 'active',
                'show_on_home' => $data['show_on_home'],
                'home_order' => $data['home_order']
            ]
        );
        echo "Created/Updated Brand: {$brand->name} with logo: {$brand->logo}\n";
    } catch (\Exception $e) {
        echo "Error creating brand {$data['name']}: " . $e->getMessage() . "\n";
    }
}

// Map some products to these new brands randomly
$allBrands = Brand::all();
$products = Product::whereNull('brand_id')->orWhereIn('brand_id', [1, 2, 3, 4, 5, 6])->get();

if ($allBrands->count() > 0 && $products->count() > 0) {
    foreach ($products as $product) {
        $brand = $allBrands->random();
        $product->brand_id = $brand->id;
        $product->save();
        echo "Linked Product '{$product->name}' to Brand '{$brand->name}'\n";
    }
}
