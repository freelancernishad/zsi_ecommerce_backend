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
        $hero = SiteSetting::getValue('home_hero');
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
            'title' => 'Shop with Ease: The Ultimate Shopping Experience - Discounts, Support and Security!',
            'image' => 'https://via.placeholder.com/400x300/1c64d4/ffffff?text=App+Mockup',
            'features' => [
                ['text' => 'Enjoy free shipping on all orders over $1000!', 'icon_type' => 'shipping'],
                ['text' => 'Quality support with 24/7 online assistance!', 'icon_type' => 'support'],
                ['text' => 'Hassle-free returns & refunds within 30 days!', 'icon_type' => 'returns'],
                ['text' => 'Gift voucher – get 20% off when you shop online!', 'icon_type' => 'gift'],
            ]
        ]);

        $footerConfig = SiteSetting::getValue('site_footer', [
            'brand_name' => 'FlipShop.',
            'tagline' => 'SELECTIONS',
            'phone' => '+880 1700000000',
            'email' => 'contact@flipshopselections.com',
            'website' => 'www.flipshopselections.com',
            'copyright' => '© 2025 FlipShopSelections. Developed by ZSI.Ai',
            'socials' => [
                'facebook' => 'https://facebook.com',
                'pinterest' => 'https://pinterest.com',
                'linkedin' => 'https://linkedin.com',
                'twitter' => 'https://twitter.com'
            ],
            'quick_links' => [
                ['name' => 'Deal Of The Day', 'url' => '#'],
                ['name' => 'New Arrival', 'url' => '#'],
                ['name' => 'Featured Products', 'url' => '#'],
                ['name' => 'Trending Now', 'url' => '#'],
                ['name' => 'On Sale', 'url' => '#'],
            ],
            'customer_services' => [
                ['name' => 'My Orders', 'url' => '#'],
                ['name' => 'My Account', 'url' => '#'],
                ['name' => 'Track My Order', 'url' => '#'],
                ['name' => 'Return Policy', 'url' => '#'],
                ['name' => 'Privacy Policy', 'url' => '#'],
                ['name' => 'Help Center', 'url' => '#'],
            ]
        ]);

        $siteNavigation = SiteSetting::getValue('site_navigation', []);

        return response()->json([
            'status' => 'success',
            'data' => [
                'site_navigation' => $siteNavigation,
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
