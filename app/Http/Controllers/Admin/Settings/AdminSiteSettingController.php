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

        if ($group === 'amazon' && !array_key_exists('amazon_sync_on_product_view', $formattedSettings)) {
            $jitSetting = SiteSetting::where('group', 'amazon_sync')
                ->where('key', 'jit_on_product_view')
                ->first();
            $jitRaw = $jitSetting ? $jitSetting->value : '0';
            $formattedSettings['amazon_sync_on_product_view'] = in_array((string) $jitRaw, ['1', 'true'], true) ? '1' : '0';
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

        if ($group === 'amazon' && array_key_exists('amazon_sync_on_product_view', $settingsData)) {
            $normalized = in_array((string) $settingsData['amazon_sync_on_product_view'], ['1', 'true'], true) ? '1' : '0';
            SiteSetting::updateOrCreate(
                ['key' => 'amazon_sync_on_product_view'],
                ['value' => $normalized, 'group' => 'amazon']
            );
            SiteSetting::updateOrCreate(
                ['key' => 'jit_on_product_view'],
                ['value' => $normalized, 'group' => 'amazon_sync']
            );
        }

        $applePayDomainSync = null;
        if ($group === 'payments') {
            $stripeEnabled = in_array((string) ($settingsData['stripe_enabled'] ?? '0'), ['1', 'true'], true);
            $applePayEnabled = in_array((string) ($settingsData['stripe_apple_pay_enabled'] ?? '0'), ['1', 'true'], true);
            if ($stripeEnabled && $applePayEnabled) {
                try {
                    $applePayDomainSync = app(AdminStripePaymentDomainController::class)->syncDomainsForCurrentTenant($request);
                } catch (\Throwable $e) {
                    $applePayDomainSync = ['error' => $e->getMessage()];
                }
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Settings updated successfully.',
            ...($applePayDomainSync !== null ? ['apple_pay_domain_sync' => $applePayDomainSync] : []),
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
