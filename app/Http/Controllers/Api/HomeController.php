<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use App\Models\Category;
use App\Models\Product;
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

        $brands = SiteSetting::getValue('home_brands', []);
        
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
        $dealOfTheDay = Product::with(['images'])
            ->whereColumn('original_price', '>', 'price')
            ->orderByRaw('(original_price - price) / original_price DESC')
            ->limit(10)
            ->get()
            ->map($formatProduct);

        // 5. Fetch New Arrivals (Last 8 products)
        $newArrivals = Product::with(['images'])
            ->orderBy('created_at', 'desc')
            ->limit(8)
            ->get()
            ->map($formatProduct);

        foreach ($springFlingProducts as $p) $formatProduct($p);
        $formatProduct($viralPick);

        return response()->json([
            'status' => 'success',
            'data' => [
                'sections' => [
                    'hero' => $hero,
                    'spring_fling' => $springFlingConfig,
                    'spring_fling_products' => $springFlingProducts,
                    'trends' => $trendsConfig,
                    'brands' => $brands,
                    'viral_pick' => $viralPick
                ],
                'categories' => $featuredCategories,
                'carousel_categories' => $carouselCategories,
                'products' => [
                    'new_arrivals' => $newArrivals,
                    'deal_of_the_day' => $dealOfTheDay
                ]
            ]
        ]);
    }
}
