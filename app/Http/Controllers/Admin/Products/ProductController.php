<?php

namespace App\Http\Controllers\Admin\Products;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Services\FileSystem\FileUploadService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    protected $fileUploadService;

    public function __construct(FileUploadService $fileUploadService)
    {
        $this->fileUploadService = $fileUploadService;
    }

    public function index()
    {
        $products = Product::with(['category', 'images'])->get();
        return response()->json(['status' => 'success', 'data' => $products]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'price' => 'required|numeric',
            'category_id' => 'required|exists:categories,id',
            'images.*' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
        ]);

        return DB::transaction(function () use ($request) {
            $product = Product::create([
                'name' => $request->name,
                'slug' => Str::slug($request->name) . '-' . time(),
                'description' => $request->description,
                'short_description' => $request->short_description,
                'price' => $request->price,
                'original_price' => $request->original_price,
                'stock_quantity' => $request->stock_quantity ?? 0,
                'sku' => $request->sku,
                'category_id' => $request->category_id,
                'brand' => $request->brand,
                'status' => $request->status ?? 'published',
            ]);

            // Handle Images
            if ($request->hasFile('images')) {
                foreach ($request->file('images') as $index => $image) {
                    $path = $this->fileUploadService->uploadFileToS3($image, 'products/images');
                    ProductImage::create([
                        'product_id' => $product->id,
                        'image_path' => $path,
                        'is_primary' => $index === 0,
                    ]);
                }
            }

            // Handle Variants if provided
            if ($request->has('variants')) {
                foreach ($request->variants as $variant) {
                    ProductVariant::create([
                        'product_id' => $product->id,
                        'color' => $variant['color'] ?? null,
                        'size' => $variant['size'] ?? null,
                        'stock' => $variant['stock'] ?? 0,
                        'price_adjustment' => $variant['price_adjustment'] ?? 0,
                    ]);
                }
            }

            return response()->json(['status' => 'success', 'data' => $product->load(['images', 'variants'])]);
        });
    }

    public function show($id)
    {
        $product = Product::with(['category', 'images', 'variants', 'reviews.user'])->findOrFail($id);
        return response()->json(['status' => 'success', 'data' => $product]);
    }

    public function update(Request $request, $id)
    {
        $product = Product::findOrFail($id);
        
        // Similar to store but for updating
        $product->update($request->all());

        if ($request->has('name')) {
            $product->slug = Str::slug($request->name) . '-' . time();
            $product->save();
        }

        return response()->json(['status' => 'success', 'data' => $product]);
    }

    public function destroy($id)
    {
        $product = Product::findOrFail($id);
        $product->delete(); // Cascades will handle images/variants if defined in migration
        return response()->json(['status' => 'success', 'message' => 'Product deleted successfully']);
    }
}
