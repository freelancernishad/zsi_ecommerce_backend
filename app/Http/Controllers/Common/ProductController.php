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

        // Filter by category slug (supports comma-separated slugs for children)
        if ($request->has('category')) {
            $slug = $request->category;
            $query->whereHas('category', function($q) use ($slug) {
                $q->where('slug', $slug)
                  ->orWhereHas('parent', function($q2) use ($slug) {
                      $q2->where('slug', $slug);
                  });
            });
        }

        if ($request->has('price_min')) {
            $query->where('price', '>=', $request->price_min);
        }
        if ($request->has('price_max')) {
            $query->where('price', '<=', $request->price_max);
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

    public function show($slug)
    {
        $product = Product::where('slug', $slug)
            ->with(['images', 'category', 'variants', 'reviews.user'])
            ->firstOrFail();

        return response()->json([
            'status' => 'success',
            'data' => $product
        ]);
    }
}
