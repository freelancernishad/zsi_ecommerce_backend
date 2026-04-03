<?php

namespace App\Models\Coupon;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    use HasFactory;

    protected $fillable = [
        'code', 'type', 'value', 'valid_from', 'valid_until', 'is_active', 'usage_limit', 'min_amount', 'max_amount'
    ];

    // Relationship with coupon associations (many-to-many)
    public function associations()
    {
        return $this->hasMany(CouponAssociation::class);
    }

    /**
     * Get all the usages of this coupon.
     */
    public function usages()
    {
        return $this->hasMany(CouponUsage::class);
    }

    /**
     * Check if the coupon is valid.
     */
    public function isValid()
    {
        $now = now();

        return $this->is_active && ($this->valid_from <= $now && $this->valid_until >= $now);
    }

    /**
     * Check if coupon usage limit is exceeded.
     */
    public function hasUsageLimit()
    {
        return $this->usage_limit && $this->usages->count() >= $this->usage_limit;
    }

    /**
     * Get the discount amount based on the coupon type.
     */
    public function getDiscountAmount($amount)
    {
        $discount = 0;
        if ($this->type == 'percentage') {
            $discount = ($this->value / 100) * $amount;
        } else {
            $discount = $this->value; // Fixed discount value
        }

        // Apply max_amount as a cap for the discount (common for percentage coupons)
        if ($this->max_amount && $discount > $this->max_amount) {
            return $this->max_amount;
        }

        return $discount;
    }
}
