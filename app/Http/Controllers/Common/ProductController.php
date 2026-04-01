<?php

namespace App\Http\Controllers\Common;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;

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

        // Filter by category slug (supports recursive children)
        if ($request->has('category') && !empty($request->category)) {
            $category = \App\Models\Category::with('children')->where('slug', $request->category)->first();
            if ($category) {
                $categoryIds = $this->getCategoryIdsRecursive($category);
                $query->whereIn('category_id', $categoryIds);
            } else {
                $query->whereRaw('1 = 0'); // No category found, return nothing
            }
        }

        if ($request->has('price_min') && is_numeric($request->price_min)) {
            $query->where('price', '>=', $request->price_min);
        }
        if ($request->has('price_max') && is_numeric($request->price_max)) {
            $query->where('price', '<=', $request->price_max);
        }

        if ($request->has('brand_id')) {
            $query->where('brand_id', $request->brand_id);
        } elseif ($request->has('brand')) {
            // Support both brand_id and brand as param for flexibility
            $query->where('brand_id', $request->brand);
        }

        if ($request->has('rating') && is_numeric($request->rating)) {
            $query->where('rating', '>=', $request->rating);
        }

        if ($request->boolean('in_stock')) {
            $query->where('stock_quantity', '>', 0);
        }

        if ($request->has('color') && !empty($request->color)) {
            $color = $request->color;
            $query->whereHas('variants', function ($q) use ($color) {
                $q->where('color', $color);
            });
        }

        if ($request->has('size') && !empty($request->size)) {
            $size = $request->size;
            $query->whereHas('variants', function ($q) use ($size) {
                $q->where('size', $size);
            });
        }

        if ($request->has('ids') && !empty($request->ids)) {
            $ids = array_map('trim', explode(',', $request->ids));
            $ids = array_filter($ids, 'is_numeric');
            if (!empty($ids)) {
                $query->whereIn('id', $ids);
            }
        }

        // Sorting
        switch ($request->get('sort', 'newest')) {
            case 'oldest':       $query->orderBy('created_at', 'asc'); break;
            case 'price_asc':    $query->orderBy('price', 'asc'); break;
            case 'price_desc':   $query->orderBy('price', 'desc'); break;
            default:             $query->orderBy('created_at', 'desc'); break; // newest
        }

        $limit   = max(1, min(50, (int) $request->get('limit', 12)));
        $products = $request->has('limit')
            ? $query->limit($limit)->get()
            : $query->paginate(12);

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
        
        // Get unique colors and sizes from variants
        $colors = \App\Models\ProductVariant::whereNotNull('color')->distinct()->pluck('color')->filter();
        $sizes = \App\Models\ProductVariant::whereNotNull('size')->distinct()->pluck('size')->filter();

        return response()->json([
            'status' => 'success',
            'data' => [
                'categories' => $categories,
                'brands' => $brands,
                'colors' => $colors,
                'sizes' => $sizes
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
