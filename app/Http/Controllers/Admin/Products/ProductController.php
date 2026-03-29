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

    public function index(Request $request)
    {
        $perPage   = $request->get('per_page', 8);
        $status    = $request->get('status');
        $search    = $request->get('search');
        $sortBy    = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');

        // Whitelist of sortable columns (maps frontend key → DB column)
        $allowedSorts = [
            'name'           => 'name',
            'price'          => 'price',
            'sale_price'     => 'sale_price',
            'stock_quantity' => 'stock_quantity',
            'sku'            => 'sku',
            'status'         => 'status',
            'created_at'     => 'created_at',
            'total_sale'     => 'order_items_sum_quantity', // withSum alias
            'revenue'        => 'revenue',                  // Select alias
        ];

        $sortColumn = $allowedSorts[$sortBy] ?? 'created_at';
        $sortDir    = in_array(strtolower($sortOrder), ['asc', 'desc']) ? $sortOrder : 'desc';

        $query = Product::with(['category:id,name', 'images'])
            ->withSum('orderItems', 'quantity')
            ->addSelect([
                'revenue' => DB::table('order_items')
                    ->selectRaw('COALESCE(SUM(quantity * price), 0)')
                    ->whereColumn('product_id', 'products.id')
            ]);
        // NOTE: No explicit ->select() here!
        // withSum() appends its subquery to SELECT *. Calling ->select() after withSum
        // overwrites and removes the aggregate subquery, causing total_sale to always be 0.

        // Sorting — aggregate values need correlated subqueries
        if ($sortBy === 'total_sale') {
            $query->orderByRaw(
                "(SELECT COALESCE(SUM(oi.quantity), 0) FROM order_items oi WHERE oi.product_id = products.id) $sortDir"
            );
        } elseif ($sortBy === 'revenue') {
            $query->orderByRaw(
                "(SELECT COALESCE(SUM(oi.quantity * oi.price), 0) FROM order_items oi WHERE oi.product_id = products.id) $sortDir"
            );
        } else {
            $query->orderBy($sortColumn, $sortDir);
        }

        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%");
            });
        }

        $products = $query->paginate($perPage);

        $products->getCollection()->transform(function ($product) {
            $product->total_sale = (int) ($product->order_items_sum_quantity ?? 0);
            $product->revenue    = (float) ($product->revenue ?? 0);
            unset($product->order_items_sum_quantity);
            return $product;
        });

        return response()->json([
            'Message' => 'Success',
            'data'    => $products,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'price' => 'required|numeric',
            'category_id' => 'required|exists:categories,id',
            'images.*' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'sub_category_id' => 'nullable|exists:categories,id',
            'sub_sub_category_id' => 'nullable|exists:categories,id',
        ]);

        return DB::transaction(function () use ($request) {
            $product = Product::create([
                'name'                  => $request->name,
                'slug'                  => Str::slug($request->name) . '-' . time(),
                'description'           => $request->description,
                'short_description'      => $request->short_description,
                'price'                 => $request->price,
                'sale_price'            => $request->sale_price,
                'original_price'        => $request->original_price,
                'cost_per_item'         => $request->cost_per_item,
                'stock_quantity'        => $request->stock_quantity ?? 0,
                'sku'                   => $request->sku,
                'barcode'               => $request->barcode,
                'category_id'           => $request->category_id,
                'sub_category_id'       => $request->sub_category_id,
                'sub_sub_category_id'   => $request->sub_sub_category_id,
                'brand'                 => $request->brand,
                'status'                => $request->status ?? 'published',
                'weight'                => $request->weight,
                'weight_unit'           => $request->weight_unit ?? 'kg',
                'height'                => $request->height,
                'height_unit'           => $request->height_unit ?? 'ft',
                'width'                 => $request->width,
                'width_unit'            => $request->width_unit ?? 'ft',
                'seo_title'             => $request->seo_title,
                'seo_description'       => $request->seo_description,
                'url_handle'            => $request->url_handle,
                'continue_selling'      => $request->continue_selling ?? false,
                'is_physical'           => $request->is_physical ?? true,
                'charge_tax'            => $request->charge_tax ?? true,
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

            // Handle Dynamic Variant Combinations
            if ($request->has('variant_combinations')) {
                foreach ($request->variant_combinations as $variant) {
                    ProductVariant::create([
                        'product_id'         => $product->id,
                        'combination_label'  => $variant['label'] ?? null,
                        'price'              => $variant['price'] ?? $product->price,
                        'discount_price'     => $variant['discount_price'] ?? null,
                        'sku'                => $variant['sku'] ?? null,
                        'available_quantity' => $variant['qty'] ?? 0,
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

    public function bulkUpdateStatus(Request $request)
    {
        $request->validate([
            'product_ids' => 'required|array',
            'product_ids.*' => 'integer|exists:products,id',
            'status' => 'required|in:published,draft,archived',
        ]);

        Product::whereIn('id', $request->product_ids)->update(['status' => $request->status]);

        return response()->json([
            'status' => 'success',
            'message' => count($request->product_ids) . ' products moved to ' . $request->status
        ]);
    }

    public function bulkDestroy(Request $request)
    {
        $request->validate([
            'product_ids' => 'required|array',
            'product_ids.*' => 'integer|exists:products,id',
        ]);

        Product::destroy($request->product_ids);

        return response()->json([
            'status' => 'success',
            'message' => count($request->product_ids) . ' products deleted successfully'
        ]);
    }
}
