<?php

namespace App\Http\Controllers\Common;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function index()
    {
        $categories = Category::where('status', 'active')
            ->whereNull('parent_id')
            ->with('children')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $categories
        ]);
    }

    public function show($slug)
    {
        $category = Category::where('slug', $slug)
            ->with(['children', 'products' => function($query) {
                $query->where('status', 'published')->with('images');
            }])
            ->firstOrFail();

        return response()->json([
            'status' => 'success',
            'data' => $category
        ]);
    }
}
