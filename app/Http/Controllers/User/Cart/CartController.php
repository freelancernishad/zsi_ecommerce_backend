<?php

namespace App\Http\Controllers\User\Cart;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CartController extends Controller
{
    /**
     * Normalize selected_options into a sorted key-value array, or null when empty.
     */
    private function normalizeSelectedOptions(?array $options): ?array
    {
        if (empty($options)) {
            return null;
        }

        $normalized = [];
        foreach ($options as $key => $value) {
            $k = trim((string) $key);
            $v = is_scalar($value) ? trim((string) $value) : '';
            if ($k !== '' && $v !== '') {
                $normalized[$k] = $v;
            }
        }

        if (empty($normalized)) {
            return null;
        }

        ksort($normalized);
        return $normalized;
    }

    /**
     * Resolve and validate variant_id for a product using explicit variant_id or selected_options.
     */
    private function resolveVariantId(int $productId, ?int $variantId, ?array $selectedOptions): ?int
    {
        if ($variantId !== null) {
            $exists = ProductVariant::where('id', $variantId)
                ->where('product_id', $productId)
                ->exists();
            if (!$exists) {
                $variantId = null;
            }
        }

        // Hybrid Resolution: If variant_id is missing but selected_options are provided
        if ($variantId === null && !empty($selectedOptions)) {
            $query = ProductVariant::where('product_id', $productId);

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

        return $variantId;
    }

    /**
     * Find an existing cart row for a user matching product, variant, and normalized selected_options.
     */
    private function findExistingCartItem(int $userId, int $productId, ?int $variantId, ?array $normalizedOptions): ?Cart
    {
        if ($variantId !== null) {
            return Cart::where('user_id', $userId)
                ->where('product_id', $productId)
                ->where('variant_id', $variantId)
                ->first();
        }

        $candidates = Cart::where('user_id', $userId)
            ->where('product_id', $productId)
            ->whereNull('variant_id')
            ->get();

        foreach ($candidates as $candidate) {
            $candidateOptions = $this->normalizeSelectedOptions(
                is_array($candidate->selected_options) ? $candidate->selected_options : null
            );
            if ($candidateOptions === $normalizedOptions) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Attach computed stock availability flags to a cart item.
     */
    private function formatCartItem(Cart $item): Cart
    {
        $availableStock = 0;
        if ($item->variant_id && $item->variant) {
            $availableStock = (int) ($item->variant->available_quantity ?? $item->variant->stock ?? 0);
        } elseif ($item->product) {
            $availableStock = (int) ($item->product->stock_quantity ?? 0);
        }

        $continueSelling = (bool) ($item->product->continue_selling ?? false);
        $item->available_stock = $continueSelling ? null : $availableStock;
        $item->is_out_of_stock = ($availableStock <= 0) && !$continueSelling;
        $item->has_insufficient_stock = ($availableStock < $item->quantity) && !$continueSelling;

        return $item;
    }

    /**
     * Return all formatted cart items for a user after pruning orphaned rows.
     */
    private function getUserFormattedCart(int $userId)
    {
        // Automatically remove cart items where the corresponding product has been deleted
        Cart::where('user_id', $userId)
            ->whereDoesntHave('product')
            ->delete();

        return Cart::where('user_id', $userId)
            ->with(['product.images', 'product.category', 'variant'])
            ->orderBy('id', 'asc')
            ->get()
            ->map(fn (Cart $item) => $this->formatCartItem($item))
            ->values();
    }

    public function index()
    {
        $cartItems = $this->getUserFormattedCart((int) Auth::id());

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

        // Just-in-Time (JIT) Supplier Sync on Add to Cart (Amazon, CJ Dropshipping, AliExpress)
        // Only calls API if product sync is stale; also protects variant-level out-of-stock adds.
        try {
            $targetProduct = Product::find($request->product_id);
            $isDropship = $targetProduct && (
                ($targetProduct->is_amazon_product && !empty($targetProduct->amazon_asin)) ||
                ($targetProduct->is_cj_product && !empty($targetProduct->cj_product_id)) ||
                ($targetProduct->is_aliexpress_product && !empty($targetProduct->aliexpress_product_id))
            );
            if ($isDropship) {
                $jitRes = app(\App\Services\InventorySyncService::class)->jitSyncProduct($targetProduct->id, 'cart');
                if (!empty($jitRes['synced'])) {
                    $targetProduct->refresh();
                    if ((int) $targetProduct->stock_quantity <= 0 && !$targetProduct->continue_selling) {
                        return response()->json([
                            'status' => 'error',
                            'message' => 'Sorry, this product is currently out of stock.',
                        ], 422);
                    }
                    if ($request->variant_id) {
                        $refreshedVariant = ProductVariant::find($request->variant_id);
                        if ($refreshedVariant && (int) $refreshedVariant->available_quantity <= 0 && !$targetProduct->continue_selling) {
                            return response()->json([
                                'status' => 'error',
                                'message' => 'Sorry, the selected option is currently out of stock.',
                            ], 422);
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("Supplier JIT sync on addToCart skipped for #{$request->product_id}: " . $e->getMessage());
        }

        $userId = (int) Auth::id();
        $productId = (int) $request->product_id;
        $normalizedOptions = $this->normalizeSelectedOptions(
            is_array($request->selected_options) ? $request->selected_options : null
        );
        $variantId = $this->resolveVariantId(
            $productId,
            $request->variant_id !== null ? (int) $request->variant_id : null,
            $normalizedOptions
        );

        $cart = $this->findExistingCartItem($userId, $productId, $variantId, $normalizedOptions);

        if ($cart) {
            $cart->increment('quantity', (int) $request->quantity);
            if ($normalizedOptions !== null) {
                $cart->update(['selected_options' => $normalizedOptions]);
            }
        } else {
            $cart = Cart::create([
                'user_id' => $userId,
                'product_id' => $productId,
                'variant_id' => $variantId,
                'selected_options' => $normalizedOptions,
                'quantity' => (int) $request->quantity,
            ]);
        }

        $freshCart = $cart->fresh(['product.images', 'product.category', 'variant']);

        return response()->json([
            'status' => 'success',
            'message' => 'Product added to cart',
            'data' => $this->formatCartItem($freshCart),
        ]);
    }

    public function syncCart(Request $request)
    {
        $request->validate([
            'items' => 'present|array',
            'items.*.product_id' => 'required|integer',
            'items.*.variant_id' => 'nullable|integer',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.selected_options' => 'nullable|array',
        ]);

        $userId = (int) Auth::id();

        foreach ($request->input('items', []) as $item) {
            $productId = (int) $item['product_id'];
            if (!Product::where('id', $productId)->exists()) {
                continue;
            }

            $normalizedOptions = $this->normalizeSelectedOptions(
                isset($item['selected_options']) && is_array($item['selected_options'])
                    ? $item['selected_options']
                    : null
            );
            $rawVariantId = isset($item['variant_id']) && $item['variant_id'] !== null
                ? (int) $item['variant_id']
                : null;
            $variantId = $this->resolveVariantId($productId, $rawVariantId, $normalizedOptions);
            $quantity = max(1, (int) $item['quantity']);

            $existing = $this->findExistingCartItem($userId, $productId, $variantId, $normalizedOptions);
            if ($existing) {
                $existing->increment('quantity', $quantity);
                if ($normalizedOptions !== null && empty($existing->selected_options)) {
                    $existing->update(['selected_options' => $normalizedOptions]);
                }
            } else {
                Cart::create([
                    'user_id' => $userId,
                    'product_id' => $productId,
                    'variant_id' => $variantId,
                    'selected_options' => $normalizedOptions,
                    'quantity' => $quantity,
                ]);
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Cart synced successfully',
            'data' => $this->getUserFormattedCart($userId),
        ]);
    }

    public function updateQuantity(Request $request, $id)
    {
        $request->validate([
            'quantity' => 'required|integer|min:1',
        ]);

        $cart = Cart::where('user_id', Auth::id())->findOrFail($id);
        $cart->update(['quantity' => (int) $request->quantity]);

        $freshCart = $cart->fresh(['product.images', 'product.category', 'variant']);

        return response()->json([
            'status' => 'success',
            'message' => 'Cart updated',
            'data' => $this->formatCartItem($freshCart),
        ]);
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
