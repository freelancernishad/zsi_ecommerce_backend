<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

class BrandController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Brand::query();
        
        if ($request->has('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        if ($request->has('show_on_home')) {
            $query->where('show_on_home', $request->boolean('show_on_home'));
        }

        $brands = $query->orderBy('home_order', 'asc')
            ->orderBy('id', 'desc')
            ->paginate($request->per_page ?? 10);
            
        return response()->json([
            'Message' => 'Success',
            'data' => $brands
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
            'status' => 'required|in:active,inactive',
            'show_on_home' => 'nullable|boolean',
            'home_order' => 'nullable|integer',
        ]);

        $data = $request->only(['name', 'description', 'status', 'show_on_home', 'home_order']);
        $data['slug'] = Str::slug($request->name) . '-' . time();

        if ($request->hasFile('logo')) {
            $data['logo'] = $request->file('logo')->store('brands', 'public');
        }

        $brand = Brand::create($data);

        return response()->json([
            'Message' => 'Success',
            'data' => $brand
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(Brand $brand)
    {
        return response()->json([
            'Message' => 'Success',
            'data' => $brand
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Brand $brand)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
            'status' => 'required|in:active,inactive',
            'show_on_home' => 'nullable|boolean',
            'home_order' => 'nullable|integer',
        ]);

        $data = $request->only(['name', 'description', 'status', 'show_on_home', 'home_order']);
        
        if ($brand->name !== $request->name) {
            $data['slug'] = Str::slug($request->name) . '-' . time();
        }

        if ($request->hasFile('logo')) {
            // Delete old logo
            if ($brand->logo) {
                Storage::disk('public')->delete($brand->logo);
            }
            $data['logo'] = $request->file('logo')->store('brands', 'public');
        }

        $brand->update($data);

        return response()->json([
            'Message' => 'Success',
            'data' => $brand
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Brand $brand)
    {
        if ($brand->logo) {
            Storage::disk('public')->delete($brand->logo);
        }
        
        $brand->delete();

        return response()->json([
            'Message' => 'Success'
        ]);
    }
}
