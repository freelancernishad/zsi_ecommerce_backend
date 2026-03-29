<?php

namespace App\Http\Controllers\Admin\Categories;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 8);
        
        $categories = Category::select('id', 'name', 'slug', 'image', 'status', 'created_at')
            ->whereNull('parent_id')
            ->with(['children' => function($query) {
                $query->select('id', 'parent_id')->with(['children' => function($q) {
                    $q->select('id', 'parent_id');
                }]);
            }])
            ->withCount('children')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        // Map categories to include recursive products_count and total_sales
        $categories->getCollection()->transform(function($category) {
            // Collect all descendant IDs
            $allCategoryIds = [$category->id];
            foreach ($category->children as $child) {
                $allCategoryIds[] = $child->id;
                foreach ($child->children as $grandchild) {
                    $allCategoryIds[] = $grandchild->id;
                }
            }

            // Recursive Product Count
            $category->products_count = \App\Models\Product::whereIn('category_id', $allCategoryIds)->count();

            // Recursive Total Sales
            $totalSale = \Illuminate\Support\Facades\DB::table('order_items')
                ->join('products', 'order_items.product_id', '=', 'products.id')
                ->whereIn('products.category_id', $allCategoryIds)
                ->sum(\Illuminate\Support\Facades\DB::raw('order_items.quantity * order_items.price'));
            
            $category->total_sales = $totalSale ?: 0;
            
            // Clean up children from the collection to keep response light
            unset($category->children);
            return $category;
        });
            
        return response()->json([
            'status' => 'success', 
            'Message' => 'Success',
            'data' => $categories
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'parent_id' => 'nullable|exists:categories,id',
            'status' => 'required|in:active,inactive',
        ]);

        $category = Category::create([
            'name' => $request->name,
            'slug' => Str::slug($request->name) . '-' . time(),
            'description' => $request->description,
            'image' => $request->image,
            'parent_id' => $request->parent_id,
            'status' => $request->status,
        ]);

        return response()->json(['status' => 'success', 'data' => $category]);
    }

    public function show($idOrSlug)
    {
        $query = Category::with(['children' => function($query) {
            $query->withCount('products')->with(['children' => function($q) {
                $q->withCount('products');
            }]);
        }]);

        if (is_numeric($idOrSlug)) {
            $category = $query->where('id', $idOrSlug)->firstOrFail();
        } else {
            $category = $query->where('slug', $idOrSlug)->firstOrFail();
        }
            
        return response()->json(['status' => 'success', 'data' => $category]);
    }

    public function tree()
    {
        // For parent dropdowns - returns a simple representation or flat tree
        $categories = Category::all();
        return response()->json(['status' => 'success', 'data' => $categories]);
    }

    public function update(Request $request, $id)
    {
        $category = Category::findOrFail($id);
        
        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'parent_id' => 'nullable|exists:categories,id',
            'status' => 'sometimes|required|in:active,inactive',
        ]);

        if ($request->has('name')) {
            $category->slug = Str::slug($request->name) . '-' . time();
        }

        $category->update($request->all());

        return response()->json(['status' => 'success', 'data' => $category]);
    }

    public function destroy($id)
    {
        $category = Category::findOrFail($id);
        $category->delete();
        return response()->json(['status' => 'success', 'message' => 'Category deleted successfully']);
    }
}
