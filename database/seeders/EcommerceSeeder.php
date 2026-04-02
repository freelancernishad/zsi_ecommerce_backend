<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Review;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File as FacadesFile;
use App\Services\FileSystem\FileUploadService;
use Illuminate\Support\Facades\Log;
use Faker\Factory as Faker;

class EcommerceSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Starting Massive Ecommerce Seeder (1500 Products)...');

        $faker = Faker::create();
        $fileUploadService = app(FileUploadService::class);
        $directory = base_path('products_images');

        // 1. Group Local Images
        $files = FacadesFile::exists($directory) ? FacadesFile::files($directory) : [];
        $imageGroups = [];
        foreach ($files as $file) {
            $filename = basename($file->getFilename(), '.' . $file->getExtension());
            $baseNameStr = preg_replace('/(\s|-)*\d+(-\d+)?$/', '', $filename) ?: $filename;
            $imageGroups[$baseNameStr][] = $file;
        }

        $this->command->info("Phase 1/3: Uploading " . count($files) . " unique images to S3 (Caching)...");
        
        $cachedS3Paths = [];
        $bar = $this->command->getOutput()->createProgressBar(count($files));
        $bar->start();

        foreach ($imageGroups as $baseName => $groupFiles) {
            foreach ($groupFiles as $file) {
                try {
                    $uploadedFile = new UploadedFile(
                        $file->getPathname(), $file->getFilename(),
                        mime_content_type($file->getPathname()), null, true
                    );
                    $path = $fileUploadService->uploadFileToS3($uploadedFile, 'products/images');
                    $cachedS3Paths[$baseName][] = $path;
                } catch (\Exception $e) {
                    Log::error("Failed to upload image $baseName: " . $e->getMessage());
                }
                $bar->advance();
            }
        }
        $bar->finish();
        $this->command->newLine();

        // If no images found or uploaded, add a fallback dummy category so script doesn't fail
        if (empty($cachedS3Paths)) {
            $cachedS3Paths['Placeholder Item'][] = 'https://via.placeholder.com/600x600?text=Placeholder';
        }

        // 2. Setup Categories
        $categoriesList = [
            'Fashion' => ['Shoes', 'Clothing', 'Accessories', 'Sportswear'],
            'Electronics' => ['Computers', 'Audio', 'Gadgets', 'Smartwatches', 'Cameras'],
            'Home & Living' => ['Decor', 'Appliances', 'Furniture', 'Kitchenware'],
            'Health & Beauty' => ['Cosmetics', 'Wellness', 'Skincare', 'Fragrances'],
        ];

        $createdCategories = [];
        foreach ($categoriesList as $mainCat => $subCats) {
            $parent = Category::updateOrCreate(
                ['slug' => Str::slug($mainCat)],
                ['name' => $mainCat, 'status' => 'active', 'show_on_home' => 1]
            );
            foreach ($subCats as $subCat) {
                $createdCategories[$subCat] = Category::updateOrCreate(
                    ['slug' => Str::slug($subCat)], 
                    ['name' => $subCat, 'parent_id' => $parent->id, 'status' => 'active']
                );
            }
        }

        $this->command->info("Phase 2/3: Generating 1500 Products & Variations...");
        $bar = $this->command->getOutput()->createProgressBar(1500);
        $bar->start();

        $allProductIds = [];
        $baseNameKeys = array_keys($cachedS3Paths);

        $admin = \App\Models\Admin::first();
        $user = \App\Models\User::first() ?: \App\Models\User::factory()->create();

        // Batch arrays for fast insertion
        $productsToInsert = [];
        $imagesToInsert = [];
        $variantsToInsert = [];
        $reviewsToInsert = [];

        for ($i = 0; $i < 1500; $i++) {
            // Pick a random base group for its images
            $randomBase = $baseNameKeys[array_rand($baseNameKeys)];
            $s3Urls = $cachedS3Paths[$randomBase];
            
            $humanName = Str::title(str_replace('-', ' ', $randomBase));
            $productName = $faker->company . ' ' . $faker->word . ' ' . $humanName;

            // Guess category
            $subCategoryStr = 'Accessories';
            $hLower = strtolower($humanName);
            if (Str::contains($hLower, ['shirt', 'jacket', 'clothing', 'romper', 'sweater', 'dress'])) $subCategoryStr = collect(['Clothing', 'Sportswear'])->random();
            elseif (Str::contains($hLower, ['shoe', 'sneaker', 'boot'])) $subCategoryStr = 'Shoes';
            elseif (Str::contains($hLower, ['laptop', 'computer'])) $subCategoryStr = 'Computers';
            elseif (Str::contains($hLower, ['headphone'])) $subCategoryStr = 'Audio';
            elseif (Str::contains($hLower, ['camera'])) $subCategoryStr = 'Cameras';
            elseif (Str::contains($hLower, ['kitchen', 'cleaner', 'pillow', 'appliance'])) $subCategoryStr = collect(['Decor', 'Appliances', 'Kitchenware'])->random();
            elseif (Str::contains($hLower, ['powder', 'cream', 'makeup', 'perfume'])) $subCategoryStr = collect(['Cosmetics', 'Skincare', 'Fragrances'])->random();
            elseif (Str::contains($hLower, ['watch'])) $subCategoryStr = 'Smartwatches';
            
            $categoryId = $createdCategories[$subCategoryStr]->id ?? Category::inRandomOrder()->value('id');

            // Generate JSON Variant options (so filtering works wonderfully)
            $variantOptions = [];
            if (in_array($subCategoryStr, ['Clothing', 'Shoes', 'Sportswear'])) {
                $variantOptions = [
                    ['name' => 'Size', 'values' => (array) $faker->randomElements(['S', 'M', 'L', 'XL', 'XXL', '7', '8', '9', '10', '11'], rand(2, 5))],
                    ['name' => 'Color', 'values' => (array) $faker->randomElements(['Black', 'White', 'Blue', 'Red', 'Green', 'Yellow', 'Grey'], rand(2, 4))]
                ];
            } elseif (in_array($subCategoryStr, ['Computers', 'Smartphones'])) {
                $variantOptions = [
                    ['name' => 'Storage', 'values' => (array) $faker->randomElements(['128GB', '256GB', '512GB', '1TB'], rand(2, 4))],
                    ['name' => 'RAM', 'values' => (array) $faker->randomElements(['8GB', '16GB', '32GB'], rand(1, 3))]
                ];
            }

            // Random Status Profile (most published, few draft/out of stock)
            $statusChoice = $faker->randomElement(['published', 'published', 'published', 'published', 'draft', 'archived']);
            $stock = rand(0, 100);

            $product = Product::create([
                'name' => $productName,
                'slug' => Str::slug($productName) . '-' . uniqid(),
                'description' => $faker->paragraphs(3, true),
                'short_description' => $faker->sentence(10),
                'price' => $faker->randomFloat(2, 10, 1500),
                'original_price' => $faker->randomFloat(2, 15, 2000), // higher than price usually
                'stock_quantity' => $stock,
                'category_id' => $categoryId,
                'status' => $statusChoice,
                'sku' => strtoupper($faker->bothify('???-#####')),
                'barcode' => $faker->ean13(),
                'is_physical' => true,
                'charge_tax' => true,
                'tags' => $faker->words(rand(1, 4)),
                'variant_options' => !empty($variantOptions) ? $variantOptions : null,
                'rating' => $faker->randomFloat(1, 1, 5),
            ]);

            $allProductIds[] = $product->id;

            // Save Images mapping
            foreach ($s3Urls as $index => $s3Url) {
                // If S3 generated the URL with a space and Next.js needs encoding, that's fine, 
                // the frontend getImageUrl already handles it.
                ProductImage::create([
                    'product_id' => $product->id,
                    'image_path' => $s3Url,
                    'is_primary' => $index === 0,
                ]);
            }

            // Flat Variants Table (Legacy but required for some structures)
            if (!empty($variantOptions) && $variantOptions[0]['name'] === 'Size') {
                $sizes = $variantOptions[0]['values'];
                $colors = $variantOptions[1]['values'];
                foreach ($faker->randomElements($sizes, rand(1, count($sizes))) as $sz) {
                    foreach ($faker->randomElements($colors, rand(1, count($colors))) as $cl) {
                        ProductVariant::create([
                            'product_id' => $product->id,
                            'color' => $cl,
                            'size' => $sz,
                            'stock' => rand(0, 20),
                        ]);
                    }
                }
            }

            // Generate Random Reviews
            if (rand(1, 100) <= 70) { // 70% chance of having reviews
                for ($r = 0; $r < rand(1, 8); $r++) {
                    Review::create([
                        'product_id' => $product->id,
                        'user_id' => $user->id,
                        'rating' => rand(2, 5),
                        'comment' => $faker->paragraph,
                        'status' => 'active',
                    ]);
                }
            }

            $bar->advance();
        }

        $bar->finish();
        $this->command->newLine();

        $this->command->info("Phase 3/3: Generating 500 Analytics Orders...");
        $bar = $this->command->getOutput()->createProgressBar(500);
        $bar->start();

        // Fast random selection for massive orders
        $collectionIds = collect($allProductIds);

        for ($j = 0; $j < 500; $j++) {
            $order = Order::create([
                'order_number' => 'ORD-' . strtoupper(uniqid()),
                'user_id' => $user->id,
                'subtotal' => 0,
                'total' => 0,
                'status' => $faker->randomElement(['pending', 'processing', 'shipped', 'delivered', 'delivered', 'delivered', 'cancelled']),
                'payment_method' => $faker->randomElement(['cod', 'stripe', 'paypal']),
                'created_at' => $faker->dateTimeBetween('-6 months', 'now'), // Good for analytics charts
            ]);

            $subtotal = 0;
            $randomProds = $collectionIds->random(rand(1, 5));
            foreach ($randomProds as $pId) {
                $qty = rand(1, 3);
                $price = rand(10, 500); // Dummy static pricing to avoid extra DB joins
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $pId,
                    'quantity' => $qty,
                    'price' => $price,
                ]);
                $subtotal += ($qty * $price);
            }

            $order->update([
                'subtotal' => $subtotal,
                'total' => $subtotal + ($subtotal > 200 ? 0 : 50),
            ]);

            $bar->advance();
        }

        $bar->finish();
        $this->command->newLine();
        $this->command->info('✅ Successfully seeded 1500 Products!');
    }
}
