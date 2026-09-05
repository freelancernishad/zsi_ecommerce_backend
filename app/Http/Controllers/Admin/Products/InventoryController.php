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
                  ->orWhere('combination_label', 'like', "%{$search}%")
                  ->orWhereHas('product', function ($pq) use ($search) {
                      $pq->where('name', 'like', "%{$search}%");
                  });
            });
        }

        // Status Filter: low_stock, out_of_stock, in_stock
        $statusFilter = $request->get('status_filter');
        if ($statusFilter === 'low_stock') {
            $query->where('available_quantity', '<=', 5)->where('available_quantity', '>', 0);
        } elseif ($statusFilter === 'out_of_stock') {
            $query->where('available_quantity', '<=', 0);
        } elseif ($statusFilter === 'in_stock') {
            $query->where('available_quantity', '>', 5);
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
                unset($variant->product->images);
            }
            
            $variant->total_sale = (int) ($variant->total_sale ?? 0);
            $variant->barcode = $variant->sku ?: ('BAR-' . sprintf('%06d', $variant->id));
            return $variant;
        });

        $totalVariants = ProductVariant::count();
        $lowStockCount = ProductVariant::where('available_quantity', '<=', 5)->where('available_quantity', '>', 0)->count();
        $outOfStockCount = ProductVariant::where('available_quantity', '<=', 0)->count();
        $inStockCount = ProductVariant::where('available_quantity', '>', 5)->count();

        return response()->json([
            'Message' => 'Success',
            'isError' => false,
            'data' => $variants,
            'counts' => [
                'total' => $totalVariants,
                'low_stock' => $lowStockCount,
                'out_of_stock' => $outOfStockCount,
                'in_stock' => $inStockCount,
            ],
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

    /**
     * Bulk update inventory quantity for multiple selected variants.
     */
    public function bulkUpdateQuantity(Request $request)
    {
        $request->validate([
            'variant_ids' => 'required|array|min:1',
            'action' => 'required|in:set,add,subtract',
            'quantity' => 'required|integer|min:0',
        ]);

        $ids = $request->input('variant_ids');
        $action = $request->input('action');
        $qty = (int) $request->input('quantity');

        DB::transaction(function () use ($ids, $action, $qty) {
            foreach ($ids as $id) {
                $variant = ProductVariant::find($id);
                if (!$variant) continue;

                if ($action === 'set') {
                    $variant->available_quantity = $qty;
                } elseif ($action === 'add') {
                    $variant->available_quantity = max(0, $variant->available_quantity + $qty);
                } elseif ($action === 'subtract') {
                    $variant->available_quantity = max(0, $variant->available_quantity - $qty);
                }
                $variant->save();
            }
        });

        return response()->json([
            'Message' => 'Bulk inventory updated successfully',
            'isError' => false,
        ]);
    }

    /**
     * Export all inventory records as CSV.
     */
    public function exportCsv(Request $request)
    {
        $variants = ProductVariant::with('product:id,name')->get();

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="inventory_export_' . date('Y-m-d_H-i') . '.csv"',
        ];

        $callback = function () use ($variants) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['Variant ID', 'Product Name', 'Variant Combination', 'SKU', 'Barcode', 'Available Stock', 'Price']);

            foreach ($variants as $v) {
                fputcsv($file, [
                    $v->id,
                    $v->product->name ?? 'N/A',
                    $v->combination_label ?? 'Default',
                    $v->sku ?? '',
                    $v->sku ?? ('BAR-' . sprintf('%06d', $v->id)),
                    $v->available_quantity ?? 0,
                    $v->price ?? 0.00,
                ]);
            }
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Bulk import stock levels from uploaded CSV file.
     */
    public function importCsv(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:5120',
        ]);

        $file = $request->file('file');
        $handle = fopen($file->getRealPath(), 'r');
        $header = fgetcsv($handle);

        $updatedCount = 0;
        $errors = 0;

        while (($row = fgetcsv($handle)) !== false) {
            if (empty($row) || count($row) < 4) continue;

            $variantId = trim($row[0] ?? '');
            $sku = trim($row[3] ?? '');
            $stock = isset($row[5]) ? (int) trim($row[5]) : null;

            if ($stock === null) continue;

            $variant = null;
            if (is_numeric($variantId)) {
                $variant = ProductVariant::find($variantId);
            }
            if (!$variant && !empty($sku)) {
                $variant = ProductVariant::where('sku', $sku)->first();
            }

            if ($variant) {
                $variant->available_quantity = max(0, $stock);
                $variant->save();
                $updatedCount++;
            } else {
                $errors++;
            }
        }
        fclose($handle);

        return response()->json([
            'Message' => "CSV Processed! Updated {$updatedCount} inventory items.",
            'isError' => false,
            'updated' => $updatedCount,
            'failed' => $errors,
        ]);
    }
}
