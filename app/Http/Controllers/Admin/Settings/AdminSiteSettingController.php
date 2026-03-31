<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AdminSiteSettingController extends Controller
{
    /**
     * Display a listing of site settings by group.
     *
     * @param string $group
     * @return \Illuminate\Http\JsonResponse
     */
    public function index($group = 'general')
    {
        $settings = SiteSetting::where('group', $group)->get();
        
        // Transform to key-value object for easier frontend consumption
        $formattedSettings = [];
        foreach ($settings as $setting) {
            $formattedSettings[$setting->key] = $setting->value;
        }

        return response()->json([
            'success' => true,
            'group' => $group,
            'data' => $formattedSettings,
        ]);
    }

    /**
     * Update site settings in bulk.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request)
    {
        $settingsData = $request->input('settings', []);
        $group = $request->input('group', 'general');

        foreach ($settingsData as $key => $value) {
            SiteSetting::updateOrCreate(
                ['key' => $key],
                ['value' => $value, 'group' => $group]
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Settings updated successfully.',
        ]);
    }
}
