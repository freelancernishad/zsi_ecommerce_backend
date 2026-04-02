<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use App\Models\User;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Review;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ShippingMethod;
use App\Models\Tax;
use App\Models\SupportAndConnect\Ticket\SupportTicket;
use App\Models\SupportAndConnect\Contacts\ContactMessage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File as FacadesFile;
use App\Services\FileSystem\FileUploadService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Faker\Factory as Faker;

class EcommerceSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('🚀 Starting Massive Ecommerce Seeder (Large Volume)...');

        $faker = Faker::create();
        $fileUploadService = app(FileUploadService::class);
        $directory = base_path('products_images');

        // 1. Group Local Images & Upload once
        $files = FacadesFile::exists($directory) ? FacadesFile::files($directory) : [];
        $imageGroups = [];
        foreach ($files as $file) {
            $filename = basename($file->getFilename(), '.' . $file->getExtension());
            $baseNameStr = preg_replace('/(\s|-)*\d+(-\d+)?$/', '', $filename) ?: $filename;
            $imageGroups[$baseNameStr][] = $file;
        }

        $this->command->info("Phase 1: Uploading baseline images to S3...");
        $cachedS3Paths = [];
        foreach ($imageGroups as $baseName => $groupFiles) {
            foreach ($groupFiles as $file) {
                try {
                    $uploadedFile = new UploadedFile($file->getPathname(), $file->getFilename(), mime_content_type($file->getPathname()), null, true);
                    $path = $fileUploadService->uploadFileToS3($uploadedFile, 'products/images');
                    $cachedS3Paths[$baseName][] = $path;
                } catch (\Exception $e) {}
            }
        }
        if (empty($cachedS3Paths)) $cachedS3Paths['Placeholder Item'][] = 'https://via.placeholder.com/600x600?text=Placeholder';
        $baseNameKeys = array_keys($cachedS3Paths);

        // 2. Create Users (500)
        $this->command->info("Phase 2: Creating 500 Users...");
        User::factory()->count(500)->create();
        $allUserIds = User::pluck('id')->toArray();

        // 3. Create Categories (50 Total)
        $this->command->info("Phase 3: Creating 50 Categories...");
        $mainCategoriesList = [
            'Fashion', 'Electronics', 'Home & Living', 'Health & Beauty', 'Sports & Outdoors',
            'Toys & Games', 'Automotive', 'Books', 'Grocery', 'Pet Supplies'
        ];
        
        $createdCategories = [];
        foreach ($mainCategoriesList as $mainCat) {
            $parent = Category::updateOrCreate(['slug' => Str::slug($mainCat)], [
                'name' => $mainCat, 
                'status' => 'active', 
                'show_on_home' => 1,
                'image' => $cachedS3Paths[$baseNameKeys[array_rand($baseNameKeys)]][0]
            ]);
            
            // 4 subcategories for each
            for ($k = 0; $k < 4; $k++) {
                $subName = $faker->word . ' ' . $mainCat;
                $createdCategories[] = Category::create([
                    'name' => Str::title($subName),
                    'slug' => Str::slug($subName) . '-' . uniqid(),
                    'parent_id' => $parent->id,
                    'status' => 'active',
                    'image' => $cachedS3Paths[$baseNameKeys[array_rand($baseNameKeys)]][0]
                ]);
            }
        }
        $categoryIds = collect($createdCategories)->pluck('id')->toArray();

        // 4. Shipping & Taxes
        $this->command->info("Phase 4: Seeding Brands, Shipping and Taxes...");

        $brandNames = ['Aeternum', 'Orange', 'Biogas', 'Aurora Vogue', 'Jeans & Co', 'Floralia', 'Elaria', 'Greenscape', 'Nike', 'Apple', 'Samsung', 'Gucci', 'Prada'];
        $createdBrands = [];
        foreach ($brandNames as $bName) {
            $createdBrands[] = \App\Models\Brand::create([
                'name' => $bName,
                'slug' => Str::slug($bName) . '-' . uniqid(),
                'logo' => $cachedS3Paths[$baseNameKeys[array_rand($baseNameKeys)]][0],
                'status' => 'active',
                'show_on_home' => 1,
                'home_order' => rand(1, 20)
            ]);
        }
        $brandIds = collect($createdBrands)->pluck('id')->toArray();

        ShippingMethod::updateOrCreate(['name' => 'Standard Shipping'], ['cost' => 50, 'is_active' => true]);
        ShippingMethod::updateOrCreate(['name' => 'Express Shipping'], ['cost' => 150, 'is_active' => true]);
        ShippingMethod::updateOrCreate(['name' => 'Free Shipping'], ['cost' => 0, 'is_active' => true]);

        $states = ['Dhaka', 'Chittagong', 'New York', 'California', 'Texas', 'London', 'Berlin'];
        foreach ($states as $state) {
            Tax::updateOrCreate(['state' => $state], ['country' => 'Global', 'tax_rate' => rand(5, 15)]);
        }

        // 5. Products (1500)
        $this->command->info("Phase 5: Generating 1500 Products...");
        $bar = $this->command->getOutput()->createProgressBar(1500);
        $bar->start();

        $allProductIds = [];
        for ($i = 0; $i < 1500; $i++) {
            $randomBase = $baseNameKeys[array_rand($baseNameKeys)];
            $s3Urls = $cachedS3Paths[$randomBase];
            $hName = Str::title(str_replace('-', ' ', $randomBase));
            $catchphrase = $faker->catchPhrase;
            $productName = $catchphrase . ": " . $hName;
            $slugBase = \Illuminate\Support\Str::limit($catchphrase . " " . $hName, 40, '');

            $product = Product::create([
                'name' => $productName,
                'slug' => Str::slug($slugBase) . '-' . uniqid(),
                'description' => $faker->paragraphs(3, true),
                'short_description' => $faker->sentence(10),
                'price' => $faker->randomFloat(2, 50, 5000),
                'original_price' => $faker->randomFloat(2, 60, 6000),
                'stock_quantity' => rand(5, 200),
                'category_id' => $categoryIds[array_rand($categoryIds)],
                'brand_id' => $brandIds[array_rand($brandIds)],
                'status' => 'published',
                'sku' => strtoupper($faker->bothify('???-#####')),
                'rating' => 0, // Will be updated by reviews booted method or manual calc
                'review_count' => 0
            ]);

            foreach ($s3Urls as $idx => $url) {
                ProductImage::create(['product_id' => $product->id, 'image_path' => $url, 'is_primary' => $idx === 0]);
            }

            // Variants
            if (rand(1, 100) <= 60) {
                $product->update(['variant_options' => [
                    ['name' => 'Size', 'values' => (array) $faker->randomElements(['S', 'M', 'L', 'XL'], 3)],
                    ['name' => 'Color', 'values' => (array) $faker->randomElements(['Red', 'Green', 'Blue', 'Black'], 2)]
                ]]);
            }

            $allProductIds[] = $product->id;
            $bar->advance();
        }
        $bar->finish();
        $this->command->newLine();

        // 6. Reviews (20-150 per product = ~100k reviews)
        $this->command->info("Phase 6: Generating massive Reviews (using batch inserts)...");
        $bar = $this->command->getOutput()->createProgressBar(count($allProductIds));
        $bar->start();

        foreach ($allProductIds as $pId) {
            $reviewCount = rand(20, 150);
            $batch = [];
            for ($r = 0; $r < $reviewCount; $r++) {
                $batch[] = [
                    'product_id' => $pId,
                    'user_id' => $allUserIds[array_rand($allUserIds)],
                    'rating' => rand(3, 5),
                    'comment' => $faker->sentence(rand(5, 20)),
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                
                if (count($batch) >= 150) {
                    DB::table('reviews')->insert($batch);
                    $batch = [];
                }
            }
            if (count($batch) > 0) DB::table('reviews')->insert($batch);
            
            // Update product counts manually to avoid booted overhead
            Product::where('id', $pId)->update([
                'review_count' => $reviewCount,
                'rating' => rand(40, 50) / 10 // Mock rating
            ]);
            
            $bar->advance();
        }
        $bar->finish();
        $this->command->newLine();

        // 7. Orders (300)
        $this->command->info("Phase 7: Generating 300 Orders...");
        for ($o = 0; $o < 300; $o++) {
            $order = Order::create([
                'order_number' => 'ORD-' . strtoupper(uniqid()),
                'user_id' => $allUserIds[array_rand($allUserIds)],
                'subtotal' => 0,
                'total' => 0,
                'status' => collect(['pending', 'processing', 'shipped', 'delivered'])->random(),
                'payment_method' => 'cod',
                'created_at' => $faker->dateTimeBetween('-4 months', 'now'),
            ]);

            $st = 0;
            $pItems = collect($allProductIds)->random(rand(1, 4));
            foreach ($pItems as $pid) {
                $qty = rand(1, 2);
                $pr = rand(100, 1000);
                OrderItem::create(['order_id' => $order->id, 'product_id' => $pid, 'quantity' => $qty, 'price' => $pr]);
                $st += ($qty * $pr);
            }
            $order->update(['subtotal' => $st, 'total' => $st + 50]);
        }

        // 8. Support Tickets (100)
        $this->command->info("Phase 8: Generating 100 Support Tickets...");
        for ($s = 0; $s < 100; $s++) {
            SupportTicket::create([
                'user_id' => $allUserIds[array_rand($allUserIds)],
                'subject' => $faker->sentence,
                'message' => $faker->paragraph,
                'status' => $faker->randomElement(['open', 'closed', 'pending']),
                'priority' => $faker->randomElement(['low', 'medium', 'high']),
            ]);
        }

        // 9. Site Settings (Homepage)
        $this->command->info("Phase 9: Updating Homepage Site Settings with S3 Images...");
        
        // Home Hero
        \App\Models\SiteSetting::setValue('home_hero', [
            'title' => 'MASSIVE SCALE SALE',
            'subtitle' => 'Explore 1500+ New Products',
            'discount' => '70%',
            'image' => $cachedS3Paths[$baseNameKeys[array_rand($baseNameKeys)]][0]
        ], 'home', 'json');

        // Home Brands (Top Picks)
        $homeBrandsData = [];
        foreach ($createdBrands as $b) {
            $homeBrandsData[] = [
                'id' => $b->id,
                'name' => $b->name,
                'image' => $b->logo
            ];
        }
        \App\Models\SiteSetting::setValue('home_brands', array_slice($homeBrandsData, 0, 8), 'home', 'json');

        // 10. Site Navigation (Advanced Menu System)
        $navigationData = [
            [
                'id' => 'nav-1',
                'label' => 'Deal Of The Day',
                'type' => 'custom',
                'url' => '/products?deal=true',
                'badge' => 'Hot'
            ],
            [
                'id' => 'nav-2',
                'label' => 'New Arrival',
                'type' => 'custom',
                'url' => '/products?sort=newest',
                'badge' => 'New'
            ],
            [
                'id' => 'nav-3',
                'label' => 'On Sale',
                'type' => 'custom',
                'url' => '/products?sale=true',
                'badge' => 'Sale'
            ]
        ];

        // Add top 4 categories to the main bar
        $topNavCategories = Category::whereNull('parent_id')->where('status', 'active')->limit(4)->get();
        foreach ($topNavCategories as $cat) {
            $navigationData[] = [
                'id' => 'cat-' . $cat->id,
                'label' => $cat->name,
                'type' => 'category',
                'resource_slug' => $cat->slug,
                'badge' => ($cat->name === 'Electronics') ? 'Tech' : null
            ];
        }

        \App\Models\SiteSetting::setValue('site_navigation', $navigationData, 'home', 'json');

        $this->command->info("✅ Massive Seeding & Site Settings Completed Successfully!");
    }
}
