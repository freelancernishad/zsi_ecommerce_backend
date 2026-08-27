<?php

namespace App\Http\Controllers\Ecommerce;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use App\Models\ShippingMethod;
use App\Models\Tax;
use Illuminate\Http\Request;

class EcommerceSettingController extends Controller
{
    /**
     * Get settings by group for public use.
     */
    public function getSettingsByGroup($group)
    {
        $settings = SiteSetting::where('group', $group)
            ->get()
            ->pluck('value', 'key')
            ->toArray();

        if ($group === 'payments') {
            // NEVER expose sensitive secret keys in public API response
            $sensitiveKeys = [
                'stripe_secret_key',
                'stripe_webhook_secret',
                'bkash_app_key',
                'bkash_app_secret',
                'bkash_username',
                'bkash_password',
                'sslcommerz_store_id',
                'sslcommerz_store_passwd',
            ];
            foreach ($sensitiveKeys as $key) {
                unset($settings[$key]);
            }
        }

        return response()->json([
            'success' => true,
            'data' => $settings
        ]);
    }

    /**
     * Get active shipping methods.
     */
    public function getShippingMethods()
    {
        $methods = ShippingMethod::where('is_active', true)->get();

        return response()->json([
            'success' => true,
            'data' => $methods
        ]);
    }

    /**
     * Get all tax rates.
     */
    public function getTaxRates()
    {
        $taxes = Tax::all();

        return response()->json([
            'success' => true,
            'data' => $taxes
        ]);
    }
}
