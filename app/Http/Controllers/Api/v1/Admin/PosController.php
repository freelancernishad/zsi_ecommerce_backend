<?php

namespace App\Http\Controllers\Api\v1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PosController extends Controller
{
    /**
     * Search or scan a barcode/SKU to immediately retrieve product variant details.
     */
    public function scanBarcode(Request $request)
    {
        $code = trim($request->input('barcode') ?? $request->input('code') ?? '');

        if (!$code) {
            return response()->json([
                'success' => false,
                'message' => 'Barcode query string is required'
            ], 422);
        }

        // 1. Search in ProductVariant table by sku, barcode or id
        $variant = ProductVariant::with('product')
            ->where(function ($q) use ($code) {
                $q->where('sku', $code)
                  ->orWhere('id', $code);
                if (DB::getSchemaBuilder()->hasColumn('product_variants', 'barcode')) {
                    $q->orWhere('barcode', $code);
                }
            })
            ->first();

        if ($variant) {
            return response()->json([
                'success' => true,
                'data' => [
                    'variant_id' => $variant->id,
                    'product_id' => $variant->product_id,
                    'product_name' => $variant->product ? $variant->product->name : 'Product',
                    'combination_label' => $variant->combination_label,
                    'sku' => $variant->sku ?: "VAR-{$variant->id}",
                    'barcode' => isset($variant->barcode) && $variant->barcode ? $variant->barcode : ($variant->sku ?: "BAR-{$variant->id}"),
                    'price' => (float) ($variant->discount_price > 0 ? $variant->discount_price : $variant->price),
                    'regular_price' => (float) $variant->price,
                    'available_quantity' => (int) $variant->available_quantity,
                    'image' => $variant->product && $variant->product->thumbnail ? $variant->product->thumbnail : null,
                ]
            ]);
        }

        // 2. Fallback: Search Product table by SKU or Name
        $product = Product::with('variants')
            ->where('sku', $code)
            ->orWhere('name', 'LIKE', "%{$code}%")
            ->first();

        if ($product) {
            $firstVariant = $product->variants->first();
            return response()->json([
                'success' => true,
                'data' => [
                    'variant_id' => $firstVariant ? $firstVariant->id : 0,
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'combination_label' => $firstVariant ? $firstVariant->combination_label : null,
                    'sku' => $product->sku ?: "PROD-{$product->id}",
                    'barcode' => $firstVariant ? ($firstVariant->sku ?: "BAR-{$firstVariant->id}") : "PROD-{$product->id}",
                    'price' => (float) ($product->sale_price > 0 ? $product->sale_price : $product->price),
                    'regular_price' => (float) $product->price,
                    'available_quantity' => (int) ($firstVariant ? $firstVariant->available_quantity : $product->quantity),
                    'image' => $product->thumbnail ?? null,
                ]
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => "No product or variant found for code '{$code}'"
        ], 404);
    }

    /**
     * Get products & variants list for POS Touch Screen Grid.
     */
    /**
     * Get products & variants list for POS Touch Screen Grid.
     */
    public function getProducts(Request $request)
    {
        $search = trim($request->input('search', ''));
        $categoryId = $request->input('category_id');
        $perPage = (int) $request->input('per_page', 24);
        $page = (int) $request->input('page', 1);

        $getImageUrl = function ($product) {
            if (!$product || !$product->images) return null;
            $primaryImg = collect($product->images)->where('is_primary', 1)->first() 
                        ?? collect($product->images)->first();
            if (!$primaryImg || !$primaryImg->image_path) return null;
            
            $path = $primaryImg->image_path;
            if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
                return $path;
            }
            return config('app.url') . '/storage/' . ltrim($path, '/');
        };

        if ($search !== '') {
            // SEARCH MODE: Return direct matching variant cards
            $variantQuery = ProductVariant::with(['product.category', 'product.images'])
                ->where(function ($q) use ($search) {
                    $q->where('sku', 'LIKE', "%{$search}%")
                      ->orWhere('combination_label', 'LIKE', "%{$search}%")
                      ->orWhere('id', $search)
                      ->orWhereHas('product', function ($pq) use ($search) {
                          $pq->where('name', 'LIKE', "%{$search}%")
                             ->orWhere('sku', 'LIKE', "%{$search}%");
                      });
                });

            if ($categoryId) {
                $variantQuery->whereHas('product', function ($pq) use ($categoryId) {
                    $pq->where('category_id', $categoryId);
                });
            }

            $variants = $variantQuery->orderBy('id', 'desc')
                ->paginate($perPage, ['*'], 'page', $page);

            $items = [];
            foreach ($variants as $var) {
                $prod = $var->product;
                $prodImg = $getImageUrl($prod);
                $vPrice = (float) ($var->discount_price > 0 ? $var->discount_price : ($var->price > 0 ? $var->price : ($prod ? $prod->price : 0)));
                $vRegPrice = (float) ($var->price > 0 ? $var->price : ($prod ? $prod->price : 0));

                $items[] = [
                    'variant_id' => $var->id,
                    'product_id' => $var->product_id,
                    'product_name' => $prod ? $prod->name : 'Product',
                    'combination_label' => $var->combination_label,
                    'color' => $var->color ?? null,
                    'size' => $var->size ?? null,
                    'sku' => $var->sku ?: "VAR-{$var->id}",
                    'barcode' => isset($var->barcode) && $var->barcode ? $var->barcode : ($var->sku ?: "BAR-{$var->id}"),
                    'price' => $vPrice,
                    'regular_price' => $vRegPrice,
                    'available_quantity' => (int) $var->available_quantity,
                    'image' => $prodImg,
                    'category' => ($prod && $prod->category) ? $prod->category->name : 'Uncategorized',
                    'variants' => [], // Empty so frontend treats it as a single direct variant item!
                ];
            }

            $currentPage = $variants->currentPage();
            $lastPage = $variants->lastPage();
            $perPageVal = $variants->perPage();
            $totalVal = $variants->total();
        } else {
            // BROWSE MODE: Group products by main product
            $query = Product::with(['variants', 'category', 'images'])
                ->withCount('orderItems');

            if ($categoryId) {
                $query->where('category_id', $categoryId);
            }

            $products = $query->orderBy('order_items_count', 'desc')
                ->orderBy('id', 'desc')
                ->paginate($perPage, ['*'], 'page', $page);

            $items = [];
            foreach ($products as $prod) {
                $prodImg = $getImageUrl($prod);

                if ($prod->variants->count() > 0) {
                    $variantsList = [];
                    $totalQty = 0;
                    $minPrice = null;

                    foreach ($prod->variants as $var) {
                        $vPrice = (float) ($var->discount_price > 0 ? $var->discount_price : ($var->price > 0 ? $var->price : $prod->price));
                        $vRegPrice = (float) ($var->price > 0 ? $var->price : $prod->price);
                        $vQty = (int) $var->available_quantity;
                        $totalQty += $vQty;

                        if ($minPrice === null || $vPrice < $minPrice) {
                            $minPrice = $vPrice;
                        }

                        $variantsList[] = [
                            'variant_id' => $var->id,
                            'product_id' => $prod->id,
                            'product_name' => $prod->name,
                            'combination_label' => $var->combination_label,
                            'color' => $var->color ?? null,
                            'size' => $var->size ?? null,
                            'sku' => $var->sku ?: "VAR-{$var->id}",
                            'barcode' => isset($var->barcode) && $var->barcode ? $var->barcode : ($var->sku ?: "BAR-{$var->id}"),
                            'price' => $vPrice,
                            'regular_price' => $vRegPrice,
                            'available_quantity' => $vQty,
                            'image' => $prodImg,
                            'category' => $prod->category ? $prod->category->name : 'Uncategorized',
                        ];
                    }

                    $firstVar = $variantsList[0];

                    $items[] = [
                        'variant_id' => $firstVar['variant_id'],
                        'product_id' => $prod->id,
                        'product_name' => $prod->name,
                        'combination_label' => count($variantsList) === 1 ? $firstVar['combination_label'] : null,
                        'sku' => $prod->sku ?: $firstVar['sku'],
                        'barcode' => $prod->sku ?: $firstVar['barcode'],
                        'price' => $minPrice,
                        'regular_price' => (float) $prod->price,
                        'available_quantity' => $totalQty,
                        'image' => $prodImg,
                        'category' => $prod->category ? $prod->category->name : 'Uncategorized',
                        'variant_options' => $prod->variant_options,
                        'variants' => $variantsList,
                    ];
                } else {
                    $items[] = [
                        'variant_id' => 0,
                        'product_id' => $prod->id,
                        'product_name' => $prod->name,
                        'combination_label' => null,
                        'sku' => $prod->sku ?: "PROD-{$prod->id}",
                        'barcode' => "PROD-{$prod->id}",
                        'price' => (float) ($prod->sale_price > 0 ? $prod->sale_price : $prod->price),
                        'regular_price' => (float) $prod->price,
                        'available_quantity' => (int) $prod->quantity,
                        'image' => $prodImg,
                        'category' => $prod->category ? $prod->category->name : 'Uncategorized',
                        'variants' => [],
                    ];
                }
            }

            $currentPage = $products->currentPage();
            $lastPage = $products->lastPage();
            $perPageVal = $products->perPage();
            $totalVal = $products->total();
        }

        return response()->json([
            'success' => true,
            'data' => $items,
            'current_page' => $currentPage,
            'last_page' => $lastPage,
            'per_page' => $perPageVal,
            'total' => $totalVal,
            'pagination' => [
                'total' => $totalVal,
                'per_page' => $perPageVal,
                'current_page' => $currentPage,
                'last_page' => $lastPage,
            ],
            'meta' => [
                'total' => $totalVal,
                'per_page' => $perPageVal,
                'current_page' => $currentPage,
                'last_page' => $lastPage,
            ]
        ]);
    }

    /**
     * Create POS Order & instantly deduct stock.
     */
    public function createOrder(Request $request)
    {
        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.variant_id' => 'required|integer',
            'items.*.product_id' => 'required|integer',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
            'payment_method' => 'required|string',
            'tendered_amount' => 'nullable|numeric|min:0',
            'change_amount' => 'nullable|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'tax_amount' => 'nullable|numeric|min:0',
            'customer_name' => 'nullable|string',
            'customer_phone' => 'nullable|string',
            'customer_email' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($validated, $request) {
            $subtotal = 0;
            $itemsToCreate = [];

            foreach ($validated['items'] as $itemData) {
                $qty = (int) $itemData['quantity'];
                $price = (float) $itemData['unit_price'];
                $lineTotal = $qty * $price;
                $subtotal += $lineTotal;

                $variantId = (int) $itemData['variant_id'];
                $productId = (int) $itemData['product_id'];

                $pName = 'Product #' . $productId;
                $vLabel = null;
                $sku = null;

                if ($variantId > 0) {
                    $variant = ProductVariant::with('product')->lockForUpdate()->find($variantId);
                    if ($variant) {
                        // Atomic stock deduction
                        $variant->decrement('available_quantity', $qty);
                        $pName = $variant->product ? $variant->product->name : $pName;
                        $vLabel = $variant->combination_label;
                        $sku = $variant->sku;
                    }
                } else if ($productId > 0) {
                    $product = Product::lockForUpdate()->find($productId);
                    if ($product) {
                        $product->decrement('quantity', $qty);
                        $pName = $product->name;
                        $sku = $product->sku;
                    }
                }

                $itemsToCreate[] = [
                    'product_id' => $productId,
                    'variant_id' => $variantId > 0 ? $variantId : null,
                    'product_name' => $pName,
                    'combination_label' => $vLabel,
                    'sku' => $sku,
                    'quantity' => $qty,
                    'price' => $price,
                    'total' => $lineTotal,
                ];
            }

            $discount = (float) ($validated['discount_amount'] ?? 0);
            $tax = (float) ($validated['tax_amount'] ?? 0);
            $total = max(0, $subtotal - $discount + $tax);
            $tendered = (float) ($validated['tendered_amount'] ?? $total);
            $change = (float) ($validated['change_amount'] ?? max(0, $tendered - $total));

            $dateCode = now()->format('Ymd');
            $randomCode = strtoupper(substr(uniqid(), -4));
            $orderNumber = "POS-{$dateCode}-{$randomCode}";
            $receiptNumber = "RCPT-" . rand(100000, 999999);

            $cashierId = optional($request->user())->id ?: 1;
            $targetUserId = null;

            if (!empty($validated['customer_email'])) {
                $matchedUser = \App\Models\User::where('email', $validated['customer_email'])->first();
                if ($matchedUser) {
                    $targetUserId = $matchedUser->id;
                }
            }

            if (!$targetUserId) {
                $defaultUser = \App\Models\User::find($cashierId) ?? \App\Models\User::first();
                $targetUserId = $defaultUser ? $defaultUser->id : null;
            }

            $order = Order::create([
                'order_number' => $orderNumber,
                'order_type' => 'pos',
                'pos_receipt_number' => $receiptNumber,
                'pos_cashier_id' => $cashierId,
                'user_id' => $targetUserId,
                'original_subtotal' => $subtotal,
                'subtotal' => $subtotal,
                'discount' => $discount,
                'tax' => $tax,
                'shipping_cost' => 0,
                'total' => $total,
                'tendered_amount' => $tendered,
                'change_amount' => $change,
                'status' => 'completed',
                'payment_status' => 'paid',
                'fulfillment_status' => 'fulfilled',
                'delivery_status' => 'delivered',
                'payment_method' => $validated['payment_method'],
                'payment_method_name' => strtoupper($validated['payment_method']) . ' (POS Terminal)',
                'shipping_first_name' => $validated['customer_name'] ?? 'Walk-in Customer',
                'shipping_phone' => $validated['customer_phone'] ?? 'N/A',
                'shipping_email' => $validated['customer_email'] ?? 'walkin@store.local',
                'shipping_street_address' => 'Over the Counter Sale',
                'shipping_city' => 'In-Store',
            ]);

            foreach ($itemsToCreate as &$it) {
                $it['order_id'] = $order->id;
                OrderItem::create($it);
            }

            return response()->json([
                'success' => true,
                'message' => 'POS Order created successfully!',
                'data' => [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'receipt_number' => $order->pos_receipt_number,
                    'subtotal' => (float) $order->subtotal,
                    'discount' => (float) $order->discount,
                    'tax' => (float) $order->tax,
                    'total' => (float) $order->total,
                    'tendered_amount' => (float) $order->tendered_amount,
                    'change_amount' => (float) $order->change_amount,
                    'payment_method' => $order->payment_method,
                    'customer_name' => $order->shipping_first_name,
                    'customer_phone' => $order->shipping_phone,
                    'customer_email' => $order->shipping_email,
                    'created_at' => $order->created_at->format('Y-m-d H:i:s'),
                    'items' => $itemsToCreate,
                ]
            ]);
        });
    }

    /**
     * Get Shift/Daily POS Sales Summary for Cashier.
     */
    public function getShiftSummary(Request $request)
    {
        $today = now()->startOfDay();

        $posOrders = Order::where('order_type', 'pos')
            ->where('created_at', '>=', $today)
            ->get();

        $totalSales = $posOrders->sum('total');
        $totalOrders = $posOrders->count();
        $totalCash = $posOrders->where('payment_method', 'cash')->sum('total');
        $totalCard = $posOrders->where('payment_method', 'card')->sum('total');
        $totalMfs = $posOrders->whereIn('payment_method', ['mfs_bkash', 'mfs_nagad', 'bkash', 'nagad', 'mfs'])->sum('total');

        return response()->json([
            'success' => true,
            'data' => [
                'date' => now()->format('Y-m-d'),
                'total_sales' => (float) $totalSales,
                'total_orders' => $totalOrders,
                'cash_total' => (float) $totalCash,
                'card_total' => (float) $totalCard,
                'mfs_total' => (float) $totalMfs,
            ]
        ]);
    }
}
