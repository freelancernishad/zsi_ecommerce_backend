<?php

namespace Amazon\Services;

use App\Models\SiteSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;
use DOMDocument;
use DOMXPath;

class AmazonScraperService
{
    protected string $brightdataApiKey;
    protected string $brightdataZone;
    protected string $provider;

    public function __construct()
    {
        $this->loadSettings();
    }

    /**
     * Load settings dynamically from SiteSetting or config
     */
    protected function loadSettings(): void
    {
        $setting = SiteSetting::where('group', 'amazon')->pluck('value', 'key')->toArray();

        $this->brightdataApiKey = $setting['amazon_brightdata_api_key'] ?? config('amazon.brightdata_api_key', '');
        $this->brightdataZone = $setting['amazon_brightdata_zone'] ?? config('amazon.brightdata_zone', '');
        $this->provider = $setting['amazon_scraper_provider'] ?? config('amazon.scraper_provider', 'brightdata');
    }

    /**
     * Search live Amazon products by keyword and filters.
     */
    public function searchProducts(
        string $keyword,
        int $page = 1,
        int $size = 20,
        ?string $categoryId = null,
        ?string $countryCode = null,
        ?float $minPrice = null,
        ?float $maxPrice = null,
        ?int $freeShipping = null,
        ?int $videoOnly = null
    ): array {
        $queryParams = [
            'k' => $keyword,
            'page' => $page,
        ];

        if ($minPrice !== null && $minPrice > 0) {
            $queryParams['rh'] = "p_36:" . (int)($minPrice * 100) . "-";
        }
        if ($maxPrice !== null && $maxPrice > 0) {
            $existingRh = $queryParams['rh'] ?? 'p_36:';
            if (str_contains($existingRh, '-')) {
                $queryParams['rh'] = rtrim($existingRh, '-') . (int)($maxPrice * 100);
            } else {
                $queryParams['rh'] = "p_36:-" . (int)($maxPrice * 100);
            }
        }

        $domain = "www.amazon.com";
        if ($countryCode) {
            $countryDomainMap = [
                'UK' => 'www.amazon.co.uk',
                'GB' => 'www.amazon.co.uk',
                'DE' => 'www.amazon.de',
                'FR' => 'www.amazon.fr',
                'CA' => 'www.amazon.ca',
                'JP' => 'www.amazon.co.jp',
                'IT' => 'www.amazon.it',
                'ES' => 'www.amazon.es',
                'AU' => 'www.amazon.com.au',
                'IN' => 'www.amazon.in',
                'BR' => 'www.amazon.com.br',
                'MX' => 'www.amazon.com.mx',
            ];
            $domain = $countryDomainMap[strtoupper($countryCode)] ?? 'www.amazon.com';
        }

        $url = "https://{$domain}/s?" . http_build_query($queryParams);

        $html = '';
        if ($this->brightdataApiKey && $this->brightdataZone) {
            try {
                $html = $this->scrapeWithBrightData($url);
            } catch (Exception $e) {
                Log::warning("BrightData keyword search failed: " . $e->getMessage());
            }
        }

        if (empty($html)) {
            $html = $this->scrapeWithHttp($url);
        }

        if (empty($html)) {
            return [
                'products' => [],
                'total' => 0,
                'page' => $page,
            ];
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        $xpath = new DOMXPath($dom);
        libxml_clear_errors();

        $items = [];
        $resultNodes = $xpath->query('//div[@data-component-type="s-search-result"]');

        foreach ($resultNodes as $node) {
            $asin = $node->getAttribute('data-asin');
            if (empty($asin)) continue;

            // Title
            $titleNodes = $xpath->query('.//h2//span', $node);
            $title = $titleNodes->length > 0 ? trim($titleNodes->item(0)->nodeValue) : "Amazon Product {$asin}";

            // Price
            $price = 29.99;
            $priceNodes = $xpath->query('.//span[contains(@class, "a-price")]/span[contains(@class, "a-offscreen")]', $node);
            if ($priceNodes->length > 0) {
                $rawPrice = $priceNodes->item(0)->nodeValue;
                if (preg_match('/[\d,]+\.\d{2}/', $rawPrice, $m)) {
                    $price = (float) str_replace(',', '', $m[0]);
                }
            }

            // Image
            $imgNodes = $xpath->query('.//img[contains(@class, "s-image")]/@src', $node);
            $image = $imgNodes->length > 0 ? trim($imgNodes->item(0)->nodeValue) : '';

            // Optional filter checks
            if ($minPrice !== null && $price < $minPrice) continue;
            if ($maxPrice !== null && $price > $maxPrice) continue;

            $items[] = [
                'id' => $asin,
                'pid' => $asin,
                'asin' => $asin,
                'title' => $title,
                'productName' => $title,
                'productNameEn' => $title,
                'price' => $price,
                'sellPrice' => $price,
                'productPrice' => $price,
                'image' => $image,
                'productImage' => $image,
                'bigImage' => $image,
                'sku' => "AMZ-{$asin}",
                'url' => "https://{$domain}/dp/{$asin}",
                'countryCode' => $countryCode ?: 'US',
            ];
        }

        return [
            'products' => array_slice($items, 0, $size),
            'total' => count($items),
            'page' => $page,
        ];
    }
    public function extractAsin(string $input): string
    {
        $input = trim($input);
        if (preg_match('/(B0[0-9A-Z]{8})/i', $input, $matches)) {
            return strtoupper($matches[1]);
        }
        if (preg_match('/^[A-Z0-9]{10}$/i', $input)) {
            return strtoupper($input);
        }
        return $input;
    }

    /**
     * Scrape and parse Amazon product details by ASIN or URL.
     */
    public function getProductDetail(string $asinOrUrl): array
    {
        $asin = $this->extractAsin($asinOrUrl);
        $url = str_starts_with($asinOrUrl, 'http')
            ? $asinOrUrl
            : "https://www.amazon.com/dp/{$asin}";

        $html = '';

        // 1. Try Bright Data Web Unlocker if configured
        if ($this->brightdataApiKey && $this->brightdataZone) {
            try {
                $html = $this->scrapeWithBrightData($url);
            } catch (Exception $e) {
                Log::warning("BrightData scrape failed for ASIN {$asin}: " . $e->getMessage());
            }
        }

        // 2. Fallback to HTTP direct request with desktop headers if HTML is empty
        if (empty($html)) {
            $html = $this->scrapeWithHttp($url);
        }

        if (empty($html)) {
            throw new Exception("Unable to scrape Amazon product page for ASIN: {$asin}. The connection may be blocked or URL is invalid.");
        }

        // 3. Parse HTML content into structured Product Array
        $parsed = $this->parseAmazonHtml($html, $url, $asin);

        if (empty($parsed['title'])) {
            throw new Exception("Could not extract product title for ASIN: {$asin}. Amazon may have presented a CAPTCHA page.");
        }

        return $parsed;
    }

    /**
     * Scrape page HTML using Bright Data Web Unlocker API
     */
    protected function scrapeWithBrightData(string $url): string
    {
        $endpoint = "https://api.brightdata.com/zone/route";
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->brightdataApiKey,
            'Content-Type' => 'application/json',
        ])->timeout(45)->post($endpoint, [
            'zone' => $this->brightdataZone,
            'url' => $url,
            'format' => 'raw',
        ]);

        if ($response->successful()) {
            return $response->body();
        }

        throw new Exception("BrightData API returned status code " . $response->status());
    }

    /**
     * Scrape page HTML using HTTP request with realistic desktop headers
     */
    protected function scrapeWithHttp(string $url): string
    {
        $userAgents = [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36',
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        ];

        $randomUserAgent = $userAgents[array_rand($userAgents)];

        $response = Http::withHeaders([
            'User-Agent' => $randomUserAgent,
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language' => 'en-US,en;q=0.9',
            'Cache-Control' => 'no-cache',
            'Pragma' => 'no-cache',
            'Sec-Ch-Ua' => '"Chromium";v="122", "Not(A:Brand";v="24", "Google Chrome";v="122"',
            'Sec-Ch-Ua-Mobile' => '?0',
            'Sec-Ch-Ua-Platform' => '"Windows"',
            'Sec-Fetch-Dest' => 'document',
            'Sec-Fetch-Mode' => 'navigate',
            'Sec-Fetch-Site' => 'none',
            'Sec-Fetch-User' => '?1',
            'Upgrade-Insecure-Requests' => '1',
        ])->timeout(30)->get($url);

        if ($response->successful()) {
            return $response->body();
        }

        return '';
    }

    /**
     * Parse raw Amazon HTML string into structured Product Data Array
     */
    protected function parseAmazonHtml(string $html, string $url, string $asin): array
    {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        $xpath = new DOMXPath($dom);
        libxml_clear_errors();

        // 1. Title Extraction
        $title = '';
        $titleNodes = $xpath->query('//span[@id="productTitle"] | //h1[@id="title"] | //meta[@property="og:title"]/@content');
        if ($titleNodes->length > 0) {
            $title = trim($titleNodes->item(0)->nodeValue);
        }

        // 2. Price Extraction
        $price = 0.0;
        $priceNodes = $xpath->query('//span[contains(@class, "a-price")]/span[contains(@class, "a-offscreen")] | //span[@id="priceblock_ourprice"] | //span[@id="priceblock_dealprice"] | //span[contains(@class, "priceToPay")]//span[contains(@class, "a-offscreen")]');
        if ($priceNodes->length > 0) {
            $rawPrice = $priceNodes->item(0)->nodeValue;
            if (preg_match('/[\d,]+\.\d{2}/', $rawPrice, $m)) {
                $price = (float) str_replace(',', '', $m[0]);
            }
        }

        // 3. Images Extraction
        $images = [];
        // Primary main image
        $mainImgNodes = $xpath->query('//img[@id="landingImage"]/@data-old-hires | //img[@id="landingImage"]/@src | //img[@id="imgBlkFront"]/@src');
        if ($mainImgNodes->length > 0) {
            $mainSrc = trim($mainImgNodes->item(0)->nodeValue);
            if (!empty($mainSrc) && str_starts_with($mainSrc, 'http')) {
                $images[] = $mainSrc;
            }
        }

        // Additional gallery images via JavaScript regex parsing
        if (preg_match('/\'colorImages\':\s*\{\s*\'initial\':\s*(\[.*?\])\s*\}/s', $html, $imgMatches)) {
            $imgJson = json_decode($imgMatches[1], true);
            if (is_array($imgJson)) {
                foreach ($imgJson as $item) {
                    if (!empty($item['hiRes']) && is_string($item['hiRes'])) {
                        $images[] = $item['hiRes'];
                    } elseif (!empty($item['large']) && is_string($item['large'])) {
                        $images[] = $item['large'];
                    }
                }
            }
        }

        // Alt images fallback via XPath
        if (count($images) < 2) {
            $altNodes = $xpath->query('//div[@id="altImages"]//li//img/@src');
            foreach ($altNodes as $altNode) {
                $src = trim($altNode->nodeValue);
                if ($src && !str_contains($src, 'sprite') && !str_contains($src, 'play-icon')) {
                    $hiresSrc = preg_replace('/._[A-Z0-9,_]+_./i', '.', $src);
                    if (!in_array($hiresSrc, $images)) {
                        $images[] = $hiresSrc;
                    }
                }
            }
        }

        $images = array_values(array_unique(array_filter($images)));

        // 4. Description & Feature Bullets
        $features = [];
        $bulletNodes = $xpath->query('//div[@id="feature-bullets"]//ul/li/span[contains(@class, "a-list-item")]');
        foreach ($bulletNodes as $bNode) {
            $feat = trim($bNode->nodeValue);
            if (!empty($feat) && strlen($feat) > 3) {
                $features[] = $feat;
            }
        }

        $descriptionHtml = '';
        $descNodes = $xpath->query('//div[@id="productDescription"] | //div[@id="featurebullets_feature_div"]');
        if ($descNodes->length > 0) {
            $descriptionHtml = trim($dom->saveHTML($descNodes->item(0)));
        } elseif (!empty($features)) {
            $descriptionHtml = '<ul>' . implode('', array_map(fn($f) => '<li>' . htmlspecialchars($f) . '</li>', $features)) . '</ul>';
        }

        // 5. Brand / Vendor Extraction
        $brand = 'Generic';
        $brandNodes = $xpath->query('//a[@id="bylineInfo"] | //div[contains(@class, "po-brand")]//span[contains(@class, "a-span9")]');
        if ($brandNodes->length > 0) {
            $rawBrand = trim($brandNodes->item(0)->nodeValue);
            $brand = preg_replace('/^(Brand:|Visit the|Store)/i', '', $rawBrand);
            $brand = trim($brand) ?: 'Generic';
        }

        // 6. Variants Extraction (Color/Size options if present)
        $variants = [];

        $twisterNodes = $xpath->query('//div[@id="twister"]//li | //div[@id="variation_color_name"]//li | //div[@id="variation_size_name"]//li');
        if ($twisterNodes->length > 0) {
            foreach ($twisterNodes as $idx => $tNode) {
                $varTitle = trim($tNode->nodeValue);
                if (empty($varTitle)) continue;

                $varAsin = $tNode->getAttribute('data-defaultasins') ?: $asin;

                $variants[] = [
                    'id' => $varAsin ?: "var_{$idx}",
                    'asin' => $varAsin ?: $asin,
                    'title' => preg_replace('/\s+/', ' ', $varTitle),
                    'price' => $price,
                    'sku' => "AMZ-{$asin}-" . ($idx + 1),
                    'image' => !empty($images) ? $images[0] : '',
                    'attributes' => [
                        'Option' => preg_replace('/\s+/', ' ', $varTitle),
                    ],
                    'available' => true,
                ];
            }
        }

        if (empty($variants)) {
            $variants[] = [
                'id' => $asin,
                'asin' => $asin,
                'title' => 'Default Title',
                'price' => $price,
                'sku' => "AMZ-{$asin}",
                'image' => !empty($images) ? $images[0] : '',
                'attributes' => [],
                'available' => true,
            ];
        }

        return [
            'pid' => $asin,
            'asin' => $asin,
            'url' => $url,
            'title' => $title,
            'description' => $descriptionHtml,
            'brand' => $brand,
            'vendor' => $brand,
            'price' => $price,
            'images' => $images,
            'features' => $features,
            'sku' => "AMZ-{$asin}",
            'variants' => $variants,
        ];
    }
}
