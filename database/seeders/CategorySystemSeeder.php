<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CategorySystemSeeder extends Seeder
{
    public function run(): void
    {
        // Clear existing categories
        Category::truncate();

        $data = [
            [
                'name' => 'Fashion & Style',
                'description' => 'Latest trends in clothing and accessories.',
                'status' => 'active',
                'image' => '👗',
                'children' => [
                    [
                        'name' => "Men's Clothing",
                        'description' => 'Apparel for men.',
                        'status' => 'active',
                        'image' => '👔',
                        'children' => []
                    ],
                    [
                        'name' => "Women's Clothing",
                        'description' => 'Apparel for women.',
                        'status' => 'active',
                        'image' => '👗',
                        'children' => []
                    ],
                    [
                        'name' => "Kids' & Baby Wear",
                        'description' => 'Cute outfits for children.',
                        'status' => 'active',
                        'image' => '👶',
                        'children' => [
                            ['name' => 'Baby Rompers', 'description' => 'Soft rompers.', 'status' => 'active', 'image' => '🍼'],
                            ['name' => "Boy's Clothing", 'description' => 'Outfits for boys.', 'status' => 'active', 'image' => '👕'],
                            ['name' => "Girl's Clothing", 'description' => 'Outfits for girls.', 'status' => 'active', 'image' => '👗'],
                        ]
                    ],
                    [
                        'name' => 'Sustainable Fashion',
                        'description' => 'Eco-friendly and sustainable clothing.',
                        'status' => 'active',
                        'image' => '🌿',
                        'children' => []
                    ],
                ]
            ],
            [
                'name' => 'Tech & Gadgets',
                'description' => 'High-tech devices and latest gadgets.',
                'status' => 'active',
                'image' => '💻',
                'children' => [
                    ['name' => 'Laptops & Computers', 'status' => 'active', 'image' => '💻'],
                    ['name' => 'Mobile Phones', 'status' => 'active', 'image' => '📱'],
                ]
            ],
            [
                'name' => 'Beauty, Health & Wellness',
                'description' => 'Skincare, health products and wellness items.',
                'status' => 'active',
                'image' => '🧴',
                'children' => []
            ],
            [
                'name' => 'Home & Living',
                'description' => 'Everything you need for your home.',
                'status' => 'active',
                'image' => '🛋️',
                'children' => []
            ],
            [
                'name' => 'Footwear & Style',
                'description' => 'Trendy shoes and footwear.',
                'status' => 'active',
                'image' => '👟',
                'children' => []
            ],
            [
                'name' => 'Accessories',
                'description' => 'Bags, watches and more.',
                'status' => 'active',
                'image' => '👜',
                'children' => []
            ],
        ];

        foreach ($data as $item) {
            $this->createCategory($item);
        }
    }

    private function createCategory($data, $parentId = null)
    {
        $children = $data['children'] ?? [];
        unset($data['children']);
        
        $data['slug'] = Str::slug($data['name']);
        $data['parent_id'] = $parentId;
        
        $category = Category::create($data);

        foreach ($children as $child) {
            $this->createCategory($child, $category->id);
        }
    }
}
