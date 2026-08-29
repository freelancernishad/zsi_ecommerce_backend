<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use App\Models\Category;
use App\Models\Product;
use App\Models\Brand;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    /**
     * Get all homepage content in a single request for better performance.
     */
    public function index()
    {
        // 1. Fetch Dynamic Sections from SiteSettings
        $defaultHero = [
            'slides' => [
                [
                    'title' => 'Kitchen & Living Essentials',
                    'image' => '/banners/1.webp',
                    'link' => '/products'
                ],
                [
                    'title' => 'New Collection',
                    'image' => '/banners/2.webp',
                    'link' => '/products'
                ],
                [
                    'title' => 'Special Deals',
                    'image' => '/banners/3.webp',
                    'link' => '/products'
                ],
                [
                    'title' => 'Trending Items',
                    'image' => '/banners/4.webp',
                    'link' => '/products'
                ]
            ],
            'side_banners' => [
                [
                    'mini_title' => 'SPECIAL OFFER',
                    'title' => 'Featured Deals',
                    'image' => '/banners/mini-1.webp',
                    'link' => '/products'
                ],
                [
                    'mini_title' => 'SPECIAL OFFER',
                    'title' => 'Smart Accessories',
                    'image' => '/banners/mini-3.webp',
                    'link' => '/products'
                ]
            ]
        ];

        $hero = SiteSetting::getValue('home_hero', $defaultHero);

        if (!is_array($hero)) {
            $hero = $defaultHero;
        }

        if (empty($hero['slides']) || !is_array($hero['slides'])) {
            $hero['slides'] = $defaultHero['slides'];
        }

        if (empty($hero['side_banners']) || !is_array($hero['side_banners'])) {
            $hero['side_banners'] = $defaultHero['side_banners'];
        }

        // Format image paths for slides and side_banners
        foreach ($hero['slides'] as &$slide) {
            if (isset($slide['image']) && !empty($slide['image']) && !str_starts_with($slide['image'], 'http') && !str_starts_with($slide['image'], '/')) {
                $slide['image'] = asset('storage/' . $slide['image']);
            }
        }
        unset($slide);

        foreach ($hero['side_banners'] as &$card) {
            if (isset($card['image']) && !empty($card['image']) && !str_starts_with($card['image'], 'http') && !str_starts_with($card['image'], '/')) {
                $card['image'] = asset('storage/' . $card['image']);
            }
        }
        unset($card);
        $springFlingConfig = SiteSetting::getValue('home_spring_fling', [
            'title' => 'Spring Fling Sale',
            'subtitle' => 'Refresh Your Wardrobe with Fresh Styles!',
            'button_text' => 'Shop Now',
            'button_link' => '/products',
            'image' => null,
            'product_ids' => []
        ]);
        
        // Fetch actual products for Spring Fling if IDs are provided
        $springFlingProducts = [];
        if (!empty($springFlingConfig['product_ids'])) {
            $springFlingProducts = Product::with(['images'])
                ->whereIn('id', $springFlingConfig['product_ids'])
                ->get();
        }

        $trendsConfig = SiteSetting::getValue('home_trends', [
            'title' => 'Top Trends: Must-Have Categories & Global Favorites',
            'viral_title' => 'Viral Pick – Grab Now!',
            'viral_pick_id' => null
        ]);

        $viralPick = null;
        if (!empty($trendsConfig['viral_pick_id'])) {
            $viralPick = Product::with(['images', 'reviews'])
                ->find($trendsConfig['viral_pick_id']);
        }

        $brands = Brand::where('status', 'active')
            ->where('show_on_home', true)
            ->orderBy('home_order', 'asc')
            ->get()
            ->map(function($brand) {
                if ($brand->logo && !str_starts_with($brand->logo, 'http')) {
                    $brand->logo = asset('storage/' . $brand->logo);
                }
                return $brand;
            });
        
        $brandSectionConfig = SiteSetting::getValue('home_brand_section', [
            'title' => 'Top Picks from The Most Favorite Brands',
            'product_ids' => []
        ]);

        $brandSectionConfig['brands'] = $brands;

        // If brands exist, fetch products for the first brand as default
        $brandSectionProducts = [];
        if ($brands->isNotEmpty()) {
            $firstBrand = $brands->first();
            $brandSectionProducts = Product::with(['images', 'reviews', 'category', 'variants'])
                ->where('brand_id', $firstBrand->id)
                ->where('status', 'published')
                ->limit(12)
                ->get();
        } elseif (!empty($brandSectionConfig['product_ids'])) {
            // Fallback to manual selection if no featured brands exist (or as secondary fallback)
            $brandSectionProducts = Product::with(['images', 'reviews', 'category', 'variants'])
                ->whereIn('id', $brandSectionConfig['product_ids'])
                ->where('status', 'published')
                ->get();
        }
        
        // 2. Fetch Featured Categories (marked with show_on_home) - For Highlights (Always show)
        $featuredCategories = Category::where('show_on_home', true)
            ->with(['children' => function($q) {
                $q->limit(4);
            }])
            ->orderBy('home_order', 'asc')
            ->get()
            ->map(function($cat) {
                if ($cat->image && !str_starts_with($cat->image, 'http')) {
                    $cat->image = asset('storage/' . $cat->image);
                }
                
                // Format children images
                if ($cat->children) {
                    foreach ($cat->children as $child) {
                        if ($child->image && !str_starts_with($child->image, 'http')) {
                            $child->image = asset('storage/' . $child->image);
                        }
                    }
                }
                return $cat;
            });

        // 2b. Fetch Filtered Categories - For Carousel Tabs (Show only if products exist in branch)
        $carouselCategories = Category::where('show_on_home', true)
            ->where(function($query) {
                $query->whereHas('products') 
                      ->orWhereHas('children.products') 
                      ->orWhereHas('children.children.products');
            })
            ->orderBy('home_order', 'asc')
            ->get()
            ->map(function($cat) {
                if ($cat->image && !str_starts_with($cat->image, 'http')) {
                    $cat->image = asset('storage/' . $cat->image);
                }
                return $cat;
            });

        // Helper to ensure product images have full URLs
        $formatProduct = function($product) {
            if (!$product) return null;
            foreach ($product->images as $img) {
                if ($img->image_path && !str_starts_with($img->image_path, 'http')) {
                    $img->image_path = asset('storage/' . $img->image_path);
                }
            }
            return $product;
        };

        // 3. Fetch Deal Of The Day (Products with highest discount percentage)
        $dealOfTheDay = Product::with(['images', 'variants'])
            ->whereColumn('original_price', '>', 'price')
            ->orderByRaw('(original_price - price) / original_price DESC')
            ->limit(10)
            ->get()
            ->map($formatProduct);

        // 5. Fetch New Arrivals (Last 8 products)
        $newArrivals = Product::with(['images', 'variants'])
            ->orderBy('created_at', 'desc')
            ->limit(8)
            ->get()
            ->map($formatProduct);

        foreach ($springFlingProducts as $p) $formatProduct($p);
        foreach ($brandSectionProducts as $p) $formatProduct($p);
        $formatProduct($viralPick);

        // Fetch Popular Products
        $popularProducts = Product::with(['images', 'variants'])
            ->where('status', 'published')
            ->orderBy('review_count', 'desc')
            ->limit(6)
            ->get()
            ->map($formatProduct);
            
        // Fetch Exclusive Deals (Products with highest absolute discount amount)
        $exclusiveDeals = Product::with(['images', 'variants'])
            ->where('status', 'published')
            ->whereColumn('original_price', '>', 'price')
            ->orderByRaw('(original_price - price) DESC')
            ->limit(6)
            ->get()
            ->map($formatProduct);

        // Fetch Global Configs for Footer/PreFooter 
        $preFooterConfig = SiteSetting::getValue('home_pre_footer', [
            'title' => 'Shop with Ease: The Ultimate Shopping Experience',
            'image' => null,
            'features' => [
                ['text' => 'Enjoy free shipping on all eligible orders!', 'icon_type' => 'shipping'],
                ['text' => 'Quality support with 24/7 online assistance!', 'icon_type' => 'support'],
                ['text' => 'Hassle-free returns & refunds within 30 days!', 'icon_type' => 'returns'],
                ['text' => 'Special discounts when you shop online!', 'icon_type' => 'gift'],
            ]
        ]);

        $footerConfig = SiteSetting::getValue('site_footer', [
            'brand_name' => '',
            'tagline' => '',
            'phone' => '',
            'email' => '',
            'website' => '',
            'copyright' => '',
            'socials' => [
                'facebook' => '',
                'pinterest' => '',
                'linkedin' => '',
                'twitter' => ''
            ],
            'quick_links' => [],
            'customer_services' => []
        ]);

        $siteNavigation = SiteSetting::getValue('site_navigation', []);
        $siteLogo = SiteSetting::getValue('site_logo', null);

        return response()->json([
            'status' => 'success',
            'data' => [
                'site_navigation' => $siteNavigation,
                'site_logo' => $siteLogo,
                'settings' => [
                    'pre_footer' => $preFooterConfig,
                    'footer' => $footerConfig
                ],
                'sections' => [
                    'hero' => $hero,
                    'spring_fling' => $springFlingConfig,
                    'spring_fling_products' => $springFlingProducts,
                    'trends' => $trendsConfig,
                    'brands' => $brands,
                    'brand_section' => [
                        'title' => $brandSectionConfig['title'],
                        'brands' => $brands,
                        'products' => $brandSectionProducts
                    ],
                    'viral_pick' => $viralPick,
                    'browsing_history' => Product::with(['images', 'reviews', 'category', 'variants'])
                        ->where('status', 'published')
                        ->inRandomOrder()
                        ->limit(12)
                        ->get()
                ],
                'categories' => $featuredCategories,
                'carousel_categories' => $carouselCategories,
                'products' => [
                    'new_arrivals' => $newArrivals,
                    'deal_of_the_day' => $dealOfTheDay,
                    'popular' => $popularProducts,
                    'on_sale' => $dealOfTheDay->take(6),
                    'exclusive_deals' => $exclusiveDeals
                ]
            ]
        ]);
    }
}
