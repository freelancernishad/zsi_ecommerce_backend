<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

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

    /**
     * Upload a custom font file (.ttf, .otf, .woff, .woff2).
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function uploadFont(Request $request)
    {
        if (!$request->hasFile('file')) {
            return response()->json([
                'success' => false,
                'message' => 'No font file uploaded.',
            ], 422);
        }

        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());
        $allowedExtensions = ['ttf', 'otf', 'woff', 'woff2'];

        if (!in_array($ext, $allowedExtensions, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid font format. Allowed formats: .ttf, .otf, .woff, .woff2',
            ], 422);
        }

        if ($file->getSize() > 10 * 1024 * 1024) {
            return response()->json([
                'success' => false,
                'message' => 'Font file size must be 10MB or less.',
            ], 422);
        }

        $originalBase = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $slug = Str::slug($originalBase) ?: 'custom-font';
        $filename = $slug . '-' . time() . '.' . $ext;

        $destinationDir = public_path('uploads/fonts');
        if (!is_dir($destinationDir)) {
            mkdir($destinationDir, 0755, true);
        }

        $file->move($destinationDir, $filename);
        $relativePath = '/uploads/fonts/' . $filename;

        return response()->json([
            'success' => true,
            'message' => 'Font file uploaded successfully.',
            'data' => [
                'path' => $relativePath,
                'url' => url($relativePath),
                'format' => $ext,
                'name' => $originalBase,
            ],
        ]);
    }
}
