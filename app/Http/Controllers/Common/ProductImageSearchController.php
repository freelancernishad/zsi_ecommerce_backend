<?php

namespace App\Http\Controllers\Common;

use App\Http\Controllers\Controller;
use App\Services\ProductImageSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProductImageSearchController extends Controller
{
    public function __construct(
        protected ProductImageSearchService $imageSearchService
    ) {
    }

    /**
     * Search published store products by uploaded image file or image URL.
     */
    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'image' => 'nullable|file|mimes:jpeg,jpg,png,webp,gif|max:5120',
            'image_url' => 'nullable|url|max:2000',
            'limit' => 'nullable|integer|min:1|max:30',
        ]);

        if (!$request->hasFile('image') && empty($validated['image_url'])) {
            return response()->json([
                'success' => false,
                'message' => 'Please upload an image file or provide an image URL.',
            ], 422);
        }

        $bytes = null;
        $mimeType = 'image/jpeg';

        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $bytes = @file_get_contents($file->getRealPath());
            $mimeType = $file->getMimeType() ?: 'image/jpeg';
        } elseif (!empty($validated['image_url'])) {
            try {
                $resp = Http::withOptions([
                    'verify' => false,
                    'curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4],
                ])->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                    'Accept' => 'image/webp,image/jpeg,image/png,image/*;q=0.8',
                ])->timeout(8)->get($validated['image_url']);

                if ($resp->successful()) {
                    $bytes = $resp->body();
                    $hdr = $resp->header('Content-Type') ?: 'image/jpeg';
                    $mimeType = explode(';', $hdr)[0];
                    if (!str_starts_with($mimeType, 'image/')) {
                        $mimeType = 'image/jpeg';
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Image search URL fetch failed: ' . $e->getMessage());
            }
        }

        if (empty($bytes) || strlen($bytes) < 100 || strlen($bytes) > 5 * 1024 * 1024) {
            return response()->json([
                'success' => false,
                'message' => 'Could not read a valid image (max 5MB). Please try another photo.',
            ], 422);
        }

        try {
            $limit = (int) ($validated['limit'] ?? 16);
            $result = $this->imageSearchService->searchByImage($bytes, $mimeType, $limit);

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (\Throwable $e) {
            Log::error('Storefront image search failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Image search failed: ' . $e->getMessage(),
            ], 500);
        }
    }
}
