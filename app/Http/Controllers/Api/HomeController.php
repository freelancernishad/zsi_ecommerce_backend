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
        $springFling = SiteSetting::getValue('home_spring_fling');
        $trendsTitle = SiteSetting::getValue('home_trends_title', 'Top Trends');
        $brands = SiteSetting::getValue('home_brands', []);
        
        // 2. Fetch Featured Categories (marked with show_on_home)
        $featuredCategories = Category::where('show_on_home', true)
            ->withCount('products')
            ->with(['children' => function($q) {
                $q->limit(4);
            }])
            ->orderBy('home_order', 'asc')
            ->get();

        // 3. Fetch Featured Products (using viral pick from settings)
        $viralProductId = SiteSetting::getValue('home_viral_pick_id');
        $viralPick = Product::with(['images'])
            ->find($viralProductId);

        // 4. Fetch New Arrivals (Last 8 products)
        $newArrivals = Product::with(['images'])
            ->orderBy('created_at', 'desc')
            ->limit(8)
            ->get();

        return response()->json([
            'data' => [
                'sections' => [
                    'hero' => $hero,
                    'spring_fling' => $springFling,
                    'trends_title' => $trendsTitle,
                    'brands' => $brands,
                    'viral_pick' => $viralPick
                ],
                'categories' => $featuredCategories,
                'products' => [
                    'new_arrivals' => $newArrivals
                ]
            ]
        ]);
    }
}
