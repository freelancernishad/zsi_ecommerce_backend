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
            'shipping_address_id' => 'nullable|exists:addresses,id',
            'address_data' => 'nullable|array',
            'address_data.fullName' => 'required_without:shipping_address_id|string',
            'address_data.phone' => 'required_without:shipping_address_id|string',
            'address_data.country' => 'required_without:shipping_address_id|string',
            'address_data.addressLine1' => 'required_without:shipping_address_id|string',
            'address_data.city' => 'required_without:shipping_address_id|string',
            'address_data.state' => 'required_without:shipping_address_id|string',
            'address_data.postalCode' => 'required_without:shipping_address_id|string',
            'payment_method' => 'required|string',
            'subtotal' => 'required|numeric',
            'original_subtotal' => 'required|numeric',
            'discount' => 'nullable|numeric',
            'coupon_code' => 'nullable|string',
            'coupon_type' => 'nullable|string',
            'coupon_value' => 'nullable|numeric',
            'total' => 'required|numeric',
            'tax' => 'nullable|numeric',
            'shipping_cost' => 'nullable|numeric',
            'shipping_method' => 'nullable|string',
            'transaction_id' => 'nullable|string',
        ]);


        return DB::transaction(function () use ($request) {
            if ($request->shipping_address_id) {
                $address = Auth::user()->addresses()->findOrFail($request->shipping_address_id);
            } else {
                // Handle manual address entry
                $nameParts = explode(' ', $request->address_data['fullName'], 2);
                $firstName = $nameParts[0];
                $lastName = $nameParts[1] ?? '';

                $address = Auth::user()->addresses()->create([
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => Auth::user()->email,
                    'phone' => $request->address_data['phone'],
                    'country' => $request->address_data['country'],
                    'street_address' => $request->address_data['addressLine1'],
                    'city' => $request->address_data['city'],
                    'state' => $request->address_data['state'],
                    'zip_code' => $request->address_data['postalCode'],
                    'type' => 'shipping',
                    'is_default' => $request->address_data['isDefault'] ?? false,
                ]);
            }


            $order = Order::create([
                'order_number' => 'ORD-' . strtoupper(Str::random(10)),
                'user_id' => Auth::id(),
                'original_subtotal' => $request->original_subtotal,
                'subtotal' => $request->subtotal,
                'discount' => $request->discount ?? 0,
                'coupon_code' => $request->coupon_code,
                'coupon_type' => $request->coupon_type,
                'coupon_value' => $request->coupon_value,
                'tax' => $request->tax ?? 0,
                'shipping_cost' => $request->shipping_cost ?? 0,
                'total' => $request->total,
                'status' => 'pending',
                'payment_method' => $request->payment_method,
                'payment_status' => $request->transaction_id ? 'paid' : 'pending',
                'transaction_id' => $request->transaction_id,
                'shipping_method' => $request->shipping_method,
                'shipping_address_id' => $address->id,
                
                // Address snapshot
                'shipping_first_name' => $address->first_name,
                'shipping_last_name' => $address->last_name,
                'shipping_email' => $address->email,
                'shipping_phone' => $address->phone,
                'shipping_country' => $address->country,
                'shipping_street_address' => $address->street_address,
                'shipping_city' => $address->city,
                'shipping_state' => $address->state,
                'shipping_zip_code' => $address->postal_code ?? $address->zip_code,
            ]);

            foreach ($request->items as $item) {
                $product = Product::findOrFail($item['product_id']);
                $price = $item['price'] ?? $product->price;

                if (isset($item['variant_id'])) {
                    $variant = ProductVariant::findOrFail($item['variant_id']);
                    // If the frontend didn't pass a specific price, we could calculate it here.
                    // But for now we trust the subtotal/total sent (with basic validation).
                }

                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'variant_id' => $item['variant_id'] ?? null,
                    'quantity' => $item['quantity'],
                    'price' => $price,
                ]);
            }

            return response()->json([
                'status' => 'success', 
                'message' => 'Order placed successfully',
                'order_id' => $order->id,
                'order_number' => $order->order_number
            ]);
        });
    }
}
