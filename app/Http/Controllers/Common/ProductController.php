<?php

namespace App\Http\Controllers\Common;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $query = Product::with(['images', 'category'])->where('status', 'published');

        // Search by name or description
        if ($request->has('q') && !empty($request->q)) {
            $searchTerm = '%' . $request->q . '%';
            $query->where(function ($q) use ($searchTerm) {
                $q->where('name', 'like', $searchTerm)
                  ->orWhere('description', 'like', $searchTerm)
                  ->orWhere('short_description', 'like', $searchTerm);
            });
        }

        // Filter by category slug (supports recursive children and multiple categories)
        if ($request->has('category') && !empty($request->category)) {
            $slugs = array_filter(explode(',', $request->category));
            $categories = \App\Models\Category::with('children')->whereIn('slug', $slugs)->get();
            
            if ($categories->isNotEmpty()) {
                $allCategoryIds = [];
                foreach ($categories as $cat) {
                    $allCategoryIds = array_merge($allCategoryIds, $this->getCategoryIdsRecursive($cat));
                }
                $catIds = array_unique($allCategoryIds);
                $query->where(function($q) use ($catIds) {
                    $q->whereIn('category_id', $catIds)
                      ->orWhereHas('categories', function($sq) use ($catIds) {
                          $sq->whereIn('categories.id', $catIds);
                      });
                });
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        if ($request->has('price_min') && is_numeric($request->price_min)) {
            $query->where('price', '>=', $request->price_min);
        }
        if ($request->has('price_max') && is_numeric($request->price_max)) {
            $query->where('price', '<=', $request->price_max);
        }

        if ($request->has('brand') && !empty($request->brand)) {
             $brands = array_filter(explode(',', $request->brand));
             if (is_numeric($brands[0])) {
                 $query->whereIn('brand_id', $brands);
             } else {
                 $brandIds = \App\Models\Brand::whereIn('slug', $brands)->pluck('id');
                 $query->whereIn('brand_id', $brandIds);
             }
        } elseif ($request->has('brand_id')) {
            $brandIds = array_filter(explode(',', $request->brand_id));
            $query->whereIn('brand_id', $brandIds);
        }

        if ($request->has('rating') && is_numeric($request->rating)) {
            $query->where('rating', '>=', $request->rating);
        }

        if ($request->boolean('in_stock')) {
            $query->where('stock_quantity', '>', 0);
        }

        if ($request->has('color') && !empty($request->color)) {
            $colors = array_filter(explode(',', $request->color));
            $query->where(function($q) use ($colors) {
                // Legacy column check
                $q->whereHas('variants', function($qv) use ($colors) {
                    $qv->whereIn('color', $colors);
                })
                // JSON variant_options check
                ->orWhere(function($qj) use ($colors) {
                    foreach ($colors as $c) {
                        $qj->orWhereRaw("JSON_SEARCH(LOWER(variant_options), 'one', ?, NULL, '$[*].values') IS NOT NULL", [strtolower(trim($c))]);
                    }
                });
            });
        }

        if ($request->has('size') && !empty($request->size)) {
            $sizes = array_filter(explode(',', $request->size));
            $query->where(function($q) use ($sizes) {
                // Legacy column check
                $q->whereHas('variants', function($qs) use ($sizes) {
                    $qs->whereIn('size', $sizes);
                })
                // JSON variant_options check
                ->orWhere(function($qj) use ($sizes) {
                    foreach ($sizes as $s) {
                        $qj->orWhereRaw("JSON_SEARCH(LOWER(variant_options), 'one', ?, NULL, '$[*].values') IS NOT NULL", [strtolower(trim($s))]);
                    }
                });
            });
        }

        // Support for other attributes like 'material'
        $allParams = $request->all();
        $reserved = ['category', 'brand', 'brand_id', 'color', 'size', 'sort', 'page', 'limit', 'price_min', 'price_max', 'q', 'rating', 'in_stock', 'ids'];
        foreach ($allParams as $key => $val) {
            if (in_array($key, $reserved) || empty($val)) continue;
            
            $values = array_filter(explode(',', $val));
            $query->where(function($q) use ($values) {
                foreach ($values as $v) {
                    $q->orWhereRaw("JSON_SEARCH(LOWER(variant_options), 'one', ?, NULL, '$[*].values') IS NOT NULL", [strtolower(trim($v))]);
                }
            });
        }

        if ($request->has('ids') && !empty($request->ids)) {
            $ids = array_map('trim', explode(',', $request->ids));
            $ids = array_filter($ids, 'is_numeric');
            if (!empty($ids)) {
                $query->whereIn('id', $ids);
            }
        }

        // Personalized Sorting (If user is authenticated)
        $personalized = false;
        if (auth('user')->check() && !$request->has('sort')) {
            $userId = auth('user')->id();
            
            // 1. Category Interest (Weight 30)
            $topCategories = \App\Models\UserActivity::where('user_id', $userId)
                ->where('activity_type', 'view_category')
                ->whereNotNull('entity_id')
                ->select('entity_id', DB::raw('count(*) as count'))
                ->groupBy('entity_id')
                ->orderBy('count', 'desc')
                ->limit(3)
                ->pluck('entity_id')
                ->toArray();

            // 2. Keyword Interest (Weight 50) - Looking at last 10 searches
            $recentKeywords = \App\Models\UserActivity::where('user_id', $userId)
                ->where('activity_type', 'search')
                ->latest()
                ->limit(10)
                ->pluck('metadata')
                ->map(fn($meta) => $meta['keywords'] ?? null)
                ->filter()
                ->unique()
                ->toArray();

            // 3. Tag interest (Weight 10 per tag match) - From last 20 product views
            $recentTags = \App\Models\UserActivity::where('user_id', $userId)
                ->where('activity_type', 'view_product')
                ->latest()
                ->limit(20)
                ->pluck('metadata')
                ->flatMap(fn($meta) => $meta['tags'] ?? [])
                ->countBy()
                ->sortDesc()
                ->take(5)
                ->keys()
                ->toArray();

            $rawOrder = [];
            
            if (!empty($topCategories)) {
                $categoryOrder = implode(',', $topCategories);
                $rawOrder[] = "(CASE WHEN category_id IN ({$categoryOrder}) THEN 30 ELSE 0 END)";
            }

            if (!empty($recentKeywords)) {
                $keywordCase = "";
                foreach ($recentKeywords as $keyword) {
                    $escaped = str_replace("'", "''", $keyword);
                    $keywordCase .= "WHEN name LIKE '%{$escaped}%' THEN 50 ";
                }
                $rawOrder[] = "(CASE {$keywordCase} ELSE 0 END)";
            }

            if (!empty($recentTags)) {
                $tagCases = [];
                foreach ($recentTags as $tag) {
                    $escapedTag = str_replace("'", "''", $tag);
                    // Using JSON_CONTAINS for clean tag matching
                    $tagCases[] = "(CASE WHEN JSON_CONTAINS(LOWER(tags), '\"" . strtolower($escapedTag) . "\"') THEN 10 ELSE 0 END)";
                }
                $rawOrder[] = "(" . implode(' + ', $tagCases) . ")";
            }

            if (!empty($rawOrder)) {
                $query->orderByRaw("(" . implode(' + ', $rawOrder) . ") DESC");
                $personalized = true;
            }
        }
    

        // Standard Sorting (Secondary or Primary if no personalization)
        switch ($request->get('sort', 'newest')) {
            case 'oldest':         $query->orderBy('created_at', 'asc'); break;
            case 'price_asc':      $query->orderBy('price', 'asc'); break;
            case 'price_desc':     $query->orderBy('price', 'desc'); break;
            case 'rating_desc':    $query->orderBy('rating', 'desc'); break;
            case 'best_selling':   $query->withCount('orderItems')->orderBy('order_items_count', 'desc'); break;
            default:               
                if (!$personalized) {
                    $query->orderBy('created_at', 'desc'); 
                }
                break; 
        }

        $limit   = max(1, min(100, (int) $request->get('limit', 24)));
        $products = $query->paginate($limit);

        return response()->json([
            'status' => 'success',
            'data'   => $products
        ]);
    }

    public function suggestions(Request $request)
    {
        $query = Product::with(['images' => function($q) {
            $q->select('id', 'product_id', 'image_path')->limit(1);
        }])
        ->where('status', 'published')
        ->select('id', 'name', 'slug', 'price', 'original_price', 'category_id', 'rating');

        if ($request->has('q') && !empty($request->q)) {
            $searchTerm = '%' . $request->q . '%';
            $query->where(function ($q) use ($searchTerm) {
                $q->where('name', 'like', $searchTerm)
                  ->orWhere('tags', 'like', $searchTerm);
            });
        }

        $products = $query->limit(8)->get();

        return response()->json([
            'status' => 'success',
            'data'   => $products
        ]);
    }

    public function show($idOrSlug)
    {
        $query = Product::with(['images', 'category', 'brand', 'variants', 'reviews.user']);

        if (is_numeric($idOrSlug)) {
            $product = $query->findOrFail($idOrSlug);
        } else {
            $product = $query->where('slug', $idOrSlug)->firstOrFail();
        }

        return response()->json([
            'status' => 'success',
            'data' => $product
        ]);
    }

    public function getFilters()
    {
        $categories = \App\Models\Category::where('status', 'active')->whereNull('parent_id')->with('children')->get();
        $brands = \App\Models\Brand::where('status', 'active')->get();
        
        // 1. Get legacy variants from product_variants columns (fallback)
        $legacyColors = \App\Models\ProductVariant::whereNotNull('color')->distinct()->pluck('color')->filter()->toArray();
        $legacySizes = \App\Models\ProductVariant::whereNotNull('size')->distinct()->pluck('size')->filter()->toArray();
        
        // 2. Get dynamic variants from product-level JSON 'variant_options'
        $allVariantOptions = \App\Models\Product::whereNotNull('variant_options')->pluck('variant_options');
        
        $variantFilters = [];
        
        // Seed with legacy values
        if (!empty($legacyColors)) $variantFilters['Color'] = array_values(array_unique($legacyColors));
        if (!empty($legacySizes)) $variantFilters['Size'] = array_values(array_unique($legacySizes));

        foreach ($allVariantOptions as $options) {
            if (!is_array($options)) continue;
            
            foreach ($options as $opt) {
                $name = $opt['name'] ?? null;
                $values = $opt['values'] ?? [];
                if (!$name || empty($values)) continue;
                
                // Use the exact name from database for the filter group
                if (!isset($variantFilters[$name])) {
                    $variantFilters[$name] = [];
                }
                
                // Normalize values to Title Case to prevent duplicates (e.g. 'black' vs 'Black')
                $normalizedValues = array_map(function($v) {
                    return mb_convert_case(trim($v), MB_CASE_TITLE, "UTF-8");
                }, $values);

                $variantFilters[$name] = array_values(array_unique(array_merge($variantFilters[$name], $normalizedValues)));
            }
        }

        // Final cleanup for each group: normalize existing legacy values too
        foreach ($variantFilters as $key => $vals) {
            $variantFilters[$key] = array_values(array_unique(array_map(function($v) {
                return mb_convert_case(trim($v), MB_CASE_TITLE, "UTF-8");
            }, $vals)));
        }

        // 3. Get Price Bounds (Min/Max) for Slider
        $minPrice = (float) \App\Models\Product::where('status', 'active')->min('price') ?: 0;
        $maxPrice = (float) \App\Models\Product::where('status', 'active')->max('price') ?: 1000;

        return response()->json([
            'status' => 'success',
            'data' => [
                'categories' => $categories,
                'brands'      => $brands,
                'variant_filters' => $variantFilters,
                'min_price' => floor($minPrice),
                'max_price' => ceil($maxPrice)
            ]
        ]);
    }

    private function getCategoryIdsRecursive($category)
    {
        $ids = [$category->id];
        foreach ($category->children as $child) {
            $ids = array_merge($ids, $this->getCategoryIdsRecursive($child));
        }
        return $ids;
    }
}
