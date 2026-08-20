<?php

namespace CjDropshipping\Helpers;

class CjHelper
{
    /**
     * Format raw CJ price string into a clean float value.
     */
    public static function formatPrice($price): float
    {
        if (is_numeric($price)) {
            return (float) $price;
        }

        if (is_string($price)) {
            $cleaned = preg_replace('/[^\d.]/', '', $price);
            return (float) $cleaned;
        }

        return 0.0;
    }

    /**
     * Calculate retail price based on cost and profit margin percentage.
     */
    public static function calculateRetailPrice(float $costPrice, float $marginPercentage = 20.0): float
    {
        return round($costPrice * (1 + ($marginPercentage / 100)), 2);
    }
}
