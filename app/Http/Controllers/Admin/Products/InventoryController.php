<?php

namespace App\Http\Controllers\Admin\Products;

use App\Http\Controllers\Controller;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryController extends Controller
{
    /**
     * Display a listing of product variants for inventory management.
     */
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 8);
        $search = $request->get('search');
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');

        $query = ProductVariant::with(['product:id,name', 'product.images'])
            ->withSum('orderItems as total_sale', 'quantity');

        // Search by Product Name or Variant SKU
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('sku', 'like', "%{$search}%")
                  ->orWhereHas('product', function ($pq) use ($search) {
                      $pq->where('name', 'like', "%{$search}%");
                  });
            });
        }

        // Sorting
        if ($sortBy === 'name') {
            $query->join('products', 'product_variants.product_id', '=', 'products.id')
                  ->orderBy('products.name', $sortOrder)
                  ->select('product_variants.*');
        } elseif ($sortBy === 'total_sale') {
            $query->orderBy('total_sale', $sortOrder);
        } else {
            $query->orderBy($sortBy, $sortOrder);
        }

        $variants = $query->paginate($perPage);

        // Transform to include absolute image URLs and clean numbers
        $variants->getCollection()->transform(function ($variant) {
            if ($variant->product && $variant->product->images) {
                $primaryImg = collect($variant->product->images)->where('is_primary', 1)->first() 
                            ?? collect($variant->product->images)->first();
                
                if ($primaryImg) {
                    $path = $primaryImg->image_path;
                    $variant->product_image = str_starts_with($path, 'http') 
                        ? $path 
                        : config('app.url') . '/storage/' . $path;
                } else {
                    $variant->product_image = null;
                }
                // Clean up nested images to save payload
                unset($variant->product->images);
            }
            
            $variant->total_sale = (int) ($variant->total_sale ?? 0);
            return $variant;
        });

        return response()->json([
            'Message' => 'Success',
            'isError' => false,
            'data' => $variants,
        ]);
    }

    /**
     * Update the available quantity for a specific variant.
     */
    public function updateQuantity(Request $request, $id)
    {
        $request->validate([
            'quantity' => 'required|integer|min:0',
        ]);

        $variant = ProductVariant::findOrFail($id);
        $variant->available_quantity = $request->quantity;
        $variant->save();

        return response()->json([
            'Message' => 'Inventory updated successfully',
            'isError' => false,
            'data' => $variant,
        ]);
    }
}
