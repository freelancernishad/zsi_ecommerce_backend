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

    public function show(Request $request, $id)
    {
        $user = auth('api')->user() ?? Auth::user();
        if ($user) {
            $query = Order::where(function($q) use ($user) {
                $q->where('user_id', $user->id)->orWhereNull('user_id');
            })->with(['items.product.images', 'items.variant', 'shippingAddress', 'statusHistories']);
        } else {
            // Guest user: strictly restrict to guest orders where user_id IS NULL
            $query = Order::whereNull('user_id')
                ->with(['items.product.images', 'items.variant', 'shippingAddress', 'statusHistories']);
        }

        if (is_numeric($id)) {
            $order = $query->where('id', $id)->firstOrFail();
        } else {
            $order = $query->where('order_number', $id)->firstOrFail();
        }
        
        return response()->json(['status' => 'success', 'data' => $order]);
    }

    public function placeOrder(Request $request)
    {
        $user = auth('api')->user() ?? Auth::user();

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
            return DB::transaction(function () use ($request, $user) {
                $address = null;
                $shippingFirstName = '';
                $shippingLastName = '';
                $shippingEmail = '';
                $shippingPhone = '';
                $shippingCountry = '';
                $shippingStreetAddress = '';
                $shippingCity = '';
                $shippingState = '';
                $shippingZipCode = '';

                if ($request->shipping_address_id && $user) {
                    $address = $user->addresses()->findOrFail($request->shipping_address_id);
                    $shippingFirstName = $address->first_name;
                    $shippingLastName = $address->last_name;
                    $shippingEmail = $address->email;
                    $shippingPhone = $address->phone;
                    $shippingCountry = $address->country;
                    $shippingStreetAddress = $address->street_address;
                    $shippingCity = $address->city;
                    $shippingState = $address->state;
                    $shippingZipCode = $address->zip_code ?? $address->postal_code;
                } else if ($request->address_data) {
                    $nameParts = explode(' ', $request->address_data['fullName'] ?? '', 2);
                    $shippingFirstName = $nameParts[0] ?? '';
                    $shippingLastName = $nameParts[1] ?? '';
                    $shippingEmail = $request->address_data['email'] ?? $request->email ?? ($user ? $user->email : '');
                    $shippingPhone = $request->address_data['phone'] ?? '';
                    $shippingCountry = $request->address_data['country'] ?? '';
                    $shippingStreetAddress = $request->address_data['addressLine1'] ?? '';
                    $shippingCity = $request->address_data['city'] ?? '';
                    $shippingState = $request->address_data['state'] ?? '';
                    $shippingZipCode = $request->address_data['postalCode'] ?? '';

                    if ($user) {
                        $address = $user->addresses()->create([
                            'first_name' => $shippingFirstName,
                            'last_name' => $shippingLastName,
                            'email' => $shippingEmail ?: $user->email,
                            'phone' => $shippingPhone,
                            'country' => $shippingCountry,
                            'street_address' => $shippingStreetAddress,
                            'city' => $shippingCity,
                            'state' => $shippingState,
                            'zip_code' => $shippingZipCode,
                            'type' => 'shipping',
                            'is_default' => $request->address_data['isDefault'] ?? false,
                        ]);
                    }
                }

                $taxAmount = (float) ($request->tax ?? 0);
                $taxRate = (float) ($request->tax_rate ?? 0);
                $taxDetails = $request->tax_details ?? null;

                if ($taxAmount > 0 && $taxRate == 0 && $request->subtotal > 0) {
                    $subtotalAfterDiscount = max(0.01, $request->subtotal - ($request->discount ?? 0));
                    $taxRate = round(($taxAmount / $subtotalAfterDiscount) * 100, 4);
                }

                if ($taxAmount === null || $taxAmount === '') {
                    $countryToMatch = $address ? $address->country : $shippingCountry;
                    $stateToMatch = $address ? $address->state : $shippingState;
                    if ($countryToMatch && $stateToMatch) {
                        $taxRecord = DB::table('taxes')
                            ->whereRaw('LOWER(TRIM(country)) = ?', [strtolower(trim($countryToMatch))])
                            ->whereRaw('LOWER(TRIM(state)) = ?', [strtolower(trim($stateToMatch))])
                            ->first();
                        if ($taxRecord) {
                            $taxRate = (float) $taxRecord->tax_rate;
                        }
                    }
                    $stripeService = app(\App\Services\Gateways\StripeService::class);
                    $taxableSubtotal = 0;
                    if (!empty($request->items)) {
                        foreach ($request->items as $item) {
                            $pId = $item['product_id'] ?? null;
                            if ($stripeService->isProductTaxable($pId, $item)) {
                                $price = (float) ($item['price'] ?? 0);
                                $qty = (int) ($item['quantity'] ?? 1);
                                $taxableSubtotal += ($price * $qty);
                            }
                        }
                    } else {
                        $taxableSubtotal = max(0, $request->subtotal - ($request->discount ?? 0));
                    }
                    $taxAmount = round(($taxableSubtotal * $taxRate) / 100, 2);
                }

                $order = Order::create([
                    'order_number' => 'ORD-' . strtoupper(Str::random(10)),
                    'user_id' => $user ? $user->id : null,
                    'original_subtotal' => $request->original_subtotal,
                    'subtotal' => $request->subtotal,
                    'discount' => $request->discount ?? 0,
                    'coupon_code' => $request->coupon_code,
                    'coupon_type' => $request->coupon_type,
                    'coupon_value' => $request->coupon_value,
                    'tax' => $taxAmount,
                    'tax_rate' => $taxRate,
                    'tax_details' => $taxDetails,
                    'shipping_cost' => $request->shipping_cost ?? 0,
                    'total' => $request->total,
                    'status' => 'pending',
                    'payment_method' => $request->payment_method,
                    'payment_status' => $request->transaction_id ? 'paid' : 'pending',
                    'transaction_id' => $request->transaction_id,
                    'shipping_method' => $request->shipping_method,
                    'shipping_address_id' => $address ? $address->id : null,
                    
                    // Address snapshot
                    'shipping_first_name' => $shippingFirstName,
                    'shipping_last_name' => $shippingLastName,
                    'shipping_email' => $shippingEmail,
                    'shipping_phone' => $shippingPhone,
                    'shipping_country' => $shippingCountry,
                    'shipping_street_address' => $shippingStreetAddress,
                    'shipping_city' => $shippingCity,
                    'shipping_state' => $shippingState,
                    'shipping_zip_code' => $shippingZipCode,
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

                // Clear user's cart on successful order placement if logged in
                if ($user) {
                    Cart::where('user_id', $user->id)->delete();
                }

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
