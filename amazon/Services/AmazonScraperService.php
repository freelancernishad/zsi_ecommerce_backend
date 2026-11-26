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
    protected int $defaultStock = 20;

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
        $this->defaultStock = !empty($setting['amazon_default_stock'])
            ? max(1, (int) $setting['amazon_default_stock'])
            : 20;
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
        $this->loadSettings();

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

        $items = [];

        if (!empty($html)) {
            libxml_use_internal_errors(true);
            $dom = new DOMDocument();
            @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
            $xpath = new DOMXPath($dom);
            libxml_clear_errors();

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
        }

        if (empty($html)) {
            throw new Exception("Unable to search Amazon for '{$keyword}'. The connection may be temporarily restricted by Amazon.", 429);
        }

        return [
            'products' => array_slice($items, 0, $size),
            'total' => count($items),
            'page' => $page,
        ];
    }

    /**
     * Resolve Amazon short share links (a.co, amzn.to, amzn.eu, amzn.asia) to their destination URL.
     */
    protected function resolveShortUrl(string $input): string
    {
        $trimmed = trim($input);
        if (!preg_match('/^https?:\/\/(?:www\.)?(a\.co|amzn\.to|amzn\.eu|amzn\.asia)\//i', $trimmed)) {
            return $trimmed;
        }

        try {
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                'Accept-Language' => 'en-US,en;q=0.9',
            ])->withOptions(['allow_redirects' => ['track_redirects' => true]])->timeout(12)->get($trimmed);

            $effective = (string) ($response->effectiveUri() ?: '');
            if ($effective !== '' && str_contains(strtolower($effective), 'amazon.')) {
                return $effective;
            }
        } catch (Exception $e) {
            Log::warning("Failed resolving short Amazon URL {$trimmed}: " . $e->getMessage());
        }

        return $trimmed;
    }

    public function extractAsin(string $input): string
    {
        $input = trim($input);

        // 1. Canonical Amazon URL product paths (supports Books ISBN-10 and all 10-char ASINs)
        if (preg_match('/(?:\/dp\/|\/gp\/product\/|\/gp\/aw\/d\/|\/o\/ASIN\/|\/product\/)([A-Z0-9]{10})(?:[\/?&#]|$)/i', $input, $matches)) {
            return strtoupper($matches[1]);
        }

        // 2. Standard B0... ASIN anywhere in URL or string
        if (preg_match('/(B0[0-9A-Z]{8})/i', $input, $matches)) {
            return strtoupper($matches[1]);
        }

        // 3. Raw 10-character ASIN / ISBN-10
        if (preg_match('/^[A-Z0-9]{10}$/i', $input)) {
            return strtoupper($input);
        }

        return '';
    }

    /**
     * Scrape and parse Amazon product details by ASIN or URL.
     */
    public function getProductDetail(string $asinOrUrl): array
    {
        $this->loadSettings();
        $asinOrUrl = $this->resolveShortUrl($asinOrUrl);
        $asin = $this->extractAsin($asinOrUrl);

        if ($asin === '' || !preg_match('/^[A-Z0-9]{10}$/i', $asin)) {
            throw new Exception("Invalid Amazon ASIN or URL: {$asinOrUrl}. Could not extract a 10-character ASIN/ISBN.", 422);
        }

        $host = 'www.amazon.com';
        if (str_starts_with($asinOrUrl, 'http')) {
            $parsedHost = parse_url($asinOrUrl, PHP_URL_HOST);
            if ($parsedHost && str_contains($parsedHost, 'amazon.')) {
                $host = $parsedHost;
            }
        }
        $url = "https://{$host}/dp/{$asin}?th=1&psc=1";
        if ($host === 'www.amazon.com') {
            $url .= "&language=en_US&currency=USD";
        }

        $html = '';

        // 1. Try Bright Data Web Unlocker if configured and active
        // If BrightData lands on an AmazonFresh/local-grocery metro IP without a nationwide shipping offer,
        // retry up to 3 times to reach a standard US IP before falling back to local-grocery pricing.
        if ($this->brightdataApiKey && $this->brightdataZone) {
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                try {
                    $candidateHtml = $this->scrapeWithBrightData($url);
                } catch (Exception $e) {
                    $candidateHtml = '';
                }

                if (empty($candidateHtml)) {
                    break;
                }

                $html = $candidateHtml;
                if (!$this->isLocalGroceryOnlyHtml($candidateHtml)) {
                    break;
                }

                usleep(300000);
            }
        }

        // 2. Fallback to HTTP direct request with desktop headers if HTML is empty
        if (empty($html)) {
            $html = $this->scrapeWithHttp($url);
        }

        // 3. Check for 404 / Not Found
        if ($html === '404' || str_contains($html, 'Page Not Found') || str_contains($html, 'Looking for something?')) {
            throw new Exception("Amazon product page not found for ASIN: {$asin}. The item may be discontinued, out of stock, or the ASIN is invalid.", 404);
        }

        if (empty($html)) {
            throw new Exception("Unable to scrape Amazon product page for ASIN: {$asin}. The connection may be temporarily restricted by Amazon.", 429);
        }

        // 4. Parse HTML content into structured Product Array
        $allowLocalGrocery = $this->isLocalGroceryOnlyHtml($html);
        $parsed = $this->parseAmazonHtml($html, $url, $asin, $allowLocalGrocery);

        if (empty($parsed['title'])) {
            throw new Exception("Could not extract product specifications for ASIN: {$asin}. Amazon may have presented a CAPTCHA checkpoint.", 422);
        }

        return $parsed;
    }

    protected function resolveBrightDataCountry(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        return match (true) {
            str_contains($host, 'amazon.co.uk') => 'gb',
            str_contains($host, 'amazon.ca') => 'ca',
            str_contains($host, 'amazon.de') => 'de',
            str_contains($host, 'amazon.fr') => 'fr',
            str_contains($host, 'amazon.it') => 'it',
            str_contains($host, 'amazon.es') => 'es',
            str_contains($host, 'amazon.co.jp') => 'jp',
            str_contains($host, 'amazon.com.au') => 'au',
            str_contains($host, 'amazon.in') => 'in',
            str_contains($host, 'amazon.com.br') => 'br',
            str_contains($host, 'amazon.com.mx') => 'mx',
            default => 'us',
        };
    }

    /**
     * Scrape page HTML using Bright Data Web Unlocker API
     */
    protected function scrapeWithBrightData(string $url): string
    {
        if (empty($this->brightdataApiKey) || empty($this->brightdataZone)) {
            return '';
        }

        $country = $this->resolveBrightDataCountry($url);

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->brightdataApiKey,
                'Content-Type' => 'application/json',
            ])->timeout(45)->post("https://api.brightdata.com/request", [
                'zone' => $this->brightdataZone,
                'url' => $url,
                'country' => $country,
                'format' => 'raw',
            ]);

            if ($response->successful() && !empty($response->body())) {
                return $response->body();
            }

            // If raw returned 200 with empty body, retry once with format=json to inspect/extract envelope
            if ($response->successful() && empty($response->body())) {
                $jsonRes = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $this->brightdataApiKey,
                    'Content-Type' => 'application/json',
                ])->timeout(45)->post("https://api.brightdata.com/request", [
                    'zone' => $this->brightdataZone,
                    'url' => $url,
                    'country' => $country,
                    'format' => 'json',
                ]);

                $jsonData = $jsonRes->json();
                if ($jsonRes->successful() && !empty($jsonData['body'])) {
                    return (string) $jsonData['body'];
                }

                Log::warning("BrightData empty body ({$response->status()}) for {$url}", [
                    'headers' => $response->headers(),
                    'json_fallback_status' => $jsonRes->status(),
                    'json_fallback_keys' => is_array($jsonData) ? array_keys($jsonData) : substr($jsonRes->body(), 0, 300),
                ]);
                return '';
            }

            Log::warning("BrightData request failed ({$response->status()}) for {$url}: " . substr($response->body(), 0, 300));
        } catch (Exception $e) {
            Log::warning("BrightData exception for {$url}: " . $e->getMessage());
        }

        return '';
    }

    /**
     * Scrape page HTML using HTTP request with realistic desktop headers and anti-bot retry
     */
    protected function scrapeWithHttp(string $url): string
    {
        $userAgents = [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:124.0) Gecko/20100101 Firefox/124.0',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_4_1) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4.1 Safari/605.1.15',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36 Edg/124.0.0.0',
        ];

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $randomUserAgent = $userAgents[array_rand($userAgents)];

            try {
                $response = Http::withHeaders([
                    'User-Agent' => $randomUserAgent,
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.9',
                    'Accept-Encoding' => 'gzip, deflate, br',
                    'Cache-Control' => 'no-cache',
                    'Pragma' => 'no-cache',
                    'Dnt' => '1',
                    'Sec-Ch-Ua' => '"Chromium";v="124", "Google Chrome";v="124"',
                    'Sec-Ch-Ua-Mobile' => '?0',
                    'Sec-Ch-Ua-Platform' => '"Windows"',
                    'Sec-Fetch-Dest' => 'document',
                    'Sec-Fetch-Mode' => 'navigate',
                    'Sec-Fetch-Site' => 'none',
                    'Sec-Fetch-User' => '?1',
                    'Upgrade-Insecure-Requests' => '1',
                ])->timeout(20)->get($url);

                if ($response->status() === 404) {
                    return '404';
                }

                $body = $response->body();
                if ($response->successful() && !empty($body)) {
                    if (str_contains($body, 'validateCaptcha') || str_contains($body, 'Robot Check')) {
                        usleep(1000000);
                        continue;
                    }
                    return $body;
                }
            } catch (Exception $e) {
                Log::warning("Direct Amazon scrape attempt {$attempt} error: " . $e->getMessage());
            }

            usleep(800000);
        }

        return '';
    }

    /**
     * Parse raw Amazon HTML string into structured Product Data Array
     */
    protected function parseAmazonHtml(string $html, string $url, string $asin, bool $allowLocalGrocery = false): array
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

        // 2. Price Extraction (strictly scoped to main buybox / corePrice / OLP, never Sponsored banners)
        $price = $this->extractMainProductPrice($xpath, $html, $asin, $allowLocalGrocery);

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
        if (preg_match('/[\'"]colorImages[\'"]\s*:\s*\{\s*[\'"]initial[\'"]\s*:\s*(?:[A-Za-z0-9_$.]+\.parseJSON\()?[\'"]?(\[\s*\{.*?\}(?:\s*,\s*\{.*?\}\s*)*\])[\'"]?\)?/s', $html, $imgMatches)) {
            $cleanJson = stripslashes($imgMatches[1]);
            $imgJson = json_decode($cleanJson, true);
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

        // Additional fallback: regex matching high-res image URLs in page scripts
        if (count($images) < 2) {
            if (preg_match_all('/"(?:hiRes|large)"\s*:\s*"([^"]+)"/', $html, $mAll)) {
                foreach ($mAll[1] as $imgUrl) {
                    $imgUrl = stripslashes($imgUrl);
                    if (str_starts_with($imgUrl, 'http') && !str_contains($imgUrl, 'sprite') && !str_contains($imgUrl, 'play-icon')) {
                        $images[] = $imgUrl;
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

        // 4. Description, Feature Bullets, Fashion Facts, A+ Content & Specifications
        $features = [];
        $seenFeatures = [];
        $bulletNodes = $xpath->query(
            '//div[@id="feature-bullets"]//ul/li/span[contains(@class, "a-list-item")]'
            . ' | //div[@id="productFactsDesktop_feature_div"]//ul/li/span[contains(@class, "a-list-item")]'
        );
        foreach ($bulletNodes as $bNode) {
            $feat = trim(preg_replace('/\s+/', ' ', (string) $bNode->nodeValue));
            if ($feat !== '' && strlen($feat) > 3 && !isset($seenFeatures[$feat])) {
                $features[] = $feat;
                $seenFeatures[$feat] = true;
            }
        }

        $descriptionParts = [];
        $descNodeList = $xpath->query('//div[@id="productDescription"]');
        if ($descNodeList->length > 0) {
            $pdHtml = trim($dom->saveHTML($descNodeList->item(0)));
            if (trim(strip_tags($pdHtml)) !== '') {
                $descriptionParts[] = $pdHtml;
            }
        }

        if (!empty($features)) {
            $descriptionParts[] = '<ul>' . implode('', array_map(fn($f) => '<li>' . htmlspecialchars($f) . '</li>', $features)) . '</ul>';
        } elseif (($fbNodes = $xpath->query('//div[@id="featurebullets_feature_div"]')) && $fbNodes->length > 0) {
            $fbHtml = trim($dom->saveHTML($fbNodes->item(0)));
            if (trim(strip_tags($fbHtml)) !== '') {
                $descriptionParts[] = $fbHtml;
            }
        }

        $combinedTextLen = strlen(trim(strip_tags(implode("\n", $descriptionParts))));
        if ($combinedTextLen < 60) {
            $aplusText = $this->extractVisibleTextFromNodes(
                $xpath,
                '//div[@id="aplus" or @id="aplus_feature_div" or @id="aplus3p_feature_div"]//p | //div[@id="aplus" or @id="aplus_feature_div" or @id="aplus3p_feature_div"]//h3 | //div[@id="aplus" or @id="aplus_feature_div" or @id="aplus3p_feature_div"]//h4'
            );
            if (strlen($aplusText) >= 25) {
                $descriptionParts[] = '<div class="amazon-aplus-description"><p>' . nl2br(htmlspecialchars($aplusText)) . '</p></div>';
            }
        }

        $descriptionHtml = implode("\n", $descriptionParts);

        // 4b. Technical Specifications / Product Details Table / Fashion Facts
        $specifications = $this->parseSpecifications($xpath);
        if (!empty($specifications)) {
            $specRows = '';
            foreach ($specifications as $specKey => $specVal) {
                $specRows .= '<tr><th style="text-align:left;padding:6px 10px;border:1px solid #e5e7eb;background:#f9fafb;">'
                    . htmlspecialchars($specKey)
                    . '</th><td style="padding:6px 10px;border:1px solid #e5e7eb;">'
                    . htmlspecialchars($specVal)
                    . '</td></tr>';
            }
            $descriptionHtml .= '<div class="amazon-specifications" style="margin-top:16px;"><h3>Product Specifications</h3><table style="width:100%;border-collapse:collapse;">' . $specRows . '</table></div>';
        }

        // 5. Brand / Vendor Extraction (clean "Visit the ... Store" and "Brand: ...")
        $brand = '';
        $brandNodes = $xpath->query('//a[@id="bylineInfo"] | //div[contains(@class, "po-brand")]//span[contains(@class, "a-span9")]');
        if ($brandNodes->length > 0) {
            $rawBrand = trim((string) $brandNodes->item(0)->nodeValue);
            $brand = preg_replace('/^(?:Brand:|Visit the)\s*/i', '', $rawBrand);
            $brand = preg_replace('/\s+Store$/i', '', (string) $brand);
            $brand = trim((string) $brand);
        }
        if ($brand === '' || strcasecmp($brand, 'Generic') === 0) {
            $specBrand = $specifications['Brand'] ?? $specifications['Manufacturer'] ?? $specifications['Author'] ?? '';
            $brand = trim((string) $specBrand) ?: 'Generic';
        }

        // 5b. Rating, Reviews, Weight/Dimensions & Digital Detection
        $ratingStats = $this->parseRatingAndReviews($xpath);
        $physicalDims = $this->extractPhysicalDimensions($specifications);
        $isPhysical = $this->detectIsPhysical($xpath, $title, $specifications);

        // 6. Availability & Stock Extraction
        $availability = $this->parseAvailability($xpath, $price);

        // 7. Comprehensive Variants Extraction
        $variants = $this->parseVariants($html, $asin, $price, $images, $xpath, $availability, $allowLocalGrocery);

        // 7b. Enrich per-variant prices when Amazon omits them from initial HTML (e.g. slotsFetched: false)
        $host = parse_url($url, PHP_URL_HOST) ?: 'www.amazon.com';
        $variants = $this->enrichVariantPrices($variants, $asin, $price, $host);

        // 7c. Backfill or reconcile parent price / availability from enriched variants
        if (!empty($variants)) {
            $matchedVariant = null;
            foreach ($variants as $v) {
                if (($v['asin'] ?? '') === $asin && (float) ($v['price'] ?? 0) > 0) {
                    $matchedVariant = $v;
                    break;
                }
            }

            if ($price <= 0) {
                if ($matchedVariant) {
                    $price = (float) $matchedVariant['price'];
                } else {
                    foreach ($variants as $v) {
                        if ((float) ($v['price'] ?? 0) > 0) {
                            $price = (float) $v['price'];
                            break;
                        }
                    }
                }
            } elseif ($matchedVariant && abs((float) $matchedVariant['price'] - $price) > 0.001) {
                $price = (float) $matchedVariant['price'];
            }
        }

        if ($price > 0 && !empty($variants)) {
            foreach ($variants as &$v) {
                if ((float) ($v['price'] ?? 0) <= 0) {
                    $v['price'] = $price;
                    $v['cost_price'] = $price;
                }
                if (($v['asin'] ?? '') === $asin && !$availability['available'] && !empty($v['available']) && (float) ($v['price'] ?? 0) > 0) {
                    $availability = [
                        'available' => true,
                        'stock' => (int) ($v['stock'] ?? $this->defaultStock) ?: $this->defaultStock,
                    ];
                }
            }
            unset($v);
        }

        // Include any variant swatch images into the main image gallery if missing
        foreach ($variants as $v) {
            if (!empty($v['image']) && !in_array($v['image'], $images)) {
                $images[] = $v['image'];
            }
        }
        $images = array_values(array_unique(array_filter($images)));

        // Extract List / MRP price strictly higher than current selling price
        $listPrice = $this->extractListPrice($xpath, $price);

        return [
            'pid' => $asin,
            'asin' => $asin,
            'url' => $url,
            'title' => $title,
            'description' => $descriptionHtml,
            'brand' => $brand,
            'vendor' => $brand,
            'price' => $price,
            'list_price' => $listPrice,
            'cost_price' => $price,
            'rating' => $ratingStats['rating'],
            'review_count' => $ratingStats['review_count'],
            'weight' => $physicalDims['weight'],
            'weight_unit' => $physicalDims['weight_unit'],
            'width' => $physicalDims['width'],
            'width_unit' => $physicalDims['width_unit'],
            'height' => $physicalDims['height'],
            'height_unit' => $physicalDims['height_unit'],
            'is_physical' => $isPhysical,
            'stock' => $availability['stock'],
            'available' => $availability['available'],
            'images' => $images,
            'features' => $features,
            'specifications' => $specifications,
            'sku' => "AMZ-{$asin}",
            'variants' => $variants,
        ];
    }

    /**
     * Extract visible text from DOM nodes while ignoring inline <script> and <style> elements.
     */
    protected function extractVisibleTextFromNodes(DOMXPath $xpath, string $query): string
    {
        $nodes = $xpath->query($query);
        $parts = [];
        foreach ($nodes as $node) {
            $textNodes = $xpath->query('.//text()[not(ancestor::script) and not(ancestor::style)]', $node);
            $buf = '';
            foreach ($textNodes as $tn) {
                $buf .= ' ' . $tn->nodeValue;
            }
            $clean = trim(preg_replace('/\s+/', ' ', $buf));
            if ($clean !== '') {
                $parts[] = $clean;
            }
        }
        return implode(' ', $parts);
    }

    /**
     * Detect whether an Amazon HTML page is showing only a local-grocery (AmazonFresh / Whole Foods / ALM)
     * offer without a standard Add-to-Cart button or nationwide OLP price.
     */
    protected function isLocalGroceryOnlyHtml(string $html, ?DOMXPath $xpath = null): bool
    {
        if (empty($html)) {
            return false;
        }

        if ($xpath === null) {
            libxml_use_internal_errors(true);
            $dom = new DOMDocument();
            @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
            $xpath = new DOMXPath($dom);
            libxml_clear_errors();
        }

        $hasBuyButton = $xpath->query('//input[@id="add-to-cart-button"] | //input[@id="buy-now-button"]')->length > 0;
        if ($hasBuyButton) {
            return false;
        }

        if (preg_match('/"olpMessage"\s*:\s*"[^"]*\$\d/', $html)) {
            return false;
        }

        $buyboxText = strtolower($this->extractVisibleTextFromNodes($xpath, '//div[@id="buybox"] | //div[@id="rightCol"]'));

        return str_contains($buyboxText, 'amazonfresh')
            || str_contains($buyboxText, 'amazon fresh')
            || str_contains($buyboxText, 'whole foods')
            || str_contains($buyboxText, 'free grocery delivery')
            || str_contains($html, '"buyingOptionType":"ALM"');
    }

    /**
     * Parse localized Amazon prices (US "$19.99", EU "19,99 €" / "1.299,99 €", UK "£45.50", JP "¥2,980", ranges "$14.99 - $29.99").
     */
    protected function parseLocalizedPrice(string $raw): float
    {
        $clean = trim(str_replace(["\xc2\xa0", "\u{00A0}"], ' ', $raw));
        if ($clean === '') {
            return 0.0;
        }

        // If a range like "$14.99 - $29.99" is given, evaluate the leading price token
        if (preg_match('/^(.+?)\s*[-–—]\s*(?:[$£€¥￥₹₩]|USD|EUR|GBP)/u', $clean, $rm)) {
            $clean = trim($rm[1]);
        }

        $isZeroDecimalCurrency = (bool) preg_match('/[¥￥₩]/u', $clean);

        if (!preg_match('/\d+(?:[.,]\d+)*/', $clean, $m)) {
            return 0.0;
        }

        $num = $m[0];

        if (str_contains($num, ',') && str_contains($num, '.')) {
            $lastComma = strrpos($num, ',');
            $lastDot = strrpos($num, '.');
            if ($lastComma > $lastDot) {
                // European format: 1.299,99
                $normalized = str_replace('.', '', $num);
                $normalized = str_replace(',', '.', $normalized);
            } else {
                // US/UK format: 1,299.99
                $normalized = str_replace(',', '', $num);
            }
        } elseif (str_contains($num, ',')) {
            if (preg_match('/,\d{2}$/', $num) && !$isZeroDecimalCurrency) {
                // European decimal comma: 19,99
                $normalized = str_replace(',', '.', $num);
            } else {
                // Thousands separator: 2,980
                $normalized = str_replace(',', '', $num);
            }
        } else {
            $normalized = $num;
        }

        $val = (float) $normalized;
        return $val > 0 ? round($val, 2) : 0.0;
    }

    /**
     * Extract list/original strike-through price if strictly higher than current selling price.
     */
    protected function extractListPrice(DOMXPath $xpath, float $sellingPrice): float
    {
        if ($sellingPrice <= 0) {
            return 0.0;
        }

        $nodes = $xpath->query(
            '//div[@id="corePriceDisplay_desktop_feature_div"]//span[contains(@class, "basisPrice")]//span[contains(@class, "a-offscreen")]'
            . ' | //div[@id="corePrice_feature_div"]//span[contains(@class, "basisPrice")]//span[contains(@class, "a-offscreen")]'
            . ' | //div[@id="corePriceDisplay_desktop_feature_div"]//span[contains(@class, "a-text-price")]//span[contains(@class, "a-offscreen")]'
            . ' | //span[@id="listPrice"]'
            . ' | //span[@id="priceblock_listprice"]'
        );

        foreach ($nodes as $node) {
            $val = $this->parseLocalizedPrice((string) $node->textContent);
            if ($val > $sellingPrice + 0.01 && $val < ($sellingPrice * 5)) {
                return $val;
            }
        }

        return 0.0;
    }

    /**
     * Extract main product price strictly from One-Time Buy Box / CorePrice / OLP / selected Twister,
     * never from Sponsored ads or Similar Items carousels, and skip local Amazon Fresh pickup-only prices.
     */
    protected function extractMainProductPrice(DOMXPath $xpath, string $html, string $asin = '', bool $allowLocalGrocery = false): float
    {
        $isLocalPickupOnly = !$allowLocalGrocery && $this->isLocalGroceryOnlyHtml($html, $xpath);

        $price = 0.0;

        if (!$isLocalPickupOnly) {
            // Priority 0: Explicit "One-time purchase" accordion row (prevents Subscribe & Save discount overriding regular price)
            $accordionRows = $xpath->query('//div[starts-with(@id, "newAccordionRow")] | //div[@id="oneTimeBuyBox"]');
            foreach ($accordionRows as $accRow) {
                $rowId = (string) $accRow->getAttribute('id');
                $rowText = strtolower(trim((string) $accRow->textContent));
                if ($rowId !== 'oneTimeBuyBox' && !str_contains($rowText, 'one-time purchase')) {
                    continue;
                }
                $oneTimeNodes = $xpath->query(
                    './/span[contains(@class, "priceToPay")]//span[contains(@class, "a-offscreen")]'
                    . ' | .//span[contains(@class, "a-price") and not(contains(@class, "a-text-price"))]//span[contains(@class, "a-offscreen")]',
                    $accRow
                );
                foreach ($oneTimeNodes as $pNode) {
                    $val = $this->parseLocalizedPrice((string) $pNode->nodeValue);
                    if ($val > 0) {
                        return $val;
                    }
                }
            }

            $mainPriceNodes = $xpath->query(
                '//div[@id="corePriceDisplay_desktop_feature_div"]//span[contains(@class, "priceToPay")]//span[contains(@class, "a-offscreen")]'
                . ' | //div[@id="corePriceDisplay_desktop_feature_div"]//span[contains(@class, "a-price") and not(contains(@class, "a-text-price"))]//span[contains(@class, "a-offscreen")]'
                . ' | //div[@id="corePrice_feature_div"]//span[contains(@class, "priceToPay")]//span[contains(@class, "a-offscreen")]'
                . ' | //div[@id="corePrice_feature_div"]//span[contains(@class, "a-price") and not(contains(@class, "a-text-price"))]//span[contains(@class, "a-offscreen")]'
                . ' | //div[@id="apex_desktop"]//span[contains(@class, "priceToPay")]//span[contains(@class, "a-offscreen")]'
                . ' | //div[@id="corePrice_desktop"]//span[contains(@class, "a-price") and not(contains(@class, "a-text-price"))]//span[contains(@class, "a-offscreen")]'
                . ' | //div[@id="buybox"]//span[@id="price_inside_buybox"]'
                . ' | //span[@id="kindle-price"]'
                . ' | //div[@id="tmmSwatches"]//li[contains(@class, "swatchElement") and contains(@class, "selected")]//span[contains(@class, "a-color-price") or contains(@class, "slot-price")]'
                . ' | //span[@id="priceblock_ourprice"]'
                . ' | //span[@id="priceblock_dealprice"]'
            );

            if ($mainPriceNodes->length > 0) {
                foreach ($mainPriceNodes as $pNode) {
                    $val = $this->parseLocalizedPrice((string) $pNode->nodeValue);
                    if ($val > 0) {
                        $price = $val;
                        break;
                    }
                }
            }

            if ($price <= 0) {
                foreach (['corePriceDisplay_desktop_feature_div', 'corePrice_feature_div'] as $divId) {
                    $wholeNodes = $xpath->query("//div[@id=\"{$divId}\"]//span[contains(@class, \"priceToPay\")]//span[contains(@class, \"a-price-whole\")]");
                    $fracNodes = $xpath->query("//div[@id=\"{$divId}\"]//span[contains(@class, \"priceToPay\")]//span[contains(@class, \"a-price-fraction\")]");
                    if ($wholeNodes->length > 0) {
                        $whole = preg_replace('/[^\d]/', '', (string) $wholeNodes->item(0)->textContent);
                        $frac = $fracNodes->length > 0 ? preg_replace('/[^\d]/', '', (string) $fracNodes->item(0)->textContent) : '00';
                        if ($whole !== '') {
                            $val = (float) "{$whole}.{$frac}";
                            if ($val > 0) {
                                $price = $val;
                                break;
                            }
                        }
                    }
                }
            }
        }

        // Fallback for suppressed Buy Box / "See All Buying Options" / local-pickup-only pages:
        // check twister-sort-filter-data for the current/selected ASIN
        if ($price <= 0 && preg_match('/<script[^>]*data-a-state=["\'][^>]*twister-sort-filter-data[^>]*["\'][^>]*>(.*?)<\/script>/s', $html, $tm)) {
            $tJson = json_decode(html_entity_decode($tm[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
            if (!empty($tJson['sortedDimValuesForAllDims']) && is_array($tJson['sortedDimValuesForAllDims'])) {
                foreach ($tJson['sortedDimValuesForAllDims'] as $items) {
                    if (!is_array($items)) continue;
                    foreach ($items as $item) {
                        $itemAsin = strtoupper(trim((string) ($item['defaultAsin'] ?? '')));
                        $isMatch = ($asin !== '' && $itemAsin === strtoupper($asin))
                            || strtoupper((string) ($item['dimensionValueState'] ?? '')) === 'SELECTED';
                        if (!$isMatch) continue;

                        foreach ($item['slots'] ?? [] as $slot) {
                            $dd = $slot['displayData'] ?? [];
                            if (!empty($dd['priceWithoutCurrencySymbol'])) {
                                $pVal = $this->parseLocalizedPrice((string) $dd['priceWithoutCurrencySymbol']);
                                if ($pVal > 0) {
                                    $price = $pVal;
                                    break 3;
                                }
                            }
                            if (!empty($dd['olpMessage'])) {
                                $pVal = $this->parseLocalizedPrice((string) $dd['olpMessage']);
                                if ($pVal > 0) {
                                    $price = $pVal;
                                    break 3;
                                }
                            }
                        }
                    }
                }
            }
        }

        // Fallback: OLP / buying choices / unqualifiedBuyBox containers
        if ($price <= 0) {
            $olpNodes = $xpath->query(
                '//div[@id="olp_feature_div"]//span[contains(@class, "a-color-price") or contains(@class, "a-offscreen")]'
                . ' | //div[@id="olpLinkWidget_feature_div"]//span[contains(@class, "a-color-price") or contains(@class, "a-offscreen")]'
                . ' | //div[@id="unqualifiedBuyBox"]//span[contains(@class, "a-color-price") or contains(@class, "a-offscreen")]'
                . ' | //div[@id="all-offers-display"]//span[contains(@class, "a-offscreen")]'
            );
            foreach ($olpNodes as $node) {
                $val = $this->parseLocalizedPrice((string) $node->textContent);
                if ($val > 0) {
                    $price = $val;
                    break;
                }
            }
        }

        // Fallback: selected DOM swatch <li> for $asin (only when not local-grocery-only)
        if ($price <= 0 && !$isLocalPickupOnly) {
            $swatchQuery = $asin !== ''
                ? "//li[@data-asin=\"{$asin}\" or @data-defaultasin=\"{$asin}\" or @data-csa-c-item-id=\"{$asin}\"]"
                : '//li[contains(@class, "swatchSelect") or contains(@class, "selected")]';
            $swatchNodes = $xpath->query($swatchQuery);
            foreach ($swatchNodes as $sNode) {
                $pNodes = $xpath->query('.//span[contains(@class, "a-price")]/span[contains(@class, "a-offscreen")] | .//span[contains(@class, "a-color-price")]', $sNode);
                if ($pNodes->length > 0) {
                    $val = $this->parseLocalizedPrice((string) $pNodes->item(0)->textContent);
                    if ($val > 0) {
                        $price = $val;
                        break;
                    }
                }
                $val = $this->parseLocalizedPrice((string) $sNode->textContent);
                if ($val > 0) {
                    $price = $val;
                    break;
                }
            }
        }

        return $price;
    }

    /**
     * Extract page-level availability and estimated stock from Amazon buybox/availability DOM.
     */
    protected function parseAvailability(DOMXPath $xpath, float $price): array
    {
        $availText = $this->extractVisibleTextFromNodes(
            $xpath,
            '//div[@id="availability"] | //div[@id="availabilityInsideBuyBox_feature_div"] | //div[@id="outOfStock"]'
        );
        $availLower = strtolower(trim($availText));

        if ($availLower !== '') {
            if (preg_match('/currently unavailable|out of stock|temporarily out of stock|we don\'t know when or if this item will be back/', $availLower)) {
                return ['available' => false, 'stock' => 0];
            }
            if (preg_match('/only\s+(\d+)\s+left\s+in\s+stock/', $availLower, $m)) {
                return ['available' => true, 'stock' => max(1, (int) $m[1])];
            }
            if (str_contains($availLower, 'in stock')) {
                return ['available' => true, 'stock' => $this->defaultStock];
            }
        }

        // Fallback: check Add to Cart / Buy Now buttons
        $buyButtons = $xpath->query('//input[@id="add-to-cart-button"] | //input[@id="buy-now-button"]');
        if ($buyButtons->length > 0) {
            return ['available' => true, 'stock' => $this->defaultStock];
        }

        // Fallback: check "See All Buying Options" / unqualifiedBuyBox when a valid OLP price exists
        $buyingOptions = $xpath->query(
            '//a[contains(@id, "buybox-see-all-buying-choices")]'
            . ' | //span[@id="buybox-see-all-buying-choices"]'
            . ' | //div[@id="unqualifiedBuyBox"]//a[contains(translate(., "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "buying options")]'
        );
        if ($buyingOptions->length > 0 && $price > 0) {
            return ['available' => true, 'stock' => $this->defaultStock];
        }

        if ($xpath->query('//div[@id="outOfStock"]')->length > 0) {
            return ['available' => false, 'stock' => 0];
        }

        return [
            'available' => $price > 0,
            'stock' => $price > 0 ? $this->defaultStock : 0,
        ];
    }

    /**
     * Extract product variants using multi-strategy cascading parser:
     * 1. Modern desktop twister sort/filter JSON state
     * 2. Embedded Twister JavaScript dimension mapping data
     * 3. DOM XPath swatches and inline twister rows
     * 4. Dropdown variation options
     * 5. Single default variant fallback
     */
    protected function parseVariants(string $html, string $asin, float $price, array $images, DOMXPath $xpath, array $availability = [], bool $allowLocalGrocery = false): array
    {
        $isLocalGroceryOnly = !$allowLocalGrocery && $this->isLocalGroceryOnlyHtml($html, $xpath);
        $pageAvailable = $availability['available'] ?? ($price > 0);
        $pageStock = $availability['stock'] ?? ($pageAvailable ? $this->defaultStock : 0);
        $variants = [];
        $seenAsins = [];
        $asinMeta = [];
        // Pre-load per-variant high-res image map from Amazon's embedded colorImages JSON blocks
        $dimValueImages = $this->extractColorImagesMap($html);

        // --- STRATEGY 1: <script type="a-state"> twister-sort-filter-data ---
        if (preg_match('/<script[^>]*data-a-state=["\'][^>]*twister-sort-filter-data[^>]*["\'][^>]*>(.*?)<\/script>/s', $html, $m)) {
            $json = json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
            if (!empty($json['sortedDimValuesForAllDims']) && is_array($json['sortedDimValuesForAllDims'])) {
                $isMultiDimTwister = count(array_filter(
                    $json['sortedDimValuesForAllDims'],
                    fn($arr) => is_array($arr) && count($arr) > 0
                )) > 1;

                foreach ($json['sortedDimValuesForAllDims'] as $dimKey => $items) {
                    $cleanDim = trim(preg_replace('/(_name|_string)$/i', '', $dimKey));
                    $dimLabel = ucwords(str_replace('_', ' ', $cleanDim ?: 'Option'));

                    foreach ($items as $idx => $item) {
                        $itemAsin = $item['defaultAsin'] ?? null;
                        if (!$itemAsin) continue;

                        $title = trim($item['dimensionValueDisplayText'] ?? '');
                        if (empty($title)) {
                            $title = "{$dimLabel} " . ($idx + 1);
                        }

                        $titleLower = strtolower($title);
                        $rawItemImg = trim((string) ($item['imageAttribute']['url'] ?? ''));
                        $hasOwnImg = false;
                        if (!empty($dimValueImages[$titleLower])) {
                            $itemImg = $dimValueImages[$titleLower];
                            $hasOwnImg = true;
                        } elseif (!empty($rawItemImg) && str_starts_with($rawItemImg, 'http')) {
                            $itemImg = preg_replace('/\._[A-Z0-9,_]+_\./i', '.', $rawItemImg);
                            $dimValueImages[$titleLower] = $itemImg;
                            $hasOwnImg = true;
                        } else {
                            $itemImg = !empty($images) ? $images[0] : '';
                        }

                        // Extract price from slots if available (do NOT pre-seed with parent fallback price)
                        $itemPrice = 0.0;
                        $hasOlpOffer = false;
                        if (!empty($item['slots']) && is_array($item['slots'])) {
                            foreach ($item['slots'] as $slot) {
                                $displayData = $slot['displayData'] ?? [];
                                if ($itemPrice <= 0 && !empty($displayData['priceWithoutCurrencySymbol'])) {
                                    $parsedP = $this->parseLocalizedPrice((string) $displayData['priceWithoutCurrencySymbol']);
                                    if ($parsedP > 0) {
                                        $itemPrice = $parsedP;
                                    }
                                }
                                if (!empty($displayData['olpMessage'])) {
                                    $parsedP = $this->parseLocalizedPrice((string) $displayData['olpMessage']);
                                    if ($parsedP > 0) {
                                        $hasOlpOffer = true;
                                        if ($itemPrice <= 0) {
                                            $itemPrice = $parsedP;
                                        }
                                    }
                                }
                            }
                        }
                        if ($itemPrice <= 0 && $itemAsin === $asin && $price > 0) {
                            $itemPrice = $price;
                        }

                        $isAvailable = ($item['dimensionValueState'] ?? '') !== 'UNAVAILABLE';
                        if ($isMultiDimTwister && $itemAsin !== $asin) {
                            $isAvailable = true;
                        }
                        if ($hasOlpOffer) {
                            $isAvailable = true;
                        } elseif ($itemAsin === $asin && !$pageAvailable) {
                            $isAvailable = false;
                        }
                        $varStock = $isAvailable ? ($itemAsin === $asin && $pageStock > 0 ? $pageStock : $this->defaultStock) : 0;

                        $existingMeta = $asinMeta[$itemAsin] ?? [];
                        $asinMeta[$itemAsin] = [
                            'price' => $itemPrice > 0 ? $itemPrice : ($existingMeta['price'] ?? $price),
                            'image' => $hasOwnImg ? $itemImg : ($existingMeta['image'] ?? $itemImg),
                            'available' => $isAvailable,
                            'stock' => $varStock,
                        ];

                        if (isset($seenAsins[$itemAsin])) continue;

                        $variants[] = [
                            'id' => $itemAsin,
                            'asin' => $itemAsin,
                            'title' => $title,
                            'price' => $itemPrice,
                            'cost_price' => $itemPrice,
                            'sku' => "AMZ-{$itemAsin}",
                            'image' => $itemImg,
                            'attributes' => [
                                $dimLabel => $title,
                            ],
                            'available' => $isAvailable,
                            'stock' => $varStock,
                        ];
                        $seenAsins[$itemAsin] = true;
                    }
                }
            }
        }

        // Collect DOM color/swatch images keyed by dimension value (e.g. "black" => "https://...")
        $swatchImgNodes = $xpath->query(
            '//div[contains(@id, "inline-twister-row")]//li//img'
            . ' | //div[contains(@id, "variation_")]//li//img'
            . ' | //div[@id="twister"]//li//img'
            . ' | //ul[contains(@class, "swatches")]//li//img'
        );
        foreach ($swatchImgNodes as $imgNode) {
            $alt = strtolower(trim((string) $imgNode->getAttribute('alt')));
            $src = trim((string) $imgNode->getAttribute('src'));
            if (
                $alt !== ''
                && $src !== ''
                && str_starts_with($src, 'http')
                && !str_contains($src, 'sprite')
                && !str_contains($src, 'transparent-pixel')
                && !isset($dimValueImages[$alt])
            ) {
                $dimValueImages[$alt] = preg_replace('/\._[A-Z0-9,_]+_\./i', '.', $src);
            }
        }

        // --- STRATEGY 2: Embedded Twister JS (multi-dimension combinations via dimensionValuesDisplayData) ---
        $twisterVariants = $this->parseTwisterJsVariants($html, $asin, $price, $images, $asinMeta, $availability, $dimValueImages);
        $hasMultiDimTwister = false;
        foreach ($twisterVariants as $tv) {
            if (count($tv['attributes'] ?? []) > 1) {
                $hasMultiDimTwister = true;
                break;
            }
        }

        // Prefer multi-dimensional Twister combinations over single-dimension slices
        if ($hasMultiDimTwister || (empty($variants) && !empty($twisterVariants))) {
            $variants = $twisterVariants;
            $seenAsins = array_fill_keys(array_column($variants, 'asin'), true);
        }

        // --- STRATEGY 3: DOM XPath Scraper (Inline Twister, Swatches, Variations) ---
        if (empty($variants)) {
            $domSelectors = [
                '//div[contains(@id, "inline-twister-row")]//li[@data-asin or @data-csa-c-item-id]',
                '//div[contains(@id, "variation_")]//li[@data-asin or @data-csa-c-item-id or @data-defaultasin]',
                '//div[@id="twister"]//li[@data-asin or @data-csa-c-item-id or @data-defaultasin]',
                '//ul[contains(@class, "swatches")]//li',
                '//li[contains(@class, "inline-twister-swatch")]',
            ];

            foreach ($domSelectors as $selector) {
                $nodes = $xpath->query($selector);
                if ($nodes->length === 0) continue;

                foreach ($nodes as $idx => $node) {
                    $itemAsin = $node->getAttribute('data-asin') 
                        ?: $node->getAttribute('data-csa-c-item-id') 
                        ?: $node->getAttribute('data-defaultasin') 
                        ?: $node->getAttribute('data-defaultasins');

                    if (!$itemAsin || strlen($itemAsin) !== 10 || isset($seenAsins[$itemAsin])) continue;

                    // Title
                    $title = '';
                    $imgNodes = $xpath->query('.//img', $node);
                    if ($imgNodes->length > 0) {
                        $title = trim($imgNodes->item(0)->getAttribute('alt'));
                    }
                    if (empty($title)) {
                        $textNodes = $xpath->query('.//span[contains(@class, "swatch-title")] | .//span[contains(@class, "a-size-mini")] | .//span[contains(@class, "a-button-text")]', $node);
                        if ($textNodes->length > 0) {
                            $title = trim(preg_replace('/\s+/', ' ', $textNodes->item(0)->textContent));
                        }
                    }
                    if (empty($title)) {
                        $rawText = trim(preg_replace('/\s+/', ' ', $node->textContent));
                        if (!empty($rawText) && strlen($rawText) < 50 && !in_array($rawText, ['←', '→', '1', '2'])) {
                            $title = $rawText;
                        }
                    }
                    if (empty($title)) {
                        $title = "Variant " . ($idx + 1);
                    }

                    // Image
                    $itemImg = $dimValueImages[strtolower($title)] ?? (!empty($images) ? $images[0] : '');
                    if (empty($dimValueImages[strtolower($title)]) && $imgNodes->length > 0) {
                        $src = trim($imgNodes->item(0)->getAttribute('src'));
                        if (!empty($src) && str_starts_with($src, 'http')) {
                            $itemImg = preg_replace('/\._[A-Z0-9,_]+_\./i', '.', $src);
                        }
                    }

                    // Price
                    $itemPrice = 0.0;
                    $hasOlpMatch = false;
                    if (preg_match('/from\s+([^\n]+)/i', (string) $node->textContent, $om)) {
                        $parsedFrom = $this->parseLocalizedPrice($om[1]);
                        if ($parsedFrom > 0) {
                            $itemPrice = $parsedFrom;
                            $hasOlpMatch = true;
                        }
                    } elseif (!$isLocalGroceryOnly) {
                        $priceNodes = $xpath->query('.//span[contains(@class, "a-price")]/span[contains(@class, "a-offscreen")] | .//span[contains(@class, "a-color-price")]', $node);
                        if ($priceNodes->length > 0) {
                            $parsedNode = $this->parseLocalizedPrice((string) $priceNodes->item(0)->textContent);
                            if ($parsedNode > 0) {
                                $itemPrice = $parsedNode;
                            }
                        }
                    }
                    if ($itemPrice <= 0 && $itemAsin === $asin && $price > 0) {
                        $itemPrice = $price;
                    }

                    $liClass = strtolower((string) $node->getAttribute('class'));
                    $isAvailable = !str_contains($liClass, 'unavailable') || $hasOlpMatch;
                    if ($itemAsin === $asin && !$pageAvailable && !$hasOlpMatch) {
                        $isAvailable = false;
                    }
                    $varStock = $isAvailable ? ($itemAsin === $asin && $pageStock > 0 ? $pageStock : $this->defaultStock) : 0;

                    $variants[] = [
                        'id' => $itemAsin,
                        'asin' => $itemAsin,
                        'title' => $title,
                        'price' => $itemPrice,
                        'cost_price' => $itemPrice,
                        'sku' => "AMZ-{$itemAsin}",
                        'image' => $itemImg,
                        'attributes' => [
                            'Option' => $title,
                        ],
                        'available' => $isAvailable,
                        'stock' => $varStock,
                    ];
                    $seenAsins[$itemAsin] = true;
                }

                if (!empty($variants)) break;
            }
        }

        // --- STRATEGY 3b: Books / Media Format Swatches (#tmmSwatches: Hardcover, Paperback, Kindle, Audiobook) ---
        if (empty($variants)) {
            $tmmNodes = $xpath->query('//div[@id="tmmSwatches"]//li[contains(@class, "swatchElement")]');
            foreach ($tmmNodes as $tNode) {
                $linkNodes = $xpath->query('.//a[contains(@href, "/dp/") or contains(@href, "/gp/product/")]', $tNode);
                $href = $linkNodes->length > 0 ? (string) $linkNodes->item(0)->getAttribute('href') : '';
                $itemAsin = $href !== '' ? $this->extractAsin($href) : '';

                $liClass = strtolower((string) $tNode->getAttribute('class'));
                $isSelected = str_contains($liClass, 'selected');
                if ($itemAsin === '' && $isSelected) {
                    $itemAsin = $asin;
                }
                if ($itemAsin === '' || strlen($itemAsin) !== 10 || isset($seenAsins[$itemAsin])) {
                    continue;
                }

                $labelNodes = $xpath->query('.//span[contains(@class, "slot-title")] | .//a/span[1]', $tNode);
                $fmtTitle = $labelNodes->length > 0 ? trim(preg_replace('/\s+/', ' ', (string) $labelNodes->item(0)->textContent)) : '';
                if ($fmtTitle === '') {
                    continue;
                }

                $priceNodes = $xpath->query('.//span[contains(@class, "slot-price")] | .//span[contains(@class, "a-color-price")] | .//span[contains(@class, "a-color-base")]', $tNode);
                $fmtPrice = 0.0;
                foreach ($priceNodes as $pn) {
                    $parsedP = $this->parseLocalizedPrice((string) $pn->textContent);
                    if ($parsedP > 0) {
                        $fmtPrice = $parsedP;
                        break;
                    }
                }
                if ($fmtPrice <= 0 && $isSelected && $price > 0) {
                    $fmtPrice = $price;
                }

                $isAvailable = !str_contains($liClass, 'unavailable') && ($fmtPrice > 0 || $isSelected);
                $varStock = $isAvailable ? ($itemAsin === $asin ? $pageStock : $this->defaultStock) : 0;

                $variants[] = [
                    'id' => $itemAsin,
                    'asin' => $itemAsin,
                    'title' => $fmtTitle,
                    'price' => $fmtPrice,
                    'cost_price' => $fmtPrice,
                    'sku' => "AMZ-{$itemAsin}",
                    'image' => !empty($images) ? $images[0] : '',
                    'attributes' => [
                        'Format' => $fmtTitle,
                    ],
                    'available' => $isAvailable,
                    'stock' => $varStock,
                ];
                $seenAsins[$itemAsin] = true;
            }
        }

        // --- STRATEGY 4: Select dropdown options (Size / Style dropdowns) ---
        if (empty($variants)) {
            $selectOptions = $xpath->query('//select[contains(@name, "dropdown_selected")]/option[@value and not(@value="-1")]');
            if ($selectOptions->length > 0) {
                foreach ($selectOptions as $opt) {
                    $val = $opt->getAttribute('value');
                    $itemAsin = null;
                    if (preg_match('/(?:,|^|\/)([A-Z0-9]{10})(?:$|,|\?)/i', $val, $am)) {
                        $itemAsin = strtoupper($am[1]);
                    } else {
                        $itemAsin = strtoupper(trim((string) $opt->getAttribute('data-asin')));
                    }
                    if (!$itemAsin || strlen($itemAsin) !== 10 || isset($seenAsins[$itemAsin])) continue;

                    $title = trim(preg_replace('/\s+/', ' ', $opt->textContent));
                    if (empty($title)) continue;

                    $isAvailable = ($itemAsin === $asin) ? $pageAvailable : true;
                    $varStock = $isAvailable ? ($itemAsin === $asin ? $pageStock : $this->defaultStock) : 0;

                    $variants[] = [
                        'id' => $itemAsin,
                        'asin' => $itemAsin,
                        'title' => $title,
                        'price' => $price,
                        'cost_price' => $price,
                        'sku' => "AMZ-{$itemAsin}",
                        'image' => $dimValueImages[strtolower($title)] ?? (!empty($images) ? $images[0] : ''),
                        'attributes' => [
                            'Size' => $title,
                        ],
                        'available' => $isAvailable,
                        'stock' => $varStock,
                    ];
                    $seenAsins[$itemAsin] = true;
                }
            }
        }

        // Fallback: Default single variant if product has no variations
        if (empty($variants)) {
            $variants[] = [
                'id' => $asin,
                'asin' => $asin,
                'title' => 'Default Title',
                'price' => $price,
                'cost_price' => $price,
                'sku' => "AMZ-{$asin}",
                'image' => !empty($images) ? $images[0] : '',
                'attributes' => [],
                'available' => $pageAvailable,
                'stock' => $pageStock,
            ];
        }

        return $variants;
    }

    /**
     * Extract technical specifications and product details from Amazon tables/bullets.
     */
    protected function parseSpecifications(DOMXPath $xpath): array
    {
        $specs = [];

        $tableRows = $xpath->query(
            '//table[@id="productDetails_techSpec_section_1"]//tr'
            . ' | //table[@id="productDetails_techSpec_section_2"]//tr'
            . ' | //table[@id="productDetails_detailBullets_sections1"]//tr'
            . ' | //div[@id="productOverview_feature_div"]//table//tr'
        );

        foreach ($tableRows as $row) {
            $th = $xpath->query('.//th | .//td[1]', $row);
            $td = $xpath->query('.//td[last()]', $row);
            if ($th->length > 0 && $td->length > 0) {
                $k = trim(preg_replace('/\s+/', ' ', str_replace(["\u{200E}", "\u{200F}"], '', $th->item(0)->textContent)));
                $v = trim(preg_replace('/\s+/', ' ', str_replace(["\u{200E}", "\u{200F}"], '', $td->item(0)->textContent)));
                $k = rtrim($k, ':');
                if ($k !== '' && $v !== '' && $k !== $v && !str_contains(strtolower($k), 'customer reviews') && !str_contains(strtolower($k), 'best sellers rank')) {
                    $specs[$k] = $v;
                }
            }
        }

        $bulletItems = $xpath->query('//div[@id="detailBullets_feature_div"]//li/span[contains(@class, "a-list-item")]');
        foreach ($bulletItems as $item) {
            $spans = $xpath->query('./span', $item);
            if ($spans->length >= 2) {
                $k = trim(preg_replace('/[\s:\x{200E}\x{200F}]+/u', ' ', $spans->item(0)->textContent));
                $k = trim(rtrim($k, ':'));
                $v = trim(preg_replace('/\s+/', ' ', str_replace(["\u{200E}", "\u{200F}"], '', $spans->item(1)->textContent)));
                if ($k !== '' && $v !== '' && !isset($specs[$k]) && !str_contains(strtolower($k), 'customer reviews') && !str_contains(strtolower($k), 'best sellers rank')) {
                    $specs[$k] = $v;
                }
            }
        }

        // Fashion / Apparel Product Facts table (#productFactsDesktop_feature_div .product-facts-detail)
        $factRows = $xpath->query('//div[@id="productFactsDesktop_feature_div"]//div[contains(@class, "product-facts-detail")]');
        foreach ($factRows as $factRow) {
            $cols = $xpath->query('./div', $factRow);
            if ($cols->length >= 2) {
                $k = trim(preg_replace('/\s+/', ' ', str_replace(["\u{200E}", "\u{200F}"], '', $cols->item(0)->textContent)));
                $v = trim(preg_replace('/\s+/', ' ', str_replace(["\u{200E}", "\u{200F}"], '', $cols->item(1)->textContent)));
                $k = rtrim($k, ':');
                if ($k !== '' && $v !== '' && !isset($specs[$k])) {
                    $specs[$k] = $v;
                }
            }
        }

        return $specs;
    }

    /**
     * Extract star rating (0.0 - 5.0) and total customer review count.
     */
    protected function parseRatingAndReviews(DOMXPath $xpath): array
    {
        $rating = 0.0;
        $ratingNodes = $xpath->query(
            '//span[@id="acrPopover"]/@title'
            . ' | //div[@id="averageCustomerReviews"]//span[contains(@class, "a-icon-alt")]'
            . ' | //span[@data-hook="rating-out-of-text"]'
        );
        foreach ($ratingNodes as $rn) {
            $txt = trim((string) $rn->textContent);
            if (preg_match('/(\d+(?:[.,]\d+)?)\s*(?:out of|von|sur|de|\/\s*5)/i', $txt, $m)) {
                $val = (float) str_replace(',', '.', $m[1]);
                if ($val > 0 && $val <= 5.0) {
                    $rating = round($val, 2);
                    break;
                }
            }
        }

        $reviewCount = 0;
        $revNodes = $xpath->query('//span[@id="acrCustomerReviewText"] | //span[@data-hook="total-review-count"]');
        foreach ($revNodes as $rn) {
            $digits = preg_replace('/[^\d]/', '', (string) $rn->textContent);
            if ($digits !== '') {
                $reviewCount = (int) $digits;
                break;
            }
        }

        return [
            'rating' => $rating,
            'review_count' => $reviewCount,
        ];
    }

    /**
     * Extract physical weight & dimensions (width, height) from Amazon specifications table.
     */
    protected function extractPhysicalDimensions(array $specifications): array
    {
        $weight = null;
        $weightUnit = 'kg';
        $width = null;
        $widthUnit = 'in';
        $height = null;
        $heightUnit = 'in';

        $weightValStr = null;
        $dimValStr = null;

        foreach ($specifications as $k => $v) {
            $kl = strtolower((string) $k);
            if (str_contains($kl, 'weight')) {
                // Prefer Item Weight over Shipping Weight
                if ($weightValStr === null || !str_contains($kl, 'shipping')) {
                    $weightValStr = (string) $v;
                }
            }
            if ($dimValStr === null && str_contains($kl, 'dimension')) {
                $dimValStr = (string) $v;
            }
        }

        if ($weightValStr !== null && preg_match('/(\d+(?:\.\d+)?)\s*(kilograms?|kgs?|grams?|g|pounds?|lbs?|ounces?|oz)\b/i', $weightValStr, $wm)) {
            $weight = round((float) $wm[1], 2);
            $rawUnit = strtolower($wm[2]);
            $weightUnit = match (true) {
                str_starts_with($rawUnit, 'kg'), str_starts_with($rawUnit, 'kilogram') => 'kg',
                str_starts_with($rawUnit, 'g') => 'g',
                str_starts_with($rawUnit, 'lb'), str_starts_with($rawUnit, 'pound') => 'lb',
                str_starts_with($rawUnit, 'oz'), str_starts_with($rawUnit, 'ounce') => 'oz',
                default => 'kg',
            };
        }

        if ($dimValStr !== null && preg_match('/(\d+(?:\.\d+)?)\s*[x×*]\s*(\d+(?:\.\d+)?)(?:\s*[x×*]\s*(\d+(?:\.\d+)?))?\s*(inches?|in\b|centimeters?|cm\b|millimeters?|mm\b)?/i', $dimValStr, $dm)) {
            $wVal = round((float) $dm[2], 2);
            $hVal = !empty($dm[3]) ? round((float) $dm[3], 2) : round((float) $dm[1], 2);
            $rawDimUnit = strtolower((string) ($dm[4] ?? 'in'));
            $unit = match (true) {
                str_starts_with($rawDimUnit, 'cm'), str_starts_with($rawDimUnit, 'centimeter') => 'cm',
                str_starts_with($rawDimUnit, 'mm'), str_starts_with($rawDimUnit, 'millimeter') => 'mm',
                default => 'in',
            };
            $width = $wVal > 0 ? $wVal : null;
            $height = $hVal > 0 ? $hVal : null;
            $widthUnit = $unit;
            $heightUnit = $unit;
        }

        return [
            'weight' => $weight,
            'weight_unit' => $weightUnit,
            'width' => $width,
            'width_unit' => $widthUnit,
            'height' => $height,
            'height_unit' => $heightUnit,
        ];
    }

    /**
     * Detect whether an Amazon product is a physical shipped item vs digital/eBook/code.
     */
    protected function detectIsPhysical(DOMXPath $xpath, string $title, array $specifications): bool
    {
        $selectedFormat = strtolower(trim($this->extractVisibleTextFromNodes(
            $xpath,
            '//div[@id="tmmSwatches"]//li[contains(@class, "swatchElement") and contains(@class, "selected")] | //div[@id="tmmSwatches"]//li[contains(@class, "swatchElement") and contains(@class, "selected")]//span'
        )));

        $digitalMarkers = [
            'kindle edition',
            'audible audiobook',
            'digital download',
            'online game code',
            'digital code',
            'egift card',
            'prime video',
            'appstore for android',
        ];

        $haystack = strtolower($selectedFormat . ' ' . $title . ' ' . implode(' ', $specifications));
        foreach ($digitalMarkers as $marker) {
            if (str_contains($haystack, $marker)) {
                return false;
            }
        }

        return $xpath->query('//span[@id="kindle-price"]')->length === 0;
    }

    /**
     * Extract a JSON object or array following a regex key prefix using balanced brace/bracket matching.
     */
    protected function extractBalancedJsonBlock(string $html, string $keyPattern, string $openChar = '{', string $closeChar = '}'): ?array
    {
        if (!preg_match('/' . $keyPattern . '/is', $html, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $matchStr = $m[0][0];
        $matchOffset = $m[0][1];
        $startPos = $matchOffset + strlen($matchStr) - 1;
        if (($html[$startPos] ?? '') !== $openChar) {
            $foundPos = strpos($html, $openChar, $matchOffset);
            if ($foundPos === false) {
                return null;
            }
            $startPos = $foundPos;
        }

        $len = strlen($html);
        $maxScan = min($len, $startPos + 500000);
        $depth = 0;
        $inStr = false;
        $quoteChar = '';
        $esc = false;
        $endPos = null;

        for ($i = $startPos; $i < $maxScan; $i++) {
            $ch = $html[$i];
            if ($esc) {
                $esc = false;
                continue;
            }
            if ($ch === '\\') {
                $esc = true;
                continue;
            }
            if ($inStr) {
                if ($ch === $quoteChar) {
                    $inStr = false;
                }
                continue;
            }
            if ($ch === '"' || $ch === "'") {
                $inStr = true;
                $quoteChar = $ch;
                continue;
            }
            if ($ch === $openChar) {
                $depth++;
            } elseif ($ch === $closeChar) {
                $depth--;
                if ($depth === 0) {
                    $endPos = $i;
                    break;
                }
            }
        }

        if ($endPos === null) {
            return null;
        }

        $jsonStr = substr($html, $startPos, $endPos - $startPos + 1);
        $decoded = json_decode($jsonStr, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Extract all per-variant high-res images from Amazon's embedded "colorImages" JSON blocks
     * using balanced-brace scanning (handles nested objects and multiple colorImages blocks).
     */
    protected function extractColorImagesMap(string $html): array
    {
        $map = [];
        $offset = 0;
        $len = strlen($html);

        while (preg_match('/["\']colorImages["\']\s*:\s*\{/i', $html, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $matchStart = $m[0][1];
            $braceStart = strpos($html, '{', $matchStart + 11);
            if ($braceStart === false) {
                break;
            }

            $depth = 0;
            $inStr = false;
            $quoteChar = '';
            $esc = false;
            $braceEnd = null;
            $maxScan = min($len, $braceStart + 500000);

            for ($i = $braceStart; $i < $maxScan; $i++) {
                $ch = $html[$i];
                if ($esc) {
                    $esc = false;
                    continue;
                }
                if ($ch === '\\') {
                    $esc = true;
                    continue;
                }
                if ($inStr) {
                    if ($ch === $quoteChar) {
                        $inStr = false;
                    }
                    continue;
                }
                if ($ch === '"' || $ch === "'") {
                    $inStr = true;
                    $quoteChar = $ch;
                    continue;
                }
                if ($ch === '{') {
                    $depth++;
                } elseif ($ch === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $braceEnd = $i;
                        break;
                    }
                }
            }

            $offset = ($braceEnd !== null) ? ($braceEnd + 1) : ($braceStart + 1);
            if ($braceEnd === null) {
                continue;
            }

            $jsonStr = substr($html, $braceStart, $braceEnd - $braceStart + 1);
            $decoded = json_decode($jsonStr, true);
            if (!is_array($decoded)) {
                continue;
            }

            foreach ($decoded as $dimVal => $imgList) {
                if (!is_string($dimVal) || strtolower(trim($dimVal)) === 'initial' || !is_array($imgList)) {
                    continue;
                }
                $chosenImg = '';
                foreach ($imgList as $imgEntry) {
                    if (!is_array($imgEntry)) continue;
                    $candidate = $imgEntry['hiRes'] ?? $imgEntry['large'] ?? '';
                    if (is_string($candidate) && str_starts_with(trim($candidate), 'http')) {
                        $chosenImg = trim($candidate);
                        if (($imgEntry['variant'] ?? '') === 'MAIN') {
                            break;
                        }
                    }
                }
                if ($chosenImg !== '') {
                    $map[strtolower(trim($dimVal))] = $chosenImg;
                }
            }
        }

        return $map;
    }

    /**
     * Parse full multi-dimensional variant combinations from Amazon Twister JS data.
     */
    protected function parseTwisterJsVariants(
        string $html,
        string $currentAsin,
        float $price,
        array $images,
        array $asinMeta = [],
        array $availability = [],
        array $dimValueImages = []
    ): array {
        $pageAvailable = $availability['available'] ?? ($price > 0);
        $pageStock = $availability['stock'] ?? ($pageAvailable ? $this->defaultStock : 0);
        $dimDisplayData = null;
        $dimensions = [];
        $labels = [];
        $colorImages = [];

        $dimDisplayData = $this->extractBalancedJsonBlock($html, '["\']?dimensionValuesDisplayData["\']?\s*:\s*\{', '{', '}');

        if (empty($dimDisplayData) || !is_array($dimDisplayData)) {
            return [];
        }

        $dimensions = $this->extractBalancedJsonBlock($html, '["\']?dimensions["\']?\s*:\s*\[', '[', ']') ?: [];
        $labels = $this->extractBalancedJsonBlock($html, '["\']?variationDisplayLabels["\']?\s*:\s*\{', '{', '}') ?: [];
        $decodedColorImgs = $this->extractBalancedJsonBlock($html, '["\']colorImages["\']\s*:\s*\{', '{', '}') ?: [];
        foreach ($decodedColorImgs as $cKey => $cImgs) {
            if (is_string($cKey) && strtolower($cKey) !== 'initial' && is_array($cImgs)) {
                $colorImages[strtolower(trim($cKey))] = $cImgs;
            }
        }

        $dimKeys = !empty($dimensions) ? array_values($dimensions) : array_keys($labels);
        $variants = [];
        $seenAsins = [];

        foreach ($dimDisplayData as $itemAsin => $valArray) {
            if (!is_string($itemAsin) || strlen($itemAsin) !== 10 || isset($seenAsins[$itemAsin])) {
                continue;
            }

            $values = is_array($valArray) ? array_values($valArray) : [(string) $valArray];
            $cleanValues = array_values(array_filter(array_map(fn($v) => trim((string) $v), $values), fn($v) => $v !== ''));
            if (empty($cleanValues)) {
                continue;
            }

            $attributes = [];
            foreach ($cleanValues as $idx => $val) {
                $dimKey = $dimKeys[$idx] ?? null;
                if ($dimKey && !empty($labels[$dimKey])) {
                    $attrName = trim((string) $labels[$dimKey]);
                } elseif ($dimKey) {
                    $cleanKey = trim(preg_replace('/(_name|_string)$/i', '', (string) $dimKey));
                    $attrName = ucwords(str_replace('_', ' ', $cleanKey ?: 'Option'));
                } else {
                    $attrName = count($cleanValues) === 1 ? 'Option' : 'Option ' . ($idx + 1);
                }
                $attributes[$attrName] = $val;
            }

            $title = implode(' / ', $cleanValues);
            $meta = $asinMeta[$itemAsin] ?? [];

            $varImg = '';
            // Build candidate keys: combined dimension keys first (e.g. "size 3 136", "136 size 3"), then individual values
            $candidateKeys = [];
            if (count($cleanValues) > 1) {
                $candidateKeys[] = strtolower(preg_replace('/\s+/', ' ', implode(' ', $cleanValues)));
                $candidateKeys[] = strtolower(preg_replace('/\s+/', ' ', implode(' ', array_reverse($cleanValues))));
                $candidateKeys[] = strtolower(implode(' / ', $cleanValues));
            }
            foreach ($cleanValues as $val) {
                $candidateKeys[] = strtolower($val);
            }
            $candidateKeys = array_values(array_unique(array_filter($candidateKeys)));

            // 1. Prefer high-res colorImages match (combined or single dimension value)
            foreach ($candidateKeys as $keyLower) {
                if (!empty($colorImages[$keyLower][0]['hiRes'])) {
                    $varImg = $colorImages[$keyLower][0]['hiRes'];
                    break;
                }
                if (!empty($colorImages[$keyLower][0]['large'])) {
                    $varImg = $colorImages[$keyLower][0]['large'];
                    break;
                }
            }
            // 2. Fallback to extracted colorImages / swatch image map (covers combined keys like "size 3 136" and single values)
            if (empty($varImg)) {
                foreach ($candidateKeys as $keyLower) {
                    if (!empty($dimValueImages[$keyLower])) {
                        $varImg = $dimValueImages[$keyLower];
                        break;
                    }
                }
            }
            // 3. Fallback to per-ASIN meta image or main product image
            if (empty($varImg)) {
                $varImg = $meta['image'] ?? (!empty($images) ? $images[0] : '');
            }

            $metaPrice = (float) ($meta['price'] ?? 0);
            $itemPrice = $metaPrice > 0
                ? $metaPrice
                : (($itemAsin === $currentAsin && $price > 0) ? $price : 0.0);

            // For multi-dimension products, twister-sort-filter-data marks other-dimension values as UNAVAILABLE
            // relative to the currently selected sibling option, NOT globally out of stock.
            $isMultiDim = count($cleanValues) > 1;
            if ($itemAsin === $currentAsin) {
                $isAvailable = $pageAvailable;
                $varStock = $pageStock;
            } elseif ($isMultiDim) {
                $isAvailable = true;
                $varStock = $this->defaultStock;
            } else {
                $isAvailable = $meta['available'] ?? true;
                $varStock = $meta['stock'] ?? ($isAvailable ? $this->defaultStock : 0);
            }

            $variants[] = [
                'id' => $itemAsin,
                'asin' => $itemAsin,
                'title' => $title,
                'price' => $itemPrice,
                'cost_price' => $itemPrice,
                'sku' => "AMZ-{$itemAsin}",
                'image' => $varImg,
                'attributes' => $attributes,
                'available' => $isAvailable,
                'stock' => $varStock,
            ];
            $seenAsins[$itemAsin] = true;
        }

        return $variants;
    }

    /**
     * When Amazon omits per-variant prices from the initial HTML (e.g. slotsFetched: false),
     * fetch the real price for each distinct variant ASIN concurrently in batches using Http::pool().
     */
    protected function enrichVariantPrices(array $variants, string $currentAsin, float $basePrice, string $host = 'www.amazon.com'): array
    {
        if (empty($variants)) {
            return $variants;
        }

        // Always enrich variants with missing price (<= 0); also enrich fallback-priced variants when there is no price variation yet
        $missingPriceAsins = [];
        $fallbackPriceAsins = [];
        $distinctPrices = [];
        foreach ($variants as $v) {
            $vp = (float) ($v['price'] ?? 0);
            if ($vp > 0) {
                $distinctPrices[(string) $vp] = true;
            }
            $vAsin = (string) ($v['asin'] ?? '');
            if ($vAsin === '' || strlen($vAsin) !== 10) {
                continue;
            }
            if ($vp <= 0) {
                $missingPriceAsins[$vAsin] = true;
            } elseif (count($variants) > 1 && $vAsin !== $currentAsin && $basePrice > 0 && abs($vp - $basePrice) < 0.001) {
                $fallbackPriceAsins[$vAsin] = true;
            }
        }

        $asinsToFetch = $missingPriceAsins;
        if (count($distinctPrices) <= 1) {
            $asinsToFetch = array_merge($asinsToFetch, $fallbackPriceAsins);
        }

        if (empty($asinsToFetch)) {
            return $variants;
        }

        $targetAsins = array_slice(array_keys($asinsToFetch), 0, 60);
        $useBrightData = !empty($this->brightdataApiKey) && !empty($this->brightdataZone);
        $country = $this->resolveBrightDataCountry("https://{$host}/");
        $fetchedPrices = [];
        $fetchedAvail = [];

        foreach (array_chunk($targetAsins, 10) as $batch) {
            try {
                $responses = Http::pool(function (\Illuminate\Http\Client\Pool $pool) use ($batch, $host, $useBrightData, $country) {
                    foreach ($batch as $vAsin) {
                        $vUrl = "https://{$host}/dp/{$vAsin}?th=1&psc=1";
                        if ($host === 'www.amazon.com') {
                            $vUrl .= "&language=en_US&currency=USD";
                        }
                        if ($useBrightData) {
                            $pool->as($vAsin)
                                ->withHeaders([
                                    'Authorization' => 'Bearer ' . $this->brightdataApiKey,
                                    'Content-Type' => 'application/json',
                                ])
                                ->timeout(30)
                                ->post('https://api.brightdata.com/request', [
                                    'zone' => $this->brightdataZone,
                                    'url' => $vUrl,
                                    'country' => $country,
                                    'format' => 'raw',
                                ]);
                        } else {
                            $pool->as($vAsin)
                                ->withHeaders([
                                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                                    'Accept-Language' => 'en-US,en;q=0.9',
                                ])
                                ->timeout(15)
                                ->get($vUrl);
                        }
                    }
                });

                foreach ($batch as $vAsin) {
                    $resp = $responses[$vAsin] ?? null;
                    if (!$resp || $resp instanceof \Throwable || !$resp->successful()) {
                        continue;
                    }
                    $body = $resp->body();
                    if (empty($body) || str_contains($body, 'validateCaptcha') || str_contains($body, 'Robot Check')) {
                        continue;
                    }

                    $parsedPrice = $this->extractPriceFromHtml($body, $vAsin, false);
                    if ($parsedPrice <= 0 && $useBrightData && $this->isLocalGroceryOnlyHtml($body)) {
                        $vUrl = "https://{$host}/dp/{$vAsin}?th=1&psc=1" . ($host === 'www.amazon.com' ? '&language=en_US&currency=USD' : '');
                        $retryBody = $this->scrapeWithBrightData($vUrl);
                        if (!empty($retryBody) && !str_contains($retryBody, 'validateCaptcha') && !str_contains($retryBody, 'Robot Check')) {
                            $body = $retryBody;
                            $parsedPrice = $this->extractPriceFromHtml($body, $vAsin, false);
                        }
                    }

                    if ($parsedPrice > 0) {
                        $fetchedPrices[$vAsin] = $parsedPrice;
                        $fetchedAvail[$vAsin] = true;
                    } elseif (preg_match('/currently unavailable|temporarily out of stock/i', $body)) {
                        $fetchedAvail[$vAsin] = false;
                    }

                    // Opportunistically harvest sibling variant OLP/prices from twister-sort-filter-data on this page
                    if (preg_match('/<script[^>]*data-a-state=["\'][^>]*twister-sort-filter-data[^>]*["\'][^>]*>(.*?)<\/script>/s', $body, $tm)) {
                        $tJson = json_decode(html_entity_decode($tm[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
                        foreach ($tJson['sortedDimValuesForAllDims'] ?? [] as $items) {
                            if (!is_array($items)) continue;
                            foreach ($items as $item) {
                                $sAsin = strtoupper(trim((string) ($item['defaultAsin'] ?? '')));
                                if (strlen($sAsin) !== 10) continue;
                                foreach ($item['slots'] ?? [] as $slot) {
                                    $dd = $slot['displayData'] ?? [];
                                    $sPrice = 0.0;
                                    if (!empty($dd['priceWithoutCurrencySymbol'])) {
                                        $sPrice = $this->parseLocalizedPrice((string) $dd['priceWithoutCurrencySymbol']);
                                    } elseif (!empty($dd['olpMessage'])) {
                                        $sPrice = $this->parseLocalizedPrice((string) $dd['olpMessage']);
                                    }
                                    if ($sPrice > 0 && empty($fetchedPrices[$sAsin])) {
                                        $fetchedPrices[$sAsin] = $sPrice;
                                        $fetchedAvail[$sAsin] = true;
                                    }
                                }
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                Log::warning('Amazon variant price enrichment batch failed: ' . $e->getMessage());
            }
        }

        if (empty($fetchedPrices) && empty($fetchedAvail)) {
            return $variants;
        }

        foreach ($variants as &$v) {
            $vAsin = strtoupper((string) ($v['asin'] ?? ''));
            if (isset($fetchedPrices[$vAsin])) {
                $v['price'] = $fetchedPrices[$vAsin];
                $v['cost_price'] = $fetchedPrices[$vAsin];
            }
            if (isset($fetchedAvail[$vAsin])) {
                if ($fetchedAvail[$vAsin] === false) {
                    $v['available'] = false;
                    $v['stock'] = 0;
                } elseif (empty($v['available']) || (int) ($v['stock'] ?? 0) <= 0) {
                    $v['available'] = true;
                    $v['stock'] = $this->defaultStock;
                }
            }
        }
        unset($v);

        return $variants;
    }

    /**
     * Fast price extraction from an Amazon product page HTML string (strictly scoped to main buybox/OLP).
     */
    protected function extractPriceFromHtml(string $html, string $asin = '', bool $allowLocalGrocery = false): float
    {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        $xpath = new DOMXPath($dom);
        libxml_clear_errors();

        return $this->extractMainProductPrice($xpath, $html, $asin, $allowLocalGrocery);
    }
}
