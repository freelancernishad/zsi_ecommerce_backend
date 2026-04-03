<?php

namespace App\Http\Controllers\User\Cart;

use App\Http\Controllers\Controller;
use App\Models\Coupon\Coupon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class UserCouponController extends Controller
{
    /**
     * Validate a coupon code and return discount details.
     */
    public function validateCoupon(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'coupon_code' => 'required|string|exists:coupons,code',
            'product_amount' => 'required|numeric|min:0',
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'isError' => true,
                'Message' => 'Invalid coupon code or request data.',
                'errors' => $validator->errors()
            ], 422);
        }

        $coupon = Coupon::where('code', $request->coupon_code)->first();

        // Check if the coupon is valid (active and within dates)
        if (!$coupon || !$coupon->is_active || !$coupon->isValid()) {
            return response()->json([
                'isError' => true,
                'Message' => 'Coupon is inactive or expired.'
            ], 400);
        }

        // Check usage limit
        if ($coupon->hasUsageLimit()) {
            return response()->json([
                'isError' => true,
                'Message' => 'Coupon usage limit has been reached.'
            ], 400);
        }

        $productAmount = (float) $request->product_amount;

        // Check Minimum Amount
        if ($coupon->min_amount && $productAmount < $coupon->min_amount) {
            return response()->json([
                'isError' => true,
                'Message' => 'Minimum order amount for this coupon is $' . number_format($coupon->min_amount, 2)
            ], 400);
        }

        // Check User Associations (Specific Users)
        $userAssociations = $coupon->associations()->where('item_type', 'user')->get();
        if ($userAssociations->count() > 0) {
            $userId = auth()->id();
            if (!$userId || !$userAssociations->contains('item_id', $userId)) {
                return response()->json([
                    'isError' => true,
                    'Message' => 'This coupon is not valid for your account.'
                ], 403);
            }
        }

        // Check Product Associations (Specific Products)
        $productAssociations = $coupon->associations()->where('item_type', 'product')->get();
        if ($productAssociations->count() > 0) {
            $cartProductIds = $request->product_ids ?? [];
            $matchingProducts = $productAssociations->whereIn('item_id', $cartProductIds);
            
            if ($matchingProducts->count() === 0) {
                return response()->json([
                    'isError' => true,
                    'Message' => 'This coupon is only valid for specific products not currently in your selection.'
                ], 400);
            }
        }

        // Calculate discount
        $discountAmount = $coupon->getDiscountAmount($productAmount);
        $finalAmount = max(0, $productAmount - $discountAmount);

        return response()->json([
            'isError' => false,
            'Message' => 'Coupon applied successfully.',
            'data' => [
                'id' => $coupon->id,
                'code' => $coupon->code,
                'type' => $coupon->type,
                'value' => $coupon->value,
                'discount_amount' => round($discountAmount, 2),
                'final_amount' => round($finalAmount, 2),
                'original_amount' => $productAmount,
            ]
        ], 200);
    }
}
