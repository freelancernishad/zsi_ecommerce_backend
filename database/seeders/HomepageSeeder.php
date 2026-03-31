<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\SiteSetting;
use App\Models\Category;
use App\Models\Product;

class HomepageSeeder extends Seeder
{
    public function run(): void
    {
        // Clear existing settings to avoid corrupted data
        SiteSetting::truncate();

        // 1. Hero Section Settings
        SiteSetting::setValue('home_hero', [
            'badge' => 'New Collection',
            'title' => 'The Best Kids Style Choice For You',
            'subtitle' => 'Unbeatable style for your little ones. Discover the latest trends in kids fashion.',
            'button_text' => 'Shop Now',
            'button_link' => '/category/kids-fashion',
            'images' => [
                'background' => 'https://via.placeholder.com/1920x1080?text=Hero+Background',
                'line' => 'https://via.placeholder.com/500x50?text=Line',
                'models' => 'https://via.placeholder.com/600x800?text=Kids+Model'
            ]
        ], 'homepage', 'json');

        // 2. Spring Fling Banner Settings
        SiteSetting::setValue('home_spring_fling', [
            'badge' => 'EXCLUSIVE COLLECTION',
            'title' => 'Blooming Styles For Spring',
            'subtitle' => 'Refresh your wardrobe with our latest seasonal picks.',
            'button_text' => 'Discover More',
            'button_link' => '/shop',
            'image' => 'https://via.placeholder.com/1200x600?text=Spring+Fling+Banner'
        ], 'homepage', 'json');

        // 3. Top Trends Settings
        SiteSetting::setValue('home_trends_title', 'Top Trends: Must-Have Categories & Global Favorites', 'homepage', 'text');
        
        // 4. Viral Pick Product ID
        $viralProduct = Product::where('name', 'like', '%camera%')->first();
        SiteSetting::setValue('home_viral_pick_id', $viralProduct ? $viralProduct->id : 1, 'homepage', 'integer');

        // 5. Brand Logos
        SiteSetting::setValue('home_brands', [
            ['name' => 'Nike', 'image' => 'https://via.placeholder.com/200x100?text=Nike'],
            ['name' => 'Adidas', 'image' => 'https://via.placeholder.com/200x100?text=Adidas'],
            ['name' => 'Puma', 'image' => 'https://via.placeholder.com/200x100?text=Puma'],
            ['name' => 'Apple', 'image' => 'https://via.placeholder.com/200x100?text=Apple'],
            ['name' => 'Samsung', 'image' => 'https://via.placeholder.com/200x100?text=Samsung'],
            ['name' => 'Sony', 'image' => 'https://via.placeholder.com/200x100?text=Sony'],
        ], 'homepage', 'json');

        // 6. Update Categories for "Category Highlights" (The 4-block grid)
        $gridCategories = [
            [
                'name' => 'Style & Trends: Fashion Hub',
                'slug' => 'fashion-hub',
                'children' => [
                    ['name' => "Men's Clothing", 'slug' => 'mens-clothing'],
                    ['name' => "Women's Clothing", 'slug' => 'womens-clothing'],
                    ['name' => "Kids' & Baby Wear", 'slug' => 'kids-wear'],
                    ['name' => 'Sustainable Fashion', 'slug' => 'sustainable-fashion'],
                ]
            ],
            [
                'name' => 'Innovative Tech & Gadgets',
                'slug' => 'tech-gadgets',
                'children' => [
                    ['name' => 'Smartphones', 'slug' => 'smartphones'],
                    ['name' => 'Laptops & Tablets', 'slug' => 'laptops'],
                    ['name' => 'Headphones & Smartwatch', 'slug' => 'audio-gear'],
                    ['name' => 'Gaming & Consoles', 'slug' => 'gaming'],
                ]
            ],
            [
                'name' => 'Beauty, Health & Wellness',
                'slug' => 'beauty-wellness',
                'children' => [
                    ['name' => 'Skincare & Makeup', 'slug' => 'skincare'],
                    ['name' => 'Haircare & Styling', 'slug' => 'haircare'],
                    ['name' => 'Perfumes', 'slug' => 'perfumes'],
                    ['name' => 'Health & Wellness', 'slug' => 'wellness'],
                ]
            ],
            [
                'name' => 'Cozy & Chic: Home & Living',
                'slug' => 'home-living',
                'children' => [
                    ['name' => 'Decor & Furniture', 'slug' => 'decor'],
                    ['name' => 'Kitchen & Dining', 'slug' => 'kitchen'],
                    ['name' => 'Bedsheets & Curtains', 'slug' => 'bedding'],
                    ['name' => 'Storage & Organization', 'slug' => 'storage'],
                ]
            ],
        ];

        // Reset show_on_home for all categories first
        Category::query()->update(['show_on_home' => false]);

        foreach ($gridCategories as $index => $catData) {
            $parent = Category::updateOrCreate(
                ['slug' => $catData['slug']],
                [
                    'name' => $catData['name'],
                    'show_on_home' => true,
                    'home_order' => $index + 1,
                    'image' => 'https://via.placeholder.com/400x400?text=' . urlencode($catData['name'])
                ]
            );

            foreach ($catData['children'] as $child) {
                Category::updateOrCreate(
                    ['slug' => $child['slug']],
                    [
                        'name' => $child['name'],
                        'parent_id' => $parent->id,
                        'image' => 'https://via.placeholder.com/200x200?text=' . urlencode($child['name'])
                    ]
                );
            }
        }
    }
}
