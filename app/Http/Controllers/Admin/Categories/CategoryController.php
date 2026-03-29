<?php

namespace App\Http\Controllers\Admin\Categories;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    public function index()
    {
        // Root categories with their children recursively
        $categories = Category::whereNull('parent_id')
            ->with(['children' => function($query) {
                $query->with('children'); // Get sub-sub-categories
            }])
            ->get();
            
        return response()->json(['status' => 'success', 'data' => $categories]);
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

    public function show($slug)
    {
        // Find by slug instead of ID to support Next.js dynamic routes better
        $category = Category::where('slug', $slug)
            ->with(['children' => function($query) {
                $query->with('children'); // Nested sub-sub items
            }])
            ->firstOrFail();
            
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
