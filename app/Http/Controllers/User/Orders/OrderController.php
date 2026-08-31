<?php

namespace App\Http\Controllers\User\Orders;

use App\Http\Controllers\Controller;
use App\Models\Cart;
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


        try {
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


                $taxAmount = $request->tax;
                if ($taxAmount === null || $taxAmount === '') {
                    $taxRate = 0;
                    if ($address && $address->country && $address->state) {
                        $taxRecord = DB::table('taxes')
                            ->whereRaw('LOWER(TRIM(country)) = ?', [strtolower(trim($address->country))])
                            ->whereRaw('LOWER(TRIM(state)) = ?', [strtolower(trim($address->state))])
                            ->first();
                        if ($taxRecord) {
                            $taxRate = $taxRecord->tax_rate;
                        }
                    }
                    $subtotalAfterDiscount = max(0, $request->subtotal - ($request->discount ?? 0));
                    $taxAmount = round(($subtotalAfterDiscount * $taxRate) / 100, 2);
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
                    'tax' => $taxAmount,
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
                    $quantity = $item['quantity'];

                    if (isset($item['variant_id']) && $item['variant_id']) {
                        $variant = ProductVariant::findOrFail($item['variant_id']);
                        if (!$product->continue_selling) {
                            if ($variant->available_quantity < $quantity) {
                                throw new \Exception("Product variant '{$variant->combination_label}' is out of stock or does not have enough quantity.");
                            }
                        }
                        $variant->decrement('available_quantity', $quantity);
                    } else {
                        if (!$product->continue_selling) {
                            if ($product->stock_quantity < $quantity) {
                                throw new \Exception("Product '{$product->name}' is out of stock or does not have enough quantity.");
                            }
                        }
                        $product->decrement('stock_quantity', $quantity);
                    }

                    $price = $item['price'] ?? $product->price;

                    OrderItem::create([
                        'order_id' => $order->id,
                        'product_id' => $product->id,
                        'variant_id' => $item['variant_id'] ?? null,
                        'quantity' => $quantity,
                        'price' => $price,
                    ]);
                }

                // Clear user's cart on successful order placement
                Cart::where('user_id', Auth::id())->delete();

                return response()->json([
                    'status' => 'success', 
                    'message' => 'Order placed successfully',
                    'order_id' => $order->id,
                    'order_number' => $order->order_number
                ]);
            });
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 422);
        }
    }
}
