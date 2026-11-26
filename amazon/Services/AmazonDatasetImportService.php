<?php

namespace Amazon\Services;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\SiteSetting;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class AmazonDatasetImportService
{
    public const DEFAULT_DATASET_ID = 'gd_l7q7dkf244hwjntr0';
    public const DEFAULT_ZIPCODE = '11372';

    /**
     * Load Bright Data Dataset API configuration without exposing the raw API key.
     */
    public function getConfig(): array
    {
        $amazonSettings = SiteSetting::where('group', 'amazon')->pluck('value', 'key')->toArray();
        $syncSettings = SiteSetting::where('group', 'amazon_sync')->pluck('value', 'key')->toArray();

        $apiKey = trim((string) ($amazonSettings['amazon_brightdata_api_key'] ?? config('amazon.brightdata_api_key', '')));
        $datasetId = trim((string) ($syncSettings['brightdata_dataset_id'] ?? self::DEFAULT_DATASET_ID));
        if ($datasetId === '') {
            $datasetId = self::DEFAULT_DATASET_ID;
        }

        return [
            'has_api_key' => $apiKey !== '',
            'dataset_id' => $datasetId,
            'default_zipcode' => self::DEFAULT_ZIPCODE,
        ];
    }

    /**
     * Resolve internal Amazon credentials and defaults.
     */
    protected function resolveCredentials(?string $overrideDatasetId = null): array
    {
        $amazonSettings = SiteSetting::where('group', 'amazon')->pluck('value', 'key')->toArray();
        $syncSettings = SiteSetting::where('group', 'amazon_sync')->pluck('value', 'key')->toArray();

        $apiKey = trim((string) ($amazonSettings['amazon_brightdata_api_key'] ?? config('amazon.brightdata_api_key', '')));
        $datasetId = trim((string) ($overrideDatasetId ?: ($syncSettings['brightdata_dataset_id'] ?? self::DEFAULT_DATASET_ID)));
        if ($datasetId === '') {
            $datasetId = self::DEFAULT_DATASET_ID;
        }

        $defaultStock = !empty($amazonSettings['amazon_default_stock'])
            ? max(1, (int) $amazonSettings['amazon_default_stock'])
            : 20;

        $defaultStatus = !empty($amazonSettings['amazon_default_status'])
            ? (string) $amazonSettings['amazon_default_status']
            : 'published';

        $affiliateTag = trim((string) ($amazonSettings['amazon_affiliate_tag'] ?? ''));

        return [
            'api_key' => $apiKey,
            'dataset_id' => $datasetId,
            'default_stock' => $defaultStock,
            'default_status' => in_array($defaultStatus, ['published', 'draft'], true) ? $defaultStatus : 'published',
            'affiliate_tag' => $affiliateTag,
        ];
    }

    /**
     * Sanitize US ZIP code (digits only, 5 chars, fallback 11372).
     */
    public function sanitizeZipcode(?string $zipcode): string
    {
        $digits = preg_replace('/[^\d]/', '', (string) $zipcode);
        if (strlen($digits) >= 5) {
            return substr($digits, 0, 5);
        }
        return self::DEFAULT_ZIPCODE;
    }

    /**
     * Resolve short share links (a.co, amzn.to, amzn.eu, amzn.asia).
     */
    protected function resolveShortUrl(string $input): string
    {
        $trimmed = trim($input);
        if (!preg_match('/^https?:\/\/(?:www\.)?(a\.co|amzn\.to|amzn\.eu|amzn\.asia)\//i', $trimmed)) {
            return $trimmed;
        }

        try {
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
                'Accept-Language' => 'en-US,en;q=0.9',
            ])->withOptions(['allow_redirects' => ['track_redirects' => true]])->timeout(12)->get($trimmed);

            $effective = (string) ($response->effectiveUri() ?: '');
            if ($effective !== '' && str_contains(strtolower($effective), 'amazon.')) {
                return $effective;
            }
        } catch (Exception $e) {
            Log::warning("AmazonDatasetImportService failed resolving short URL {$trimmed}: " . $e->getMessage());
        }

        return $trimmed;
    }

    /**
     * Extract 10-char ASIN/ISBN from an Amazon URL or raw ASIN string.
     */
    public function extractAsin(string $input): string
    {
        $input = trim($input);

        if (preg_match('/(?:\/dp\/|\/gp\/product\/|\/gp\/aw\/d\/|\/o\/ASIN\/|\/product\/)([A-Z0-9]{10})(?:[\/?&#]|$)/i', $input, $matches)) {
            return strtoupper($matches[1]);
        }

        if (preg_match('/(B0[0-9A-Z]{8})/i', $input, $matches)) {
            return strtoupper($matches[1]);
        }

        if (preg_match('/^[A-Z0-9]{10}$/i', $input)) {
            return strtoupper($input);
        }

        return '';
    }

    /**
     * Preview Amazon products via Bright Data Dataset API with ZIP deliverability checks.
     */
    public function previewProducts(
        array $asinsOrUrls,
        string $zipcode = self::DEFAULT_ZIPCODE,
        bool $enrichVariants = true,
        ?string $datasetId = null
    ): array {
        $creds = $this->resolveCredentials($datasetId);
        if ($creds['api_key'] === '') {
            throw new Exception('Bright Data API Key is not configured in Amazon Scraper Settings.', 422);
        }

        $zip = $this->sanitizeZipcode($zipcode);

        $asinInputs = [];
        $invalidInputs = [];

        foreach ($asinsOrUrls as $raw) {
            $rawStr = trim((string) $raw);
            if ($rawStr === '') {
                continue;
            }
            $resolved = $this->resolveShortUrl($rawStr);
            $asin = $this->extractAsin($resolved);
            if ($asin === '') {
                $invalidInputs[] = $rawStr;
                continue;
            }
            if (!isset($asinInputs[$asin])) {
                $asinInputs[$asin] = $resolved;
            }
        }

        if (empty($asinInputs)) {
            $detail = !empty($invalidInputs) ? ' Invalid entries: ' . implode(', ', array_slice($invalidInputs, 0, 5)) : '';
            throw new Exception("No valid Amazon ASINs or URLs found.{$detail}", 422);
        }

        // Cap at 25 ASINs per preview request
        $targetAsins = array_slice(array_keys($asinInputs), 0, 25);

        $recordsByAsin = $this->fetchRecordsFromDataset($targetAsins, $zip, $creds['dataset_id'], $creds['api_key']);

        $items = [];
        foreach ($targetAsins as $asin) {
            $rec = $recordsByAsin[$asin] ?? [
                'asin' => $asin,
                'url' => "https://www.amazon.com/dp/{$asin}",
                'error' => 'Bright Data Dataset API did not return a record for this ASIN.',
            ];
            $items[] = $this->normalizeRecord($rec, $asin, $zip, $creds['default_stock']);
        }

        if ($enrichVariants && !empty($items)) {
            $items = $this->enrichChildVariants($items, $zip, $creds['dataset_id'], $creds['api_key'], $creds['default_stock']);
        }

        // Add any invalid input entries as error items so the user sees feedback
        foreach ($invalidInputs as $bad) {
            $items[] = [
                'asin' => $bad,
                'parent_asin' => null,
                'url' => $bad,
                'title' => "Invalid ASIN / URL: {$bad}",
                'brand' => '',
                'price' => 0.0,
                'list_price' => 0.0,
                'currency' => 'USD',
                'buybox_seller' => null,
                'other_sellers_count' => 0,
                'lowest_other_seller_price' => null,
                'local_grocery_price' => null,
                'availability' => '',
                'is_available' => false,
                'stock' => 0,
                'available' => false,
                'zipcode' => $zip,
                'delivery_status' => 'error',
                'deliverable_to_zip' => false,
                'delivery_reason' => 'Could not extract a valid 10-character Amazon ASIN from this input.',
                'delivery_messages' => [],
                'images' => [],
                'description' => '',
                'features' => [],
                'specifications' => [],
                'weight' => null,
                'weight_unit' => 'kg',
                'width' => null,
                'width_unit' => 'in',
                'height' => null,
                'height_unit' => 'in',
                'rating' => 0.0,
                'review_count' => 0,
                'sku' => "AMZ-{$bad}",
                'variants' => [],
                'raw_record' => ['input' => $bad, 'error' => 'Invalid ASIN/URL'],
            ];
        }

        $summary = [
            'total' => count($items),
            'deliverable' => 0,
            'undeliverable' => 0,
            'local_grocery_only' => 0,
            'no_featured_offer' => 0,
            'out_of_stock' => 0,
            'errors' => 0,
        ];

        foreach ($items as $item) {
            match ($item['delivery_status'] ?? 'error') {
                'deliverable' => $summary['deliverable']++,
                'undeliverable' => $summary['undeliverable']++,
                'local_grocery_only' => $summary['local_grocery_only']++,
                'no_featured_offer' => $summary['no_featured_offer']++,
                'out_of_stock' => $summary['out_of_stock']++,
                default => $summary['errors']++,
            };
        }

        return [
            'zipcode' => $zip,
            'dataset_id' => $creds['dataset_id'],
            'summary' => $summary,
            'items' => $items,
        ];
    }

    /**
     * Fetch records from Bright Data Web Scraper / Amazon Products Dataset API.
     * Supports immediate HTTP 200 (JSON / NDJSON) and async HTTP 202 snapshot polling.
     */
    protected function fetchRecordsFromDataset(array $asins, string $zipcode, string $datasetId, string $apiKey): array
    {
        $payload = array_map(fn($asin) => [
            'url' => "https://www.amazon.com/dp/{$asin}",
            'zipcode' => $zipcode,
        ], array_values($asins));

        $endpoint = "https://api.brightdata.com/datasets/v3/scrape?dataset_id=" . urlencode($datasetId) . "&format=json";

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type' => 'application/json',
        ])->timeout(90)->post($endpoint, $payload);

        $status = $response->status();
        $records = [];

        if ($status === 202) {
            $bodyJson = $response->json();
            $snapshotId = trim((string) ($bodyJson['snapshot_id'] ?? ''));
            if ($snapshotId === '') {
                throw new Exception('Bright Data Dataset API returned HTTP 202 without a snapshot_id.', 502);
            }
            $records = $this->pollSnapshot($snapshotId, $apiKey);
        } elseif ($response->successful()) {
            $records = $this->decodeBrightDataBody($response->body());
        } else {
            throw new Exception(
                "Bright Data Dataset API HTTP {$status}: " . substr($response->body(), 0, 300),
                $status >= 400 ? $status : 502
            );
        }

        $byAsin = [];
        foreach ($records as $rec) {
            if (!is_array($rec)) {
                continue;
            }
            $recAsin = strtoupper(trim((string) ($rec['asin'] ?? '')));
            if ($recAsin === '' && !empty($rec['input']['url'])) {
                $recAsin = $this->extractAsin((string) $rec['input']['url']);
            }
            if ($recAsin === '' && !empty($rec['url'])) {
                $recAsin = $this->extractAsin((string) $rec['url']);
            }
            if ($recAsin !== '') {
                $byAsin[$recAsin] = $rec;
            }
        }

        return $byAsin;
    }

    /**
     * Poll Bright Data async snapshot until ready (up to ~120s) and fetch JSON records.
     */
    protected function pollSnapshot(string $snapshotId, string $apiKey): array
    {
        $progressUrl = "https://api.brightdata.com/datasets/v3/progress/" . urlencode($snapshotId);
        $snapshotUrl = "https://api.brightdata.com/datasets/v3/snapshot/" . urlencode($snapshotId) . "?format=json";

        $maxAttempts = 40; // 40 * 3s = 120s
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            sleep(3);

            $progRes = Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
            ])->timeout(20)->get($progressUrl);

            if (!$progRes->successful()) {
                continue;
            }

            $progData = $progRes->json();
            $state = strtolower((string) ($progData['status'] ?? ''));

            if ($state === 'ready') {
                $dataRes = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $apiKey,
                ])->timeout(60)->get($snapshotUrl);

                if (!$dataRes->successful()) {
                    throw new Exception(
                        "Failed downloading Bright Data snapshot {$snapshotId} (HTTP {$dataRes->status()}).",
                        502
                    );
                }

                return $this->decodeBrightDataBody($dataRes->body());
            }

            if (in_array($state, ['failed', 'cancelled', 'error'], true)) {
                throw new Exception("Bright Data snapshot {$snapshotId} ended with status: {$state}.", 502);
            }
        }

        throw new Exception("Timed out waiting for Bright Data snapshot {$snapshotId} to finish.", 504);
    }

    /**
     * Decode either a JSON array/object or NDJSON (newline-delimited JSON) body.
     */
    protected function decodeBrightDataBody(string $body): array
    {
        $trimmed = trim($body);
        if ($trimmed === '') {
            return [];
        }

        $decoded = json_decode($trimmed, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            if (array_is_list($decoded)) {
                return $decoded;
            }
            if (isset($decoded['data']) && is_array($decoded['data'])) {
                return $decoded['data'];
            }
            return [$decoded];
        }

        // NDJSON fallback
        $lines = preg_split('/\r\n|\r|\n/', $trimmed);
        $records = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $row = json_decode($line, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($row)) {
                $records[] = $row;
            }
        }

        return $records;
    }

    /**
     * Normalize a single Bright Data Dataset record and compute ZIP delivery diagnostics.
     */
    protected function normalizeRecord(array $rec, string $requestedAsin, string $zipcode, int $defaultStock): array
    {
        $asin = strtoupper(trim((string) ($rec['asin'] ?? $requestedAsin))) ?: $requestedAsin;
        $parentAsin = strtoupper(trim((string) ($rec['parent_asin'] ?? ''))) ?: null;
        $title = trim((string) ($rec['title'] ?? $rec['product_name'] ?? ''));
        $brand = trim((string) ($rec['brand'] ?? ''));
        $url = trim((string) ($rec['url'] ?? "https://www.amazon.com/dp/{$asin}"));
        $currency = trim((string) ($rec['currency'] ?? 'USD')) ?: 'USD';

        // Delivery messages (can be array of strings or single string)
        $deliveryMessages = [];
        if (!empty($rec['delivery'])) {
            if (is_array($rec['delivery'])) {
                foreach ($rec['delivery'] as $dLine) {
                    $clean = trim(strip_tags((string) $dLine));
                    if ($clean !== '') {
                        $deliveryMessages[] = $clean;
                    }
                }
            } elseif (is_string($rec['delivery'])) {
                $clean = trim(strip_tags($rec['delivery']));
                if ($clean !== '') {
                    $deliveryMessages[] = $clean;
                }
            }
        }

        $availability = trim((string) ($rec['availability'] ?? ''));
        $warning = trim((string) ($rec['warning'] ?? ''));
        $errorMsg = trim((string) ($rec['error'] ?? $rec['error_code'] ?? ''));
        $buyboxSeller = isset($rec['buybox_seller']) && is_string($rec['buybox_seller'])
            ? trim($rec['buybox_seller'])
            : (is_array($rec['buybox_seller'] ?? null) ? trim((string) ($rec['buybox_seller']['name'] ?? '')) : '');
        if ($buyboxSeller === '') {
            $buyboxSeller = null;
        }
        $sellerName = isset($rec['seller_name']) && is_string($rec['seller_name']) && trim($rec['seller_name']) !== ''
            ? trim($rec['seller_name'])
            : null;
        $shipsFrom = isset($rec['ships_from']) && is_string($rec['ships_from']) && trim($rec['ships_from']) !== ''
            ? trim($rec['ships_from'])
            : null;
        $buyingOptionType = strtoupper(trim((string) ($rec['buying_option_type'] ?? '')));
        $isHighPrice = !empty($rec['is_high_price']);
        $hasInactiveBuyBox = !empty($rec['inactive_buy_box']) || !empty($rec['all_inactive_buy_box']);

        // Other sellers (exclude local AmazonFresh / Whole Foods / ALM grocery offers)
        $otherSellersCount = 0;
        $lowestOtherSellerPrice = null;
        $localGroceryPrice = null;
        if (!empty($rec['other_sellers_prices']) && is_array($rec['other_sellers_prices'])) {
            foreach ($rec['other_sellers_prices'] as $offer) {
                if (is_array($offer)) {
                    $offerPrice = $this->parseNumericPrice($offer['price'] ?? $offer['final_price'] ?? $offer['seller_price'] ?? null);
                    if ($this->isLocalGroceryOffer($offer)) {
                        if ($offerPrice > 0 && ($localGroceryPrice === null || $offerPrice < $localGroceryPrice)) {
                            $localGroceryPrice = $offerPrice;
                        }
                        continue;
                    }
                } else {
                    $offerPrice = $this->parseNumericPrice($offer);
                }
                if ($offerPrice > 0) {
                    $otherSellersCount++;
                    if ($lowestOtherSellerPrice === null || $offerPrice < $lowestOtherSellerPrice) {
                        $lowestOtherSellerPrice = $offerPrice;
                    }
                }
            }
        }

        $price = $this->parseNumericPrice($rec['final_price'] ?? $rec['price'] ?? null);
        $initialPrice = $this->parseNumericPrice($rec['initial_price'] ?? null);
        $listPrice = ($initialPrice > $price && $price > 0) ? $initialPrice : 0.0;

        $deliveryLower = strtolower(implode(' ', $deliveryMessages));
        $availLower = strtolower($availability);
        $warningLower = strtolower($warning);
        $combinedDeliveryText = trim($deliveryLower . ' ' . $availLower . ' ' . $warningLower);

        $cannotShipToZip = (bool) preg_match(
            '/cannot be shipped to your selected delivery location|cannot be delivered to your selected delivery location|cannot be shipped to|not deliverable|doesn\'t ship to|does not ship to/i',
            $combinedDeliveryText
        );

        $explicitOutOfStock = (bool) preg_match(
            '/currently unavailable|out of stock|temporarily out of stock|we don\'t know when or if this item will be back/i',
            $availLower
        );
        $isAvailableFlag = array_key_exists('is_available', $rec) ? (bool) $rec['is_available'] : null;
        $outOfStock = $explicitOutOfStock || ($isAvailableFlag === false && !$cannotShipToZip);

        $isLocalGroceryOnly = $buyingOptionType === 'ALM'
            || (bool) preg_match('/amazon\s*fresh|whole\s*foods/i', trim(($buyboxSeller ?? '') . ' ' . ($sellerName ?? '') . ' ' . ($shipsFrom ?? '')))
            || ($buyboxSeller === null && $sellerName === null && (bool) preg_match('/2-hour delivery|free grocery delivery|amazon\s*fresh|whole\s*foods|orders over \$100/i', $deliveryLower))
            || ($buyboxSeller === null && $sellerName === null && $price > 0 && $localGroceryPrice !== null && abs($price - $localGroceryPrice) < 0.02);

        if ($isLocalGroceryOnly) {
            if ($localGroceryPrice === null && $price > 0) {
                $localGroceryPrice = $price;
            }
            $price = 0.0;
            $listPrice = 0.0;
        }

        $noFeaturedOfferText = str_contains($availLower, 'no featured offers')
            || str_contains($availLower, 'see all buying options')
            || str_contains($availLower, 'high price')
            || str_contains($deliveryLower, 'no featured offers');

        $noFeaturedOffer = $isHighPrice
            || $noFeaturedOfferText
            || ($buyboxSeller === null && $sellerName === null && $hasInactiveBuyBox)
            || ($price <= 0 && $buyboxSeller === null && !$outOfStock && !$cannotShipToZip && $errorMsg === '');

        if ($noFeaturedOffer && !$isLocalGroceryOnly && $buyboxSeller === null && $sellerName === null && $price > 0) {
            if ($lowestOtherSellerPrice === null || $price < $lowestOtherSellerPrice) {
                $lowestOtherSellerPrice = $price;
            }
            $price = 0.0;
            $listPrice = 0.0;
        }

        if ($errorMsg !== '' && $title === '') {
            $deliveryStatus = 'error';
        } elseif ($cannotShipToZip) {
            $deliveryStatus = 'undeliverable';
        } elseif ($outOfStock) {
            $deliveryStatus = 'out_of_stock';
        } elseif ($isLocalGroceryOnly) {
            $deliveryStatus = 'local_grocery_only';
        } elseif ($noFeaturedOffer) {
            $deliveryStatus = 'no_featured_offer';
        } elseif ($price > 0) {
            $deliveryStatus = 'deliverable';
        } else {
            $deliveryStatus = 'no_featured_offer';
        }

        $deliverableToZip = ($deliveryStatus === 'deliverable');

        // Build clear human-readable delivery reason
        $deliveryReason = match ($deliveryStatus) {
            'deliverable' => !empty($deliveryMessages)
                ? $deliveryMessages[0]
                : ($buyboxSeller ? "Ships to ZIP {$zipcode} (Sold by {$buyboxSeller})" : "Featured offer available for ZIP {$zipcode}"),
            'undeliverable' => !empty($deliveryMessages)
                ? implode(' | ', $deliveryMessages)
                : "This item cannot be shipped to ZIP {$zipcode}.",
            'out_of_stock' => $availability !== ''
                ? $availability
                : "Item is currently unavailable / out of stock for ZIP {$zipcode}.",
            'local_grocery_only' => sprintf(
                'AmazonFresh / Local Grocery only for ZIP %s (%s$100+ Prime grocery order required). %s',
                $zipcode,
                $localGroceryPrice ? '$' . number_format($localGroceryPrice, 2) . ', ' : '',
                $otherSellersCount > 0
                    ? "{$otherSellersCount} standard 3rd-party seller(s)" . ($lowestOtherSellerPrice ? ' from $' . number_format($lowestOtherSellerPrice, 2) : '') . '.'
                    : 'No standard shipping Buy Box offer.'
            ),
            'no_featured_offer' => ($isHighPrice ? 'Buy Box suppressed (High price). ' : '')
                . ($otherSellersCount > 0
                    ? "No featured Buy Box offer for ZIP {$zipcode} ({$otherSellersCount} 3rd-party seller(s)" . ($lowestOtherSellerPrice ? ' from $' . number_format($lowestOtherSellerPrice, 2) : '') . ').'
                    : "No featured Buy Box offer available for ZIP {$zipcode} (main Amazon offer may not ship here)."),
            default => $errorMsg !== '' ? $errorMsg : "Failed to fetch dataset record for {$asin}.",
        };

        // Stock estimate
        $stock = 0;
        if ($deliverableToZip) {
            if (preg_match('/only\s+(\d+)\s+left\s+in\s+stock/i', $availability, $sm)) {
                $stock = max(1, (int) $sm[1]);
            } else {
                $stock = $defaultStock;
            }
        }

        // Images (deduplicated)
        $images = [];
        $rawImages = [];
        if (!empty($rec['images']) && is_array($rec['images'])) {
            $rawImages = array_merge($rawImages, $rec['images']);
        }
        foreach (['image_url', 'image', 'main_image'] as $imgKey) {
            if (!empty($rec[$imgKey]) && is_string($rec[$imgKey])) {
                $rawImages[] = $rec[$imgKey];
            }
        }
        foreach ($rawImages as $img) {
            $imgUrl = trim((string) $img);
            if ($imgUrl !== '' && filter_var($imgUrl, FILTER_VALIDATE_URL)) {
                $images[$imgUrl] = true;
            }
        }
        $images = array_keys($images);

        // Features
        $features = [];
        if (!empty($rec['features']) && is_array($rec['features'])) {
            foreach ($rec['features'] as $f) {
                $clean = trim((string) $f);
                if ($clean !== '') {
                    $features[] = $clean;
                }
            }
        }

        // Specifications
        $specifications = [];
        foreach (['product_details', 'product_specifications', 'specifications'] as $specKey) {
            if (!empty($rec[$specKey]) && is_array($rec[$specKey])) {
                foreach ($rec[$specKey] as $k => $v) {
                    if (is_array($v) && isset($v['type'], $v['value'])) {
                        $sk = trim((string) $v['type']);
                        $sv = trim((string) $v['value']);
                        if ($sk !== '' && $sv !== '') {
                            $specifications[$sk] = $sv;
                        }
                    } elseif (is_string($k) && (is_string($v) || is_numeric($v))) {
                        $sk = trim($k);
                        $sv = trim((string) $v);
                        if ($sk !== '' && $sv !== '') {
                            $specifications[$sk] = $sv;
                        }
                    }
                }
            }
        }

        // Description HTML
        $descriptionParts = [];
        $rawDesc = trim((string) ($rec['description'] ?? ''));
        if ($rawDesc !== '') {
            $descriptionParts[] = '<p>' . nl2br(e($rawDesc)) . '</p>';
        }
        if (!empty($features)) {
            $lis = implode('', array_map(fn($f) => '<li>' . e($f) . '</li>', $features));
            $descriptionParts[] = "<ul>{$lis}</ul>";
        }
        if (!empty($specifications)) {
            $rows = '';
            foreach ($specifications as $sk => $sv) {
                $rows .= '<tr><th>' . e($sk) . '</th><td>' . e($sv) . '</td></tr>';
            }
            $descriptionParts[] = "<table><tbody>{$rows}</tbody></table>";
        }
        $descriptionHtml = implode("\n", $descriptionParts);

        // Physical weight & dimensions
        $dims = $this->extractPhysicalDimensions($rec, $specifications);

        // Variations from Dataset record
        $variants = [];
        if (!empty($rec['variations']) && is_array($rec['variations'])) {
            foreach ($rec['variations'] as $idx => $v) {
                if (!is_array($v)) {
                    continue;
                }
                $vAsin = strtoupper(trim((string) ($v['asin'] ?? '')));
                $vTitle = trim((string) ($v['name'] ?? $v['title'] ?? ''));
                $vAttrs = [];
                if (!empty($v['color'])) {
                    $vAttrs['Color'] = trim((string) $v['color']);
                }
                if (!empty($v['size'])) {
                    $vAttrs['Size'] = trim((string) $v['size']);
                }
                if (!empty($v['style'])) {
                    $vAttrs['Style'] = trim((string) $v['style']);
                }
                if (empty($vAttrs) && $vTitle !== '') {
                    $vAttrs['Option'] = $vTitle;
                }
                if ($vTitle === '' && !empty($vAttrs)) {
                    $vTitle = implode(' / ', array_values($vAttrs));
                }
                if ($vTitle === '') {
                    $vTitle = 'Variant ' . ($idx + 1);
                }

                $vPrice = $this->sanitizeVariationPrice(
                    $this->parseNumericPrice($v['price'] ?? $v['final_price'] ?? null),
                    $price
                );
                if ($vPrice <= 0 && $vAsin === $asin && $price > 0) {
                    $vPrice = $price;
                }

                $vImg = trim((string) ($v['image'] ?? $v['image_url'] ?? ''));
                if ($vImg === '' && !empty($images[0])) {
                    $vImg = $images[0];
                }

                $isCurrent = ($vAsin !== '' && $vAsin === $asin);
                $vAvailable = $isCurrent ? $deliverableToZip : false;
                $vDeliveryStatus = $isCurrent ? $deliveryStatus : 'unchecked';

                $variants[] = [
                    'id' => $vAsin ?: ('var_' . ($idx + 1)),
                    'asin' => $vAsin ?: $asin,
                    'title' => $vTitle,
                    'sku' => 'AMZ-' . ($vAsin ?: $asin),
                    'price' => $isCurrent ? $price : $vPrice,
                    'image' => $vImg ?: null,
                    'attributes' => $vAttrs,
                    'available' => $vAvailable,
                    'stock' => $isCurrent ? $stock : 0,
                    'deliverable_to_zip' => $vAvailable,
                    'delivery_status' => $vDeliveryStatus,
                    'lowest_other_seller_price' => $isCurrent ? $lowestOtherSellerPrice : null,
                    'local_grocery_price' => $isCurrent ? $localGroceryPrice : null,
                    'delivery_reason' => $isCurrent
                        ? $deliveryReason
                        : 'Enable "Enrich Child Variants" to verify ZIP deliverability.',
                ];
            }
        }

        if (empty($variants)) {
            $variants[] = [
                'id' => $asin,
                'asin' => $asin,
                'title' => $title !== '' ? 'Default Title' : $asin,
                'sku' => "AMZ-{$asin}",
                'price' => $price,
                'image' => $images[0] ?? null,
                'attributes' => [],
                'available' => $deliverableToZip,
                'stock' => $stock,
                'deliverable_to_zip' => $deliverableToZip,
                'delivery_status' => $deliveryStatus,
                'lowest_other_seller_price' => $lowestOtherSellerPrice,
                'local_grocery_price' => $localGroceryPrice,
                'delivery_reason' => $deliveryReason,
            ];
        }

        $rating = min(5.0, max(0.0, round((float) ($rec['rating'] ?? 0.0), 2)));
        $reviewCount = max(0, (int) ($rec['reviews_count'] ?? $rec['review_count'] ?? 0));

        return [
            'pid' => $asin,
            'asin' => $asin,
            'parent_asin' => $parentAsin,
            'url' => $url,
            'title' => $title !== '' ? $title : "Amazon Product {$asin}",
            'brand' => $brand,
            'vendor' => $brand,
            'price' => $price,
            'cost_price' => $price,
            'list_price' => $listPrice,
            'currency' => $currency,
            'buybox_seller' => $buyboxSeller,
            'other_sellers_count' => $otherSellersCount,
            'lowest_other_seller_price' => $lowestOtherSellerPrice,
            'local_grocery_price' => $localGroceryPrice,
            'availability' => $availability,
            'is_available' => $isAvailableFlag,
            'stock' => $stock,
            'available' => $deliverableToZip,
            'zipcode' => $zipcode,
            'delivery_status' => $deliveryStatus,
            'deliverable_to_zip' => $deliverableToZip,
            'delivery_reason' => $deliveryReason,
            'delivery_messages' => $deliveryMessages,
            'images' => $images,
            'description' => $descriptionHtml,
            'features' => $features,
            'specifications' => $specifications,
            'weight' => $dims['weight'],
            'weight_unit' => $dims['weight_unit'],
            'width' => $dims['width'],
            'width_unit' => $dims['width_unit'],
            'height' => $dims['height'],
            'height_unit' => $dims['height_unit'],
            'is_physical' => true,
            'rating' => $rating,
            'review_count' => $reviewCount,
            'sku' => "AMZ-{$asin}",
            'variants' => $variants,
            'raw_record' => $rec,
        ];
    }

    /**
     * Enrich up to 15 unique child variant ASINs in one Dataset API batch call for the same ZIP.
     */
    protected function enrichChildVariants(
        array $items,
        string $zipcode,
        string $datasetId,
        string $apiKey,
        int $defaultStock
    ): array {
        $childAsins = [];
        $parentAsinSet = [];

        foreach ($items as $item) {
            $parentAsinSet[$item['asin']] = true;
        }

        foreach ($items as $item) {
            foreach ($item['variants'] ?? [] as $v) {
                $vAsin = strtoupper(trim((string) ($v['asin'] ?? '')));
                if (strlen($vAsin) === 10 && !isset($parentAsinSet[$vAsin]) && !isset($childAsins[$vAsin])) {
                    $childAsins[$vAsin] = true;
                    if (count($childAsins) >= 15) {
                        break 2;
                    }
                }
            }
        }

        $normalizedChildren = [];
        foreach ($items as $item) {
            if (!empty($item['asin'])) {
                $normalizedChildren[$item['asin']] = $item;
            }
        }

        if (!empty($childAsins)) {
            try {
                $childRecords = $this->fetchRecordsFromDataset(array_keys($childAsins), $zipcode, $datasetId, $apiKey);
                foreach ($childRecords as $cAsin => $cRec) {
                    $normalizedChildren[$cAsin] = $this->normalizeRecord($cRec, $cAsin, $zipcode, $defaultStock);
                }
            } catch (Exception $e) {
                Log::warning("AmazonDatasetImportService child variant enrichment failed: " . $e->getMessage());
            }
        }

        if (empty($normalizedChildren)) {
            return $items;
        }

        foreach ($items as &$item) {
            foreach ($item['variants'] as &$v) {
                $vAsin = strtoupper(trim((string) ($v['asin'] ?? '')));
                if (isset($normalizedChildren[$vAsin])) {
                    $child = $normalizedChildren[$vAsin];
                    $v['price'] = $child['price'];
                    if (!empty($child['images'][0])) {
                        $v['image'] = $child['images'][0];
                    }
                    $v['available'] = $child['deliverable_to_zip'];
                    $v['stock'] = $child['stock'];
                    $v['deliverable_to_zip'] = $child['deliverable_to_zip'];
                    $v['delivery_status'] = $child['delivery_status'];
                    $v['lowest_other_seller_price'] = $child['lowest_other_seller_price'] ?? null;
                    $v['local_grocery_price'] = $child['local_grocery_price'] ?? null;
                    $v['delivery_reason'] = $child['delivery_reason'] ?? '';
                }
            }
            unset($v);
        }
        unset($item);

        return $items;
    }

    /**
     * Import products into the local database (either from prefetched preview items or freshly fetched).
     */
    public function importProducts(
        array $asinsOrUrls,
        array $options = [],
        ?array $prefetchedItems = null
    ): array {
        $creds = $this->resolveCredentials($options['dataset_id'] ?? null);
        $zip = $this->sanitizeZipcode($options['zipcode'] ?? self::DEFAULT_ZIPCODE);
        $blockUndeliverable = true;

        if (!empty($prefetchedItems) && is_array($prefetchedItems)) {
            $items = $prefetchedItems;
        } else {
            $preview = $this->previewProducts(
                $asinsOrUrls,
                $zip,
                true,
                $creds['dataset_id']
            );
            $items = $preview['items'];
        }

        $imported = [];
        $skipped = [];
        $failed = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $asin = strtoupper(trim((string) ($item['asin'] ?? '')));
            $title = (string) ($item['title'] ?? "Amazon Product {$asin}");
            $deliveryStatus = (string) ($item['delivery_status'] ?? 'error');
            $deliverableToZip = !empty($item['deliverable_to_zip']);

            if ($deliveryStatus === 'error') {
                $failed[] = [
                    'asin' => $asin,
                    'title' => $title,
                    'error' => $item['delivery_reason'] ?? 'Dataset API error.',
                ];
                continue;
            }

            if ($blockUndeliverable && !$deliverableToZip) {
                $skipped[] = [
                    'asin' => $asin,
                    'title' => $title,
                    'delivery_status' => $deliveryStatus,
                    'reason' => $item['delivery_reason'] ?? "Not deliverable to ZIP {$zip}.",
                ];
                continue;
            }

            try {
                $product = $this->persistProduct($item, $options, $creds);
                $imported[] = [
                    'id' => $product->id,
                    'asin' => $asin,
                    'name' => $product->name,
                    'sku' => $product->sku,
                    'price' => (float) $product->price,
                    'stock_quantity' => (int) $product->stock_quantity,
                    'delivery_status' => $deliveryStatus,
                ];
            } catch (Throwable $e) {
                $failed[] = [
                    'asin' => $asin,
                    'title' => $title,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'zipcode' => $zip,
            'total' => count($items),
            'imported_count' => count($imported),
            'skipped_count' => count($skipped),
            'failed_count' => count($failed),
            'imported' => $imported,
            'skipped' => $skipped,
            'failed' => $failed,
        ];
    }

    /**
     * Persist a normalized Dataset API item into Product / ProductImage / ProductVariant tables.
     */
    protected function persistProduct(array $item, array $options, array $creds): Product
    {
        $profitMarginPercent = (float) ($options['profit_margin_percent'] ?? 0.0);
        $markupType = ($options['markup_type'] ?? 'percentage') === 'fixed' ? 'fixed' : 'percentage';
        $fixedMarkupAmount = (float) ($options['fixed_markup_amount'] ?? 0.0);
        $categoryId = !empty($options['category_id']) ? (int) $options['category_id'] : null;
        $defaultStock = !empty($options['default_stock']) ? max(1, (int) $options['default_stock']) : $creds['default_stock'];
        $status = in_array(($options['status'] ?? $creds['default_status']), ['published', 'draft'], true)
            ? ($options['status'] ?? $creds['default_status'])
            : 'published';

        $asin = strtoupper(trim((string) ($item['asin'] ?? '')));
        if ($asin === '') {
            throw new Exception('Missing Amazon ASIN for product persistence.');
        }

        $costPrice = (float) ($item['price'] ?? 0.0);
        if ($costPrice <= 0 && !empty($item['lowest_other_seller_price'])) {
            $costPrice = (float) $item['lowest_other_seller_price'];
        }
        if ($costPrice <= 0 && !empty($item['variants']) && is_array($item['variants'])) {
            $positiveVarPrices = array_values(array_filter(array_map(
                fn($v) => (float) ($v['price'] ?? 0.0),
                $item['variants']
            ), fn($p) => $p > 0));
            if (!empty($positiveVarPrices)) {
                $costPrice = min($positiveVarPrices);
            }
        }

        if ($costPrice <= 0) {
            throw new Exception("Unable to determine a valid price for ASIN {$asin} (status: " . ($item['delivery_status'] ?? 'unknown') . ').');
        }

        $salePrice = ($markupType === 'fixed')
            ? round(max(0, $costPrice + $fixedMarkupAmount), 2)
            : round(max(0, $costPrice * (1 + ($profitMarginPercent / 100))), 2);

        $listCost = (float) ($item['list_price'] ?? 0.0);
        if ($listCost > $costPrice) {
            $originalPrice = ($markupType === 'fixed')
                ? round($listCost + $fixedMarkupAmount, 2)
                : round($listCost * (1 + ($profitMarginPercent / 100)), 2);
        } else {
            $originalPrice = $salePrice;
        }

        return DB::transaction(function () use (
            $item,
            $asin,
            $costPrice,
            $salePrice,
            $originalPrice,
            $markupType,
            $fixedMarkupAmount,
            $profitMarginPercent,
            $categoryId,
            $defaultStock,
            $status,
            $creds
        ) {
            $existingAmazonProduct = Product::where('amazon_asin', $asin)->first();

            if ($existingAmazonProduct && !empty($existingAmazonProduct->slug)) {
                $slug = $existingAmazonProduct->slug;
            } else {
                $titleName = $item['title'] ?? ('amazon-product-' . $asin);
                $baseSlug = Str::limit(Str::slug($titleName), 120, '');
                if ($baseSlug === '') {
                    $baseSlug = 'amazon-product-' . strtolower($asin);
                }
                $slug = $baseSlug;
                while (Product::where('slug', $slug)->where('amazon_asin', '!=', $asin)->exists()) {
                    $slug = $baseSlug . '-' . Str::random(6);
                }
            }

            $targetCategoryId = $categoryId
                ?: ($existingAmazonProduct?->category_id)
                ?: (Category::query()->value('id') ?: 1);

            $brandId = $existingAmazonProduct?->brand_id ?: null;
            $rawBrand = trim((string) ($item['brand'] ?? ''));
            if ($rawBrand !== '' && !in_array(strtolower($rawBrand), ['generic', 'unknown', 'n/a', 'unbranded'], true)) {
                $brandName = Str::limit($rawBrand, 180, '');
                $brandSlug = Str::slug($brandName);
                if ($brandSlug !== '') {
                    $brand = Brand::firstOrCreate(
                        ['slug' => $brandSlug],
                        ['name' => $brandName, 'status' => 'active']
                    );
                    $brandId = $brand->id;
                }
            }

            $variantOptions = $this->buildVariantOptions($item);

            $amazonUrl = $item['url'] ?? "https://www.amazon.com/dp/{$asin}";
            if ($creds['affiliate_tag'] !== '' && !str_contains($amazonUrl, 'tag=')) {
                $separator = str_contains($amazonUrl, '?') ? '&' : '?';
                $amazonUrl .= "{$separator}tag=" . urlencode($creds['affiliate_tag']);
            }

            $resolvedMarkupValue = $markupType === 'fixed' ? $fixedMarkupAmount : $profitMarginPercent;

            $realProductStock = $defaultStock;
            if (!empty($item['variants']) && is_array($item['variants'])) {
                $realProductStock = array_sum(array_map(function ($v) use ($defaultStock) {
                    if (isset($v['available']) && $v['available'] === false) {
                        return 0;
                    }
                    return isset($v['stock']) && is_numeric($v['stock']) ? max(0, (int) $v['stock']) : $defaultStock;
                }, $item['variants']));
            } elseif (isset($item['available']) && $item['available'] === false) {
                $realProductStock = 0;
            } elseif (isset($item['stock']) && is_numeric($item['stock'])) {
                $realProductStock = max(0, (int) $item['stock']);
            }

            $rating = min(5.0, max(0.0, round((float) ($item['rating'] ?? 0.0), 2)));
            $reviewCount = max(0, (int) ($item['review_count'] ?? 0));

            $productPayload = [
                'name' => $item['title'],
                'slug' => $slug,
                'description' => $item['description'] ?? '',
                'short_description' => Str::limit(strip_tags($item['description'] ?? ''), 160),
                'price' => $salePrice,
                'original_price' => $originalPrice,
                'cost_per_item' => $costPrice,
                'sync_markup_type' => $markupType,
                'sync_markup_value' => $resolvedMarkupValue,
                'sku' => $item['sku'] ?? "AMZ-{$asin}",
                'stock_quantity' => $realProductStock,
                'category_id' => $targetCategoryId,
                'brand_id' => $brandId,
                'status' => $status,
                'rating' => $rating,
                'review_count' => $reviewCount,
                'weight' => $item['weight'] ?? null,
                'weight_unit' => $item['weight_unit'] ?? 'kg',
                'width' => $item['width'] ?? null,
                'width_unit' => $item['width_unit'] ?? 'in',
                'height' => $item['height'] ?? null,
                'height_unit' => $item['height_unit'] ?? 'in',
                'variant_options' => $variantOptions,
                'is_physical' => array_key_exists('is_physical', $item) ? (bool) $item['is_physical'] : true,
                'is_amazon_product' => true,
                'import_source' => 'amazon',
                'amazon_asin' => $asin,
                'amazon_url' => $amazonUrl,
                'amazon_synced_at' => now(),
            ];

            try {
                $product = Product::updateOrCreate(['amazon_asin' => $asin], $productPayload);
            } catch (QueryException $e) {
                $existingProduct = Product::where('amazon_asin', $asin)
                    ->orWhere('sku', $productPayload['sku'])
                    ->first();

                if ($existingProduct) {
                    $existingProduct->update([
                        'name' => $item['title'],
                        'description' => $productPayload['description'],
                        'short_description' => $productPayload['short_description'],
                        'price' => $salePrice,
                        'original_price' => $originalPrice,
                        'cost_per_item' => $costPrice,
                        'sync_markup_type' => $markupType,
                        'sync_markup_value' => $resolvedMarkupValue,
                        'stock_quantity' => $realProductStock,
                        'brand_id' => $brandId ?: $existingProduct->brand_id,
                        'rating' => $rating,
                        'review_count' => $reviewCount,
                        'weight' => $productPayload['weight'] ?? $existingProduct->weight,
                        'weight_unit' => $productPayload['weight_unit'],
                        'width' => $productPayload['width'] ?? $existingProduct->width,
                        'width_unit' => $productPayload['width_unit'],
                        'height' => $productPayload['height'] ?? $existingProduct->height,
                        'height_unit' => $productPayload['height_unit'],
                        'variant_options' => $variantOptions ?? $existingProduct->variant_options,
                        'is_physical' => $productPayload['is_physical'],
                        'amazon_synced_at' => now(),
                    ]);
                    $product = $existingProduct;
                } else {
                    $productPayload['slug'] = Str::limit($slug, 90, '') . '-' . uniqid() . '-' . rand(100, 999);
                    $product = Product::create($productPayload);
                }
            }

            if (!empty($item['images']) && is_array($item['images'])) {
                $product->images()->delete();
                foreach (array_values($item['images']) as $index => $imgUrl) {
                    if (!empty($imgUrl)) {
                        ProductImage::create([
                            'product_id' => $product->id,
                            'image_path' => $imgUrl,
                            'is_primary' => $index === 0,
                            'sort_order' => $index,
                        ]);
                    }
                }
            }

            if (!empty($item['variants']) && is_array($item['variants'])) {
                $product->variants()->delete();
                foreach ($item['variants'] as $v) {
                    $vCost = (float) ($v['price'] ?? 0);
                    if ($vCost <= 0 && !empty($v['lowest_other_seller_price'])) {
                        $vCost = (float) $v['lowest_other_seller_price'];
                    }
                    if ($vCost <= 0) {
                        $vCost = $costPrice;
                    }
                    $vPrice = ($markupType === 'fixed')
                        ? round(max(0, $vCost + $fixedMarkupAmount), 2)
                        : round(max(0, $vCost * (1 + ($profitMarginPercent / 100))), 2);
                    if ($vPrice <= 0) {
                        $vPrice = $salePrice;
                    }

                    $vLabel = $v['title'] ?? 'Default Title';
                    $vSku = $v['sku'] ?? ($product->sku . '-' . ($v['id'] ?? Str::random(4)));
                    $vStock = (isset($v['available']) && $v['available'] === false)
                        ? 0
                        : (isset($v['stock']) && is_numeric($v['stock']) ? max(0, (int) $v['stock']) : $defaultStock);

                    $isDefaultPlaceholder = in_array(strtolower(trim($vLabel)), ['default', 'default title', 'default variant'], true);
                    $vColor = $v['attributes']['Color']
                        ?? $v['attributes']['Option']
                        ?? (isset($v['attributes']['Size']) || $isDefaultPlaceholder ? null : $vLabel);

                    ProductVariant::create([
                        'product_id' => $product->id,
                        'combination_label' => $vLabel,
                        'price' => $vPrice,
                        'discount_price' => null,
                        'sku' => $vSku,
                        'stock' => $vStock,
                        'available_quantity' => $vStock,
                        'color' => $vColor,
                        'size' => $v['attributes']['Size'] ?? null,
                        'image' => $v['image'] ?? null,
                        'amazon_asin' => $v['asin'] ?? $asin,
                        'amazon_variant_sku' => $vSku,
                    ]);
                }
            }

            return $product;
        });
    }

    /**
     * Build variant_options JSON from normalized variants.
     */
    protected function buildVariantOptions(array $item): ?array
    {
        if (empty($item['variants']) || !is_array($item['variants'])) {
            return null;
        }

        $optionsCollector = [];
        foreach ($item['variants'] as $v) {
            if (empty($v['attributes']) || !is_array($v['attributes'])) {
                continue;
            }
            foreach ($v['attributes'] as $optName => $val) {
                $cleanOpt = trim((string) $optName);
                $cleanVal = trim((string) $val);
                if ($cleanOpt !== '' && $cleanVal !== '') {
                    $optionsCollector[ucfirst($cleanOpt)][$cleanVal] = true;
                }
            }
        }

        if (empty($optionsCollector)) {
            return null;
        }

        $formatted = [];
        foreach ($optionsCollector as $optName => $valsMap) {
            $vals = array_values(array_keys($valsMap));
            $formatted[] = [
                'name' => ucfirst($optName),
                'values' => $vals,
            ];
        }

        return $formatted;
    }

    /**
     * Detect whether an offer in other_sellers_prices is an AmazonFresh / Whole Foods local grocery offer.
     */
    protected function isLocalGroceryOffer(array $offer): bool
    {
        $haystack = strtolower(trim(
            (string) ($offer['seller_name'] ?? '') . ' ' .
            (string) ($offer['ships_from'] ?? '') . ' ' .
            (string) ($offer['seller_url'] ?? '') . ' ' .
            (string) ($offer['delivery'] ?? '')
        ));

        return (bool) preg_match(
            '/amazon\s*fresh|whole\s*foods|alm\/storefront|2-hour delivery|free grocery delivery|orders over \$100/i',
            $haystack
        );
    }

    /**
     * Sanitize Bright Data double-concatenated variation prices (e.g. 3993.99 -> 3.99, 8998.99 -> 8.99).
     */
    protected function sanitizeVariationPrice(float $vPrice, float $referencePrice = 0.0): float
    {
        if ($vPrice >= 500 && ($referencePrice <= 0 || $vPrice > $referencePrice * 10)) {
            $digits = str_replace('.', '', number_format($vPrice, 2, '.', ''));
            $len = strlen($digits);
            if ($len >= 6 && $len % 2 === 0) {
                $half = intdiv($len, 2);
                $h1 = substr($digits, 0, $half);
                $h2 = substr($digits, $half);
                if ($h1 === $h2) {
                    return round(((float) $h1) / 100, 2);
                }
            }
        }

        return $vPrice;
    }

    /**
     * Parse numeric price safely from float, int, or formatted string.
     */
    protected function parseNumericPrice($raw): float
    {
        if ($raw === null || $raw === '' || is_bool($raw)) {
            return 0.0;
        }
        if (is_int($raw) || is_float($raw)) {
            $val = (float) $raw;
            return $val > 0 ? round($val, 2) : 0.0;
        }
        if (is_string($raw) && preg_match('/[\d,]+(?:\.\d+)?/', $raw, $m)) {
            $val = (float) str_replace(',', '', $m[0]);
            return $val > 0 ? round($val, 2) : 0.0;
        }
        return 0.0;
    }

    /**
     * Extract physical weight/dimensions from Bright Data Dataset fields.
     */
    protected function extractPhysicalDimensions(array $rec, array $specifications): array
    {
        $weight = null;
        $weightUnit = 'kg';
        $width = null;
        $widthUnit = 'in';
        $height = null;
        $heightUnit = 'in';

        $rawWeight = trim((string) ($rec['item_weight'] ?? $specifications['Item Weight'] ?? $specifications['Package Weight'] ?? ''));
        if ($rawWeight !== '' && preg_match('/([\d.]+)\s*(ounces?|oz|pounds?|lbs?|kilograms?|kgs?|grams?|g)\b/i', $rawWeight, $wm)) {
            $val = (float) $wm[1];
            $unit = strtolower($wm[2]);
            if (str_starts_with($unit, 'oz') || str_starts_with($unit, 'ounce')) {
                $weight = round($val * 0.0283495, 3);
            } elseif (str_starts_with($unit, 'lb') || str_starts_with($unit, 'pound')) {
                $weight = round($val * 0.453592, 3);
            } elseif ($unit === 'g' || str_starts_with($unit, 'gram')) {
                $weight = round($val / 1000, 3);
            } else {
                $weight = round($val, 3);
            }
        }

        $rawDims = trim((string) ($rec['product_dimensions'] ?? $specifications['Product Dimensions'] ?? $specifications['Package Dimensions'] ?? ''));
        if ($rawDims !== '' && preg_match('/([\d.]+)\s*[x×]\s*([\d.]+)\s*[x×]\s*([\d.]+)/i', $rawDims, $dm)) {
            $width = round((float) $dm[2], 2);
            $height = round((float) $dm[3], 2);
            if (preg_match('/\b(cm|centimeters?)\b/i', $rawDims)) {
                $widthUnit = 'cm';
                $heightUnit = 'cm';
            }
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
}
