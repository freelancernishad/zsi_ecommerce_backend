<?php

namespace CjDropshipping\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Exception;

class CjDropshippingService
{
    protected function getBaseUrl(): string
    {
        return \App\Models\SiteSetting::where('key', 'cj_base_url')->value('value')
            ?: config('cj.base_url')
            ?: env('CJ_BASE_URL', 'https://developers.cjdropshipping.com/api2.0/v1');
    }

    /**
     * Get or fetch CJ Access Token (cached for 50 minutes).
     */
    public function getAccessToken(): string
    {
        return Cache::remember('cj_access_token', 3000, function () {
            $apiKey = \App\Models\SiteSetting::where('key', 'cj_api_key')->value('value')
                ?: config('cj.api_key')
                ?: env('CJ_API_KEY');

            if (!$apiKey) {
                throw new Exception('CJ_API_KEY is not configured in Admin Settings or .env');
            }

            $baseUrl = $this->getBaseUrl();

            $response = Http::post("{$baseUrl}/authentication/getAccessToken", [
                'apiKey' => $apiKey,
            ]);

            if ($response->successful() && isset($response->json()['data']['accessToken'])) {
                return $response->json()['data']['accessToken'];
            }

            Log::error('CJ Authentication Error:', ['response' => $response->json()]);
            throw new Exception('Failed to retrieve CJ Access Token: ' . ($response->json()['message'] ?? 'Unknown error'));
        });
    }

    /**
     * Search products on CJ Dropshipping by keyword or category.
     */
    public function searchProducts(string $keyword = 'hoodie', int $page = 1, int $size = 20, ?string $categoryId = null): array
    {
        $token = $this->getAccessToken();

        $queryParams = [
            'page' => $page,
            'size' => $size,
            'keyWord' => $keyword,
            'sort' => 'desc',
            'orderBy' => '0',
        ];

        if ($categoryId) {
            $queryParams['categoryId'] = $categoryId;
        }

        $response = Http::withHeaders([
            'CJ-Access-Token' => $token,
        ])->get("{$this->getBaseUrl()}/product/listV2", $queryParams);

        if ($response->successful() && isset($response->json()['data']['content'])) {
            $content = $response->json()['data']['content'];
            $products = [];

            if (is_array($content)) {
                foreach ($content as $item) {
                    if (isset($item['productList']) && is_array($item['productList'])) {
                        $products = array_merge($products, $item['productList']);
                    }
                }
            }

            return [
                'success' => true,
                'products' => $products,
                'total' => count($products),
                'page' => $page,
                'size' => $size,
            ];
        }

        return [
            'success' => false,
            'message' => $response->json()['message'] ?? 'Failed to fetch CJ products',
            'products' => [],
        ];
    }

    /**
     * Fetch full product details including variants from CJ.
     */
    public function getProductDetail(string $cjProductId): ?array
    {
        $token = $this->getAccessToken();

        $response = Http::withHeaders([
            'CJ-Access-Token' => $token,
        ])->get("{$this->getBaseUrl()}/product/query", [
            'pid' => $cjProductId,
        ]);

        if ($response->successful() && ($response->json()['code'] ?? 0) === 200) {
            return $response->json()['data'] ?? null;
        }

        Log::error('CJ Product Detail Error:', ['pid' => $cjProductId, 'response' => $response->json()]);
        return null;
    }

    /**
     * Calculate freight/shipping costs.
     */
    public function calculateShipping(string $endCountryCode, string $vid, int $quantity = 1, string $startCountryCode = 'CN'): array
    {
        $token = $this->getAccessToken();

        $payload = [
            'startCountryCode' => $startCountryCode,
            'endCountryCode' => $endCountryCode,
            'products' => [
                [
                    'quantity' => $quantity,
                    'vid' => $vid,
                ],
            ],
        ];

        $response = Http::withHeaders([
            'CJ-Access-Token' => $token,
        ])->post("{$this->getBaseUrl()}/logistic/freightCalculate", $payload);

        if ($response->successful() && ($response->json()['code'] ?? 0) === 200) {
            return $response->json()['data'] ?? [];
        }

        return [];
    }

    /**
     * Create an order in CJ Dropshipping system.
     */
    public function createOrder(array $orderPayload): array
    {
        $token = $this->getAccessToken();

        $response = Http::withHeaders([
            'CJ-Access-Token' => $token,
        ])->post("{$this->getBaseUrl()}/shopping/order/create", $orderPayload);

        if ($response->successful()) {
            return $response->json();
        }

        Log::error('CJ Order Creation Error:', ['payload' => $orderPayload, 'response' => $response->json()]);
        return [
            'code' => 500,
            'message' => $response->json()['message'] ?? 'Failed to create CJ order',
        ];
    }
}
