<?php

namespace App\Http\Controllers\Ecommerce;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
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
            ->pluck('value', 'key');

        return response()->json([
            'success' => true,
            'data' => $settings
        ]);
    }
}
