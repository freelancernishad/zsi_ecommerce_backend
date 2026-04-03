<?php

namespace App\Http\Controllers\User\Cart;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CartController extends Controller
{
    public function index()
    {
        $cartItems = Cart::where('user_id', Auth::id())
            ->with(['product.images', 'product.category', 'variant'])
            ->get();
        
        return response()->json(['status' => 'success', 'data' => $cartItems]);
    }

    public function addToCart(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
            'variant_id' => 'nullable|exists:product_variants,id',
            'selected_options' => 'nullable|array',
        ]);

        $variantId = $request->variant_id;
        $selectedOptions = $request->selected_options;

        // Hybrid Resolution: If variant_id is missing but selected_options are provided
        if (!$variantId && !empty($selectedOptions)) {
            $query = \App\Models\ProductVariant::where('product_id', $request->product_id);

            foreach ($selectedOptions as $key => $value) {
                $k = strtolower($key);
                if (str_contains($k, 'color')) {
                    $query->where('color', 'like', $value);
                } elseif (str_contains($k, 'size')) {
                    $query->where('size', 'like', $value);
                } else {
                    // Fallback: search in combination_label
                    $query->where('combination_label', 'like', '%' . $value . '%');
                }
            }

            $variant = $query->first();
            if ($variant) {
                $variantId = $variant->id;
            }
        }

        $cart = Cart::where('user_id', Auth::id())
            ->where('product_id', $request->product_id)
            ->where('variant_id', $variantId)
            ->first();

        if ($cart) {
            $cart->increment('quantity', $request->quantity);
        } else {
            $cart = Cart::create([
                'user_id' => Auth::id(),
                'product_id' => $request->product_id,
                'variant_id' => $variantId,
                'quantity' => $request->quantity,
            ]);
        }

        return response()->json([
            'status' => 'success', 
            'message' => 'Product added to cart', 
            'data' => $cart->load(['product.images', 'variant'])
        ]);
    }

    public function updateQuantity(Request $request, $id)
    {
        $request->validate([
            'quantity' => 'required|integer|min:1',
        ]);

        $cart = Cart::where('user_id', Auth::id())->findOrFail($id);
        $cart->update(['quantity' => $request->quantity]);

        return response()->json(['status' => 'success', 'message' => 'Cart updated', 'data' => $cart]);
    }

    public function removeFromCart($id)
    {
        Cart::where('user_id', Auth::id())->findOrFail($id)->delete();
        return response()->json(['status' => 'success', 'message' => 'Item removed from cart']);
    }

    public function clearCart()
    {
        Cart::where('user_id', Auth::id())->delete();
        return response()->json(['status' => 'success', 'message' => 'Cart cleared successfully']);
    }
}
