<?php

namespace App\Http\Controllers\User\Orders;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderController extends Controller
{
    public function index()
    {
        $orders = Order::where('user_id', Auth::id())
            ->with(['items.product.images', 'items.variant'])
            ->latest()
            ->get();
        return response()->json(['status' => 'success', 'data' => $orders]);
    }

    public function show($id)
    {
        $order = Order::where('user_id', Auth::id())
            ->with(['items.product.images', 'items.variant', 'shippingAddress', 'statusHistories'])
            ->findOrFail($id);
        
        return response()->json(['status' => 'success', 'data' => $order]);
    }

    public function placeOrder(Request $request)
    {
        $request->validate([
            'items' => 'required|array',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'shipping_address_id' => 'required|exists:addresses,id',
            'payment_method' => 'required|string',
        ]);

        return DB::transaction(function () use ($request) {
            $subtotal = 0;
            $itemsToCreate = [];

            foreach ($request->items as $item) {
                $product = Product::findOrFail($item['product_id']);
                $price = $product->price;

                if (isset($item['variant_id'])) {
                    $variant = ProductVariant::findOrFail($item['variant_id']);
                    $price += $variant->price_adjustment;
                }

                $subtotal += $price * $item['quantity'];

                $itemsToCreate[] = [
                    'product_id' => $product->id,
                    'variant_id' => $item['variant_id'] ?? null,
                    'quantity' => $item['quantity'],
                    'price' => $price,
                ];
            }

            $order = Order::create([
                'order_number' => 'ORD-' . strtoupper(Str::random(10)),
                'user_id' => Auth::id(),
                'subtotal' => $subtotal,
                'total' => $subtotal + ($request->shipping_cost ?? 0),
                'shipping_cost' => $request->shipping_cost ?? 0,
                'status' => 'pending',
                'payment_method' => $request->payment_method,
                'shipping_address_id' => $request->shipping_address_id,
            ]);

            foreach ($itemsToCreate as $itemData) {
                $itemData['order_id'] = $order->id;
                OrderItem::create($itemData);
            }

            return response()->json([
                'status' => 'success', 
                'message' => 'Order placed successfully',
                'order_number' => $order->order_number
            ]);
        });
    }
}
