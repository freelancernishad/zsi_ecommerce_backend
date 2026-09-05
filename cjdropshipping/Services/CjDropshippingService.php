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
     * Search products on CJ Dropshipping by keyword, category, country, price range, free shipping, video availability.
     */
    public function searchProducts(
        string $keyword = 'hoodie',
        int $page = 1,
        int $size = 20,
        ?string $categoryId = null,
        ?string $countryCode = null,
        ?float $minPrice = null,
        ?float $maxPrice = null,
        ?int $freeShipping = null,
        ?int $productType = null
    ): array {
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

        if ($countryCode) {
            $queryParams['countryCode'] = strtoupper($countryCode);
        }

        if ($minPrice !== null && $minPrice > 0) {
            $queryParams['startSellPrice'] = $minPrice;
        }

        if ($maxPrice !== null && $maxPrice > 0) {
            $queryParams['endSellPrice'] = $maxPrice;
        }

        if ($freeShipping !== null) {
            $queryParams['addMarkStatus'] = $freeShipping;
        }

        if ($productType !== null) {
            $queryParams['productType'] = $productType;
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

            // Extract dynamic facets from products
            $availableCountries = [];
            $availableCategories = [];
            $minPriceFound = null;
            $maxPriceFound = null;
            $freeShippingCount = 0;
            $videoCount = 0;

            foreach ($products as $p) {
                // Country Code
                $country = $p['countryCode'] ?? $p['sourceFrom'] ?? $p['countryName'] ?? null;
                if ($country && !in_array(strtoupper($country), $availableCountries)) {
                    $availableCountries[] = strtoupper($country);
                }

                // Category
                $catId = $p['categoryId'] ?? null;
                $catName = $p['categoryName'] ?? $p['entryName'] ?? null;
                if ($catId || $catName) {
                    $key = $catId ?? $catName;
                    if (!isset($availableCategories[$key])) {
                        $availableCategories[$key] = [
                            'id' => $catId,
                            'name' => $catName ?? $catId,
                        ];
                    }
                }

                // Price
                $price = isset($p['sellPrice']) ? (float)$p['sellPrice'] : (isset($p['productPrice']) ? (float)$p['productPrice'] : null);
                if ($price !== null && $price > 0) {
                    if ($minPriceFound === null || $price < $minPriceFound) {
                        $minPriceFound = $price;
                    }
                    if ($maxPriceFound === null || $price > $maxPriceFound) {
                        $maxPriceFound = $price;
                    }
                }

                // Free Shipping
                if ((isset($p['addMarkStatus']) && $p['addMarkStatus'] == 1) || (!empty($p['isFreeShipping']))) {
                    $freeShippingCount++;
                }

                // Video
                if ((isset($p['productType']) && $p['productType'] == 10) || (!empty($p['isVideo'])) || !empty($p['productVideo'])) {
                    $videoCount++;
                }
            }

            return [
                'success' => true,
                'products' => $products,
                'total' => count($products),
                'page' => $page,
                'size' => $size,
                'facets' => [
                    'countries' => $availableCountries,
                    'categories' => array_values($availableCategories),
                    'min_price' => $minPriceFound !== null ? round($minPriceFound, 2) : 0,
                    'max_price' => $maxPriceFound !== null ? round($maxPriceFound, 2) : 0,
                    'free_shipping_count' => $freeShippingCount,
                    'video_count' => $videoCount,
                ],
            ];
        }

        return [
            'success' => false,
            'message' => $response->json()['message'] ?? 'Failed to fetch CJ products',
            'products' => [],
            'facets' => [
                'countries' => [],
                'categories' => [],
                'min_price' => 0,
                'max_price' => 0,
                'free_shipping_count' => 0,
                'video_count' => 0,
            ],
        ];
    }

    /**
     * Fetch full product details including variants from CJ.
     */
    public function getProductDetail(string $cjProductId, int $maxRetries = 3): ?array
    {
        $token = $this->getAccessToken();
        $attempt = 0;

        while ($attempt < $maxRetries) {
            $attempt++;

            $response = Http::withHeaders([
                'CJ-Access-Token' => $token,
            ])->get("{$this->getBaseUrl()}/product/query", [
                'pid' => $cjProductId,
            ]);

            $json = $response->json() ?? [];
            $code = $json['code'] ?? 0;

            if ($response->successful() && $code === 200) {
                return $json['data'] ?? null;
            }

            // Check if rate limited (1600200 or Too Many Requests)
            $message = $json['message'] ?? '';
            if ($code === 1600200 || str_contains(strtolower($message), 'too many requests') || str_contains(strtolower($message), 'qps limit')) {
                if ($attempt < $maxRetries) {
                    usleep(1500000); // 1.5s delay before retry
                    continue;
                }
            }

            Log::error('CJ Product Detail Error:', ['pid' => $cjProductId, 'response' => $json]);
            break;
        }

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
