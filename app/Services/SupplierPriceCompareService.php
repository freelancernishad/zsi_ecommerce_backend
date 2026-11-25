<?php

namespace App\Services;

use AliExpress\Services\AliExpressService;
use Amazon\Services\AmazonScraperService;
use App\Models\SiteSetting;
use App\Models\SystemSetting;
use CjDropshipping\Services\CjDropshippingService;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SupplierPriceCompareService
{
    /** @var string[] High-quota multimodal Vision models ordered by daily quota & speed */
    protected array $visionModels = [
        'gemini-3.5-flash-lite',
        'gemini-3.1-flash-lite',
        'gemini-3.8-flash',
        'gemini-3.6-flash',
        'gemini-2.5-flash-lite',
    ];

    /** @var string[] High-quota fast text models first, with Gemma 4 & 2.5 backup */
    protected array $textModels = [
        'gemini-3.5-flash-lite',
        'gemini-3.1-flash-lite',
        'gemma-4-31b-it',
        'gemma-4-26b-a4b-it',
        'gemini-2.5-flash-lite',
    ];

    public function __construct(
        protected AliExpressService $aliExpressService,
        protected AmazonScraperService $amazonScraperService,
        protected CjDropshippingService $cjService,
        protected GeminiAIService $geminiService
    ) {
    }

    /**
     * Compare a product across AliExpress, CJ Dropshipping, and Amazon.
     * Every optional intelligence step (Gemini Vision, GD Visual, size matching) skips cleanly on error.
     */
    public function compare(array $input): array
    {
        $sourceSupplier = (string) ($input['source_supplier'] ?? 'aliexpress');
        $productId = isset($input['product_id']) ? trim((string) $input['product_id']) : null;
        $title = trim((string) ($input['title'] ?? ''));
        $sourcePrice = $this->parsePrice($input['price'] ?? null);
        $image = isset($input['image']) ? trim((string) $input['image']) : null;
        $url = isset($input['url']) ? trim((string) $input['url']) : null;
        $brand = isset($input['brand']) ? trim((string) $input['brand']) : null;
        $customQuery = isset($input['custom_query']) ? trim((string) $input['custom_query']) : '';
        $limit = max(1, min(25, (int) ($input['limit'] ?? 12)));
        $page = max(1, min(5, (int) ($input['page'] ?? 1)));
        $refresh = (bool) ($input['refresh'] ?? false);

        $activeFeatures = ['size_detection', 'quality_warnings'];
        $skippedFeatures = [];
        $aiModelUsed = null;

        $geminiKey = $this->getGeminiApiKey();
        if ($geminiKey === null) {
            $skippedFeatures[] = [
                'feature' => 'gemini_ai',
                'reason' => 'Gemini API key not configured',
            ];
        }

        // Pre-download source image once so both Gemini Vision OCR and GD Visual Matching reuse it
        $sourceImageData = $this->fetchImageBytes($image);

        // Build Smart Search Query (Gemma 4 / Vision OCR -> Fallback)
        $queryInfo = $this->buildSmartSearchQuery(
            $title,
            $brand,
            $customQuery,
            $geminiKey,
            $sourceImageData,
            $activeFeatures,
            $skippedFeatures,
            $aiModelUsed
        );
        $query = $queryInfo['query'];
        $querySource = $queryInfo['source'];

        if (!empty($queryInfo['ocr_brand']) && (empty($brand) || strtolower($brand) === 'generic')) {
            $brand = $queryInfo['ocr_brand'];
        }

        $sourceMeasurements = $this->extractMeasurements(
            trim($title . ' ' . ($queryInfo['ocr_size'] ?? ''))
        );

        $sourceItem = [
            'supplier' => $sourceSupplier,
            'product_id' => $productId ?: null,
            'title' => $title,
            'price' => $sourcePrice,
            'currency' => 'USD',
            'shipping' => null,
            'total_cost' => $sourcePrice,
            'free_shipping' => false,
            'delivery_estimate' => null,
            'image' => $image ?: null,
            'url' => $url ?: null,
            'store_name' => $this->supplierLabel($sourceSupplier),
            'rating' => null,
            'available' => true,
            'stock' => null,
            'match_score' => 100,
            'lexical_score' => 100,
            'visual_score' => 100,
            'ai_verified' => false,
            'detected_size' => $sourceMeasurements['label'] ?? null,
            'unit_price_label' => $this->formatUnitPrice($sourcePrice, $sourceMeasurements),
            'size_warning' => null,
            'quality_warning' => null,
            'match_reason' => 'Current product',
            'is_source' => true,
            'is_lowest_cost' => false,
            'is_best_value' => false,
            'details_checked' => false,
        ];

        $cacheKey = 'price_compare_result_v5:' . md5(json_encode([
            $sourceSupplier,
            $productId,
            strtolower($query),
            $limit,
            $page,
            $customQuery !== '',
            $image ?: '',
            $geminiKey !== null,
        ]));

        if (!$refresh && Cache::has($cacheKey)) {
            $cachedResult = Cache::get($cacheKey);
            if (is_array($cachedResult)) {
                $cachedResult['cached'] = true;
                return $cachedResult;
            }
        }

        $suppliers = [];

        foreach (['aliexpress', 'cj', 'amazon'] as $supplier) {
            // Only treat a supplier as "source" when comparing directly from that supplier on page 1 without custom query
            if ($supplier === $sourceSupplier && $customQuery === '' && $page === 1) {
                $suppliers[$supplier] = [
                    'supplier' => $supplier,
                    'label' => $this->supplierLabel($supplier),
                    'status' => 'source',
                    'scanned_count' => 1,
                    'used_query' => null,
                    'best_match' => $sourceItem,
                    'items' => [$sourceItem],
                    'error' => null,
                ];
                continue;
            }

            try {
                $fetchSize = match ($supplier) {
                    'amazon' => 60,
                    'aliexpress' => 25,
                    'cj' => 25,
                    default => 25,
                };

                $searchOutcome = $this->searchSupplierWithFallback($supplier, $query, $fetchSize, $page);
                $rawItems = $searchOutcome['items'];
                $usedQuery = $searchOutcome['used_query'];

                $normalized = [];
                foreach ($rawItems as $rawItem) {
                    $item = $this->normalizeCandidate($supplier, $rawItem);
                    if ($item === null) {
                        continue;
                    }

                    $lex = $this->computeLexicalMatchScore($title, $item['title'], $brand);
                    $candMeas = $this->extractMeasurements($item['title']);
                    $measComparison = $this->compareMeasurements($sourceMeasurements, $candMeas);

                    $score = $lex['score'];
                    if ($measComparison['status'] === 'match') {
                        $score = min(99, $score + 8);
                    } elseif ($measComparison['status'] === 'mismatch') {
                        $score = max(0, $score - 8);
                    }

                    $item['lexical_score'] = $lex['score'];
                    $item['match_score'] = $score;
                    $item['match_reason'] = $lex['reason'];
                    $item['detected_size'] = $candMeas['label'] ?? null;
                    $item['size_matched'] = ($measComparison['status'] === 'match');
                    $item['unit_price_label'] = $this->formatUnitPrice($item['total_cost'], $candMeas);
                    $item['size_warning'] = $measComparison['warning'];
                    $item['quality_warning'] = $this->computeQualityWarning($item);

                    $normalized[] = $item;
                }

                $scannedCount = count($normalized);

                usort($normalized, function (array $a, array $b) {
                    if ($a['lexical_score'] !== $b['lexical_score']) {
                        return $b['lexical_score'] <=> $a['lexical_score'];
                    }
                    return $this->compareNullableCost($a['total_cost'], $b['total_cost']);
                });

                // Shortlist up to 24 candidates per supplier: top 18 lexical + up to 6 lowest-priced remaining items
                $shortlist = array_slice($normalized, 0, 18);
                $pickedIds = [];
                foreach ($shortlist as $cand) {
                    $pickedIds[$cand['product_id']] = true;
                }
                $remainingByPrice = array_values(array_filter(
                    $normalized,
                    fn (array $cand) => !isset($pickedIds[$cand['product_id']]) && $cand['total_cost'] !== null
                ));
                usort($remainingByPrice, fn (array $a, array $b) => $this->compareNullableCost($a['total_cost'], $b['total_cost']));
                foreach (array_slice($remainingByPrice, 0, 6) as $extra) {
                    $shortlist[] = $extra;
                }

                $suppliers[$supplier] = [
                    'supplier' => $supplier,
                    'label' => $this->supplierLabel($supplier),
                    'status' => empty($shortlist) ? 'empty' : 'found',
                    'scanned_count' => $scannedCount,
                    'used_query' => $usedQuery,
                    'best_match' => null,
                    'items' => $shortlist,
                    'error' => null,
                ];
            } catch (\Throwable $e) {
                Log::warning("Price comparison search failed for {$supplier}", [
                    'query' => $query,
                    'page' => $page,
                    'error' => $e->getMessage(),
                ]);

                $suppliers[$supplier] = [
                    'supplier' => $supplier,
                    'label' => $this->supplierLabel($supplier),
                    'status' => 'error',
                    'scanned_count' => 0,
                    'used_query' => $query,
                    'best_match' => null,
                    'items' => [],
                    'error' => $e->getMessage(),
                ];
            }
        }

        // Visual image similarity scoring via PHP GD (downloads candidate thumbnails once)
        $downloadedImages = $this->applyVisualMatchScores(
            $sourceImageData,
            $suppliers,
            $activeFeatures,
            $skippedFeatures
        );

        // Optional Gemini Vision Multimodal Verification (or text-only refinement)
        if ($geminiKey !== null) {
            $visionVerified = false;
            if ($sourceImageData !== null) {
                $visionVerified = $this->verifyTopMatchesWithGeminiVision(
                    $geminiKey,
                    $title,
                    $brand,
                    $sourceImageData,
                    $suppliers,
                    $downloadedImages,
                    $activeFeatures,
                    $skippedFeatures,
                    $aiModelUsed
                );
            }

            if (!$visionVerified) {
                $this->refineMatchScoresWithGeminiText(
                    $geminiKey,
                    $title,
                    $brand,
                    $suppliers,
                    $activeFeatures,
                    $skippedFeatures,
                    $aiModelUsed
                );
            }
        }

        // Final sort per bucket, slice to $limit, and select best_match
        foreach ($suppliers as $supplierKey => &$bucket) {
            if ($bucket['status'] === 'source') {
                continue;
            }
            if (empty($bucket['items'])) {
                $bucket['best_match'] = null;
                continue;
            }

            usort($bucket['items'], function (array $a, array $b) {
                if (($a['ai_verified'] ?? false) !== ($b['ai_verified'] ?? false)) {
                    return ($b['ai_verified'] ?? false) <=> ($a['ai_verified'] ?? false);
                }
                if ($a['match_score'] !== $b['match_score']) {
                    return $b['match_score'] <=> $a['match_score'];
                }
                $visA = $a['visual_score'] ?? -1;
                $visB = $b['visual_score'] ?? -1;
                if ($visA !== $visB) {
                    return $visB <=> $visA;
                }
                return $this->compareNullableCost($a['total_cost'], $b['total_cost']);
            });

            $bucket['items'] = array_slice($bucket['items'], 0, $limit);

            $relevant = array_values(array_filter(
                $bucket['items'],
                fn (array $item) => ($item['match_score'] ?? 0) >= 35
            ));
            $bucket['best_match'] = $relevant[0] ?? $bucket['items'][0];
        }
        unset($bucket);

        $summary = $this->computeSummary($sourceSupplier, $sourceItem, $suppliers);

        $result = [
            'search_query' => $query,
            'query_source' => $querySource,
            'page' => $page,
            'cached' => false,
            'checked_at' => now()->toIso8601String(),
            'ai_model_used' => $aiModelUsed,
            'active_features' => array_values(array_unique($activeFeatures)),
            'skipped_features' => $skippedFeatures,
            'source' => $sourceItem,
            'summary' => $summary,
            'suppliers' => $suppliers,
        ];

        Cache::put($cacheKey, $result, now()->addMinutes(30));

        return $result;
    }

    /**
     * On-demand live check for verified price, shipping cost, and stock for a single supplier item.
     * Fault-tolerant: returns partial details + warning instead of throwing on upstream errors.
     */
    public function checkCandidateDetails(string $supplier, string $productId, string $countryCode = 'US'): array
    {
        $country = strtoupper(trim($countryCode ?: 'US'));
        $verifiedPrice = null;
        $shipping = null;
        $deliveryEstimate = null;
        $available = null;
        $stock = null;
        $rating = null;
        $variantsCount = null;
        $warnings = [];

        if ($supplier === 'cj') {
            try {
                $detail = $this->cjService->getProductDetail($productId);
                if (!empty($detail) && is_array($detail)) {
                    $verifiedPrice = $this->parsePrice($detail['sellPrice'] ?? $detail['productPrice'] ?? null);
                    $variants = is_array($detail['variants'] ?? null) ? $detail['variants'] : [];
                    $variantsCount = count($variants);
                    $available = true;

                    $firstVid = null;
                    foreach ($variants as $v) {
                        if (!empty($v['vid'])) {
                            $firstVid = (string) $v['vid'];
                            if ($verifiedPrice === null) {
                                $verifiedPrice = $this->parsePrice($v['variantSellPrice'] ?? null);
                            }
                            break;
                        }
                    }

                    if ($firstVid) {
                        try {
                            $freightOptions = $this->cjService->calculateShipping($country, $firstVid, 1);
                            if (is_array($freightOptions) && !empty($freightOptions)) {
                                $lowestShip = null;
                                $bestAging = null;
                                foreach ($freightOptions as $opt) {
                                    $shipCost = $this->parsePrice($opt['logisticPrice'] ?? null);
                                    if ($shipCost !== null && ($lowestShip === null || $shipCost < $lowestShip)) {
                                        $lowestShip = $shipCost;
                                        $bestAging = $opt['logisticAging'] ?? null;
                                    }
                                }
                                if ($lowestShip !== null) {
                                    $shipping = $lowestShip;
                                    if ($bestAging) {
                                        $deliveryEstimate = is_string($bestAging) ? "{$bestAging} days" : null;
                                    }
                                }
                            } else {
                                $warnings[] = 'CJ shipping options unavailable for this destination';
                            }
                        } catch (\Throwable $e) {
                            $warnings[] = 'CJ shipping lookup skipped: ' . $e->getMessage();
                        }
                    } else {
                        $warnings[] = 'No CJ variant ID found for shipping calculation';
                    }
                } else {
                    $warnings[] = 'CJ product detail returned empty';
                }
            } catch (\Throwable $e) {
                $warnings[] = 'CJ detail check skipped: ' . $e->getMessage();
            }
        } elseif ($supplier === 'amazon') {
            try {
                $detail = $this->amazonScraperService->getProductDetail($productId);
                $verifiedPrice = $this->parsePrice($detail['price'] ?? $detail['cost_price'] ?? null);
                $available = isset($detail['available']) ? (bool) $detail['available'] : null;
                $stock = isset($detail['stock']) ? (int) $detail['stock'] : null;
                $variantsCount = is_array($detail['variants'] ?? null) ? count($detail['variants']) : null;
            } catch (\Throwable $e) {
                $warnings[] = 'Amazon detail check skipped: ' . $e->getMessage();
            }
        } elseif ($supplier === 'aliexpress') {
            try {
                $detail = $this->aliExpressService->getProductDetail($productId);
                $verifiedPrice = $this->parsePrice($detail['costPrice'] ?? $detail['price'] ?? null);
                $stock = isset($detail['stock']) ? (int) $detail['stock'] : null;
                $available = $stock !== null ? ($stock > 0) : null;
                $rating = isset($detail['rating']) ? (float) $detail['rating'] : null;
                $variantsCount = is_array($detail['variants'] ?? null) ? count($detail['variants']) : null;
                $warnings[] = 'AliExpress API does not expose live freight quote; showing verified item cost & stock';
            } catch (\Throwable $e) {
                $warnings[] = 'AliExpress detail check skipped: ' . $e->getMessage();
            }
        }

        $totalCost = null;
        if ($verifiedPrice !== null) {
            $totalCost = round($verifiedPrice + ($shipping ?? 0.0), 2);
        }

        return [
            'supplier' => $supplier,
            'product_id' => $productId,
            'country_code' => $country,
            'verified_price' => $verifiedPrice,
            'shipping' => $shipping,
            'total_cost' => $totalCost,
            'delivery_estimate' => $deliveryEstimate,
            'available' => $available,
            'stock' => $stock,
            'rating' => $rating,
            'variants_count' => $variantsCount,
            'checked_at' => now()->toIso8601String(),
            'warning' => !empty($warnings) ? implode(' · ', $warnings) : null,
        ];
    }

    /**
     * Extract volume, weight, or pack-count measurements from a title/OCR string.
     */
    public function extractMeasurements(string $text): array
    {
        $result = [
            'dimension' => null,
            'normalized_value' => null,
            'unit' => null,
            'label' => null,
        ];

        $lower = strtolower($text);

        // 1. Volume: fl oz, ml, liter
        if (preg_match('/(\d+(?:\.\d+)?)\s*(?:fl\.?\s*oz|fluid\s*ounces?)\b/i', $lower, $m)) {
            $val = (float) $m[1];
            return [
                'dimension' => 'volume',
                'normalized_value' => round($val * 29.5735, 2),
                'unit' => 'fl oz',
                'raw_value' => $val,
                'label' => "{$m[1]} fl oz",
            ];
        }
        if (preg_match('/(\d+(?:\.\d+)?)\s*ml\b/i', $lower, $m)) {
            $val = (float) $m[1];
            return [
                'dimension' => 'volume',
                'normalized_value' => round($val, 2),
                'unit' => 'ml',
                'raw_value' => $val,
                'label' => "{$m[1]} ml",
            ];
        }
        if (preg_match('/(\d+(?:\.\d+)?)\s*(?:liters?|litres?|l)\b/i', $lower, $m)) {
            $val = (float) $m[1];
            if ($val > 0 && $val <= 50) {
                return [
                    'dimension' => 'volume',
                    'normalized_value' => round($val * 1000, 2),
                    'unit' => 'L',
                    'raw_value' => $val,
                    'label' => "{$m[1]} L",
                ];
            }
        }

        // 2. Weight: oz, lb, kg, g
        if (preg_match('/(\d+(?:\.\d+)?)\s*(?:oz|ounces?)\b/i', $lower, $m)) {
            $val = (float) $m[1];
            return [
                'dimension' => 'weight',
                'normalized_value' => round($val * 28.3495, 2),
                'unit' => 'oz',
                'raw_value' => $val,
                'label' => "{$m[1]} oz",
            ];
        }
        if (preg_match('/(\d+(?:\.\d+)?)\s*(?:lbs?|pounds?)\b/i', $lower, $m)) {
            $val = (float) $m[1];
            return [
                'dimension' => 'weight',
                'normalized_value' => round($val * 453.592, 2),
                'unit' => 'lb',
                'raw_value' => $val,
                'label' => "{$m[1]} lb",
            ];
        }
        if (preg_match('/(\d+(?:\.\d+)?)\s*kg\b/i', $lower, $m)) {
            $val = (float) $m[1];
            return [
                'dimension' => 'weight',
                'normalized_value' => round($val * 1000, 2),
                'unit' => 'kg',
                'raw_value' => $val,
                'label' => "{$m[1]} kg",
            ];
        }
        if (preg_match('/(\d+(?:\.\d+)?)\s*(?:g|grams?)\b/i', $lower, $m)) {
            $val = (float) $m[1];
            if ($val >= 5) {
                return [
                    'dimension' => 'weight',
                    'normalized_value' => round($val, 2),
                    'unit' => 'g',
                    'raw_value' => $val,
                    'label' => "{$m[1]} g",
                ];
            }
        }

        // 3. Pack / Piece / Count
        if (preg_match('/(\d+)\s*[-\s]?(?:pack|packs|pcs|pieces|count|ct|capsules|softgels|chews|tablets)\b/i', $lower, $m)) {
            $val = (int) $m[1];
            if ($val >= 2 && $val <= 1000) {
                return [
                    'dimension' => 'count',
                    'normalized_value' => (float) $val,
                    'unit' => 'pack',
                    'raw_value' => (float) $val,
                    'label' => "{$val} Pack",
                ];
            }
        }
        if (preg_match('/pack\s+of\s+(\d+)\b/i', $lower, $m)) {
            $val = (int) $m[1];
            if ($val >= 2 && $val <= 1000) {
                return [
                    'dimension' => 'count',
                    'normalized_value' => (float) $val,
                    'unit' => 'pack',
                    'raw_value' => (float) $val,
                    'label' => "{$val} Pack",
                ];
            }
        }

        return $result;
    }

    /**
     * Compare source vs candidate measurements to detect size/pack mismatches.
     */
    public function compareMeasurements(array $sourceMeas, array $candMeas): array
    {
        if (empty($sourceMeas['normalized_value']) || empty($candMeas['normalized_value'])) {
            return [
                'status' => 'unknown',
                'warning' => null,
            ];
        }

        $srcDim = $sourceMeas['dimension'];
        $candDim = $candMeas['dimension'];

        // Allow comparing volume (ml) vs fluid/weight oz loosely when bottles list "oz" instead of "fl oz"
        $srcVal = (float) $sourceMeas['normalized_value'];
        $candVal = (float) $candMeas['normalized_value'];

        $compatible = ($srcDim === $candDim)
            || (($srcDim === 'volume' && $candDim === 'weight') || ($srcDim === 'weight' && $candDim === 'volume'));

        if (!$compatible || $srcVal <= 0 || $candVal <= 0) {
            return [
                'status' => 'unknown',
                'warning' => null,
            ];
        }

        $ratio = max($srcVal, $candVal) / min($srcVal, $candVal);

        if ($ratio <= 1.22) {
            return [
                'status' => 'match',
                'warning' => null,
            ];
        }

        if ($ratio >= 1.35) {
            return [
                'status' => 'mismatch',
                'warning' => "Size differs: {$sourceMeas['label']} vs {$candMeas['label']}",
            ];
        }

        return [
            'status' => 'unknown',
            'warning' => null,
        ];
    }

    protected function formatUnitPrice(?float $price, array $meas): ?string
    {
        if ($price === null || $price <= 0 || empty($meas['raw_value']) || empty($meas['unit'])) {
            return null;
        }
        $raw = (float) $meas['raw_value'];
        if ($raw <= 0) {
            return null;
        }
        $perUnit = $price / $raw;
        return '$' . number_format($perUnit, 2) . ' / ' . $meas['unit'];
    }

    protected function computeQualityWarning(array $item): ?string
    {
        if (isset($item['available']) && $item['available'] === false) {
            return 'Out of stock';
        }
        if (isset($item['rating']) && $item['rating'] !== null && $item['rating'] > 0 && $item['rating'] < 4.0) {
            return 'Low rating (' . number_format((float) $item['rating'], 1) . '★)';
        }
        return null;
    }

    protected function getGeminiApiKey(): ?string
    {
        try {
            $siteKey = SiteSetting::getValue('gemini_api_key');
            if (!empty($siteKey)) {
                return trim((string) $siteKey);
            }
            $systemKey = SystemSetting::getValue('gemini_api_key');
            if (!empty($systemKey)) {
                return trim((string) $systemKey);
            }
        } catch (\Throwable $e) {
            // Fall through to config
        }

        $envKey = config('services.gemini.key');
        return !empty($envKey) ? trim((string) $envKey) : null;
    }

    /**
     * Fetch image bytes + mime type safely. Returns null on any failure.
     */
    protected function fetchImageBytes(?string $url): ?array
    {
        if (empty($url) || !str_starts_with($url, 'http')) {
            return null;
        }

        try {
            $resp = Http::withOptions(['verify' => false])
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                    'Accept' => 'image/webp,image/jpeg,image/png,image/*;q=0.8',
                ])
                ->timeout(5)
                ->get($url);

            if (!$resp->successful()) {
                return null;
            }

            $bytes = $resp->body();
            if (strlen($bytes) < 100 || strlen($bytes) > 4 * 1024 * 1024) {
                return null;
            }

            $mime = $resp->header('Content-Type') ?: 'image/jpeg';
            $mime = explode(';', $mime)[0];
            if (!str_starts_with($mime, 'image/')) {
                $mime = 'image/jpeg';
            }

            return [
                'bytes' => $bytes,
                'mime' => $mime,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Execute a Gemini/Gemma generateContent call across a prioritized model list,
     * automatically skipping cooldown models and rotating on 429 rate/quota limits.
     */
    protected function callGeminiModelsWithFallback(string $apiKey, array $models, array $payload, int $timeout = 12): ?array
    {
        foreach ($models as $model) {
            $cooldownKey = "gemini_model_cooldown:{$model}";
            if (Cache::has($cooldownKey)) {
                continue;
            }

            try {
                $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . $apiKey;
                $resp = Http::withHeaders(['Content-Type' => 'application/json'])
                    ->withOptions([
                        'curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4],
                    ])
                    ->timeout($timeout)
                    ->post($url, $payload);

                if ($resp->successful()) {
                    $text = $resp->json()['candidates'][0]['content']['parts'][0]['text'] ?? null;
                    if (!empty($text)) {
                        return [
                            'text' => (string) $text,
                            'model' => $model,
                        ];
                    }
                    continue;
                }

                $status = $resp->status();
                $body = $resp->body();
                if ($status === 429) {
                    $isDaily = str_contains($body, 'PerDay')
                        || str_contains(strtolower($body), 'daily')
                        || str_contains($body, 'RESOURCE_EXHAUSTED');
                    Cache::put($cooldownKey, true, now()->addMinutes($isDaily ? 45 : 1));
                    Log::warning("Gemini model {$model} hit 429 quota/rate limit; switching to next model.");
                    continue;
                }

                if ($status === 404) {
                    Cache::put($cooldownKey, true, now()->addHours(12));
                } elseif ($status === 503 || $status >= 500) {
                    Cache::put($cooldownKey, true, now()->addMinutes(3));
                }

                Log::warning("Gemini model {$model} returned HTTP {$status}; trying next model.", [
                    'body' => mb_substr($body, 0, 220),
                ]);
            } catch (\Throwable $e) {
                Cache::put($cooldownKey, true, now()->addMinutes(3));
                $safeMsg = preg_replace('/key=[^&\s)]+/i', 'key=***', $e->getMessage());
                Log::warning("Gemini model {$model} call failed ({$safeMsg}); trying next model.");
            }
        }

        return null;
    }

    /**
     * Build a clean search query via Gemma 4 / Gemini Vision OCR -> Rule-Based Fallback.
     */
    protected function buildSmartSearchQuery(
        string $title,
        ?string $brand,
        string $customQuery,
        ?string $geminiKey,
        ?array $sourceImageData,
        array &$activeFeatures,
        array &$skippedFeatures,
        ?string &$aiModelUsed = null
    ): array {
        if ($customQuery !== '') {
            return [
                'query' => $customQuery,
                'source' => 'custom',
                'ocr_brand' => null,
                'ocr_size' => null,
            ];
        }

        $cacheKey = 'price_compare_query_v5:' . md5($title . '|' . ($brand ?? '') . '|' . ($geminiKey !== null ? '1' : '0') . '|' . ($sourceImageData !== null ? 'img' : 'noimg'));
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && !empty($cached['query'])) {
            if ($cached['source'] === 'ai') {
                $activeFeatures[] = 'gemini_query';
                if (!empty($cached['model'])) {
                    $aiModelUsed = $cached['model'];
                }
            }
            return $cached;
        }

        if ($geminiKey !== null) {
            $hasTitleSize = !empty(($this->extractMeasurements($title))['label']);

            // Save Vision quota when title already specifies size — use Gemma 4 (14.4K RPD) first
            if ($hasTitleSize) {
                $textRes = $this->generateQueryWithGemini($geminiKey, $title, $brand);
                if ($textRes !== null) {
                    $activeFeatures[] = 'gemini_query';
                    $aiModelUsed = $aiModelUsed ?: $textRes['model'];
                    $res = [
                        'query' => $textRes['query'],
                        'source' => 'ai',
                        'model' => $textRes['model'],
                        'ocr_brand' => null,
                        'ocr_size' => null,
                    ];
                    Cache::put($cacheKey, $res, now()->addDays(7));
                    return $res;
                }
            }

            if ($sourceImageData !== null) {
                $visionRes = $this->generateQueryWithGeminiVision($geminiKey, $title, $brand, $sourceImageData);
                if ($visionRes !== null) {
                    $activeFeatures[] = 'gemini_vision_ocr';
                    $aiModelUsed = $aiModelUsed ?: ($visionRes['model'] ?? null);
                    Cache::put($cacheKey, $visionRes, now()->addDays(7));
                    return $visionRes;
                }
                $skippedFeatures[] = [
                    'feature' => 'gemini_vision_ocr',
                    'reason' => 'Vision OCR skipped; fell back to text/rules',
                ];
            }

            if (!$hasTitleSize) {
                $textRes = $this->generateQueryWithGemini($geminiKey, $title, $brand);
                if ($textRes !== null) {
                    $activeFeatures[] = 'gemini_query';
                    $aiModelUsed = $aiModelUsed ?: $textRes['model'];
                    $res = [
                        'query' => $textRes['query'],
                        'source' => 'ai',
                        'model' => $textRes['model'],
                        'ocr_brand' => null,
                        'ocr_size' => null,
                    ];
                    Cache::put($cacheKey, $res, now()->addDays(7));
                    return $res;
                }
            }
        }

        $fallback = $this->extractFallbackQuery($title, $brand);
        $res = [
            'query' => $fallback,
            'source' => 'fallback',
            'ocr_brand' => null,
            'ocr_size' => null,
        ];
        Cache::put($cacheKey, $res, now()->addDays(7));

        return $res;
    }

    /**
     * Use Gemini Multimodal fallback chain to read brand/size from product image and produce a clean search query.
     */
    protected function generateQueryWithGeminiVision(string $apiKey, string $title, ?string $brand, array $imageData): ?array
    {
        try {
            $brandLine = ($brand && strtolower($brand) !== 'generic') ? "Known Brand: {$brand}\n" : '';
            $prompt = "Look at this product image and title. Extract:\n"
                . "1) \"query\": A concise 4 to 7 word English search query to find this exact product on Amazon and CJ Dropshipping (include core product type + key target audience like Dogs Cats; ONLY include a brand name if it is a widely recognized major brand or explicitly appears in the product title — do NOT guess or prefix obscure/blurry label words onto the query; exclude spam words like Premium, Hot Sale, Free Shipping).\n"
                . "2) \"ocr_brand\": Brand name printed on the product packaging (or null if unclear).\n"
                . "3) \"ocr_size\": Net weight/volume/pack size printed on the packaging like \"8.8 fl oz\" or \"250 ml\" (or null if none).\n"
                . "Output ONLY strict valid JSON.\n\n"
                . $brandLine
                . "Product Title: {$title}";

            $res = $this->callGeminiModelsWithFallback($apiKey, $this->visionModels, [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt],
                            [
                                'inlineData' => [
                                    'mimeType' => $imageData['mime'],
                                    'data' => base64_encode($imageData['bytes']),
                                ],
                            ],
                        ],
                    ],
                ],
                'generationConfig' => [
                    'temperature' => 0.2,
                    'maxOutputTokens' => 256,
                ],
            ], 10);

            if ($res === null) {
                return null;
            }

            if (!preg_match('/\{[\s\S]*\}/', $res['text'], $m)) {
                return null;
            }

            $decoded = json_decode($m[0], true);
            if (!is_array($decoded) || empty($decoded['query'])) {
                return null;
            }

            $query = preg_replace('/\s+/', ' ', trim((string) $decoded['query'], " \t\n\r\0\x0B\"'`"));
            if (strlen($query) < 3 || strlen($query) > 90) {
                return null;
            }

            return [
                'query' => $query,
                'source' => 'ai',
                'model' => $res['model'],
                'ocr_brand' => !empty($decoded['ocr_brand']) ? trim((string) $decoded['ocr_brand']) : null,
                'ocr_size' => !empty($decoded['ocr_size']) ? trim((string) $decoded['ocr_size']) : null,
            ];
        } catch (\Throwable $e) {
            Log::warning('Gemini Vision OCR skipped: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Ask Gemma 4 / Flash-Lite text chain to extract a concise 4-8 word product search query.
     */
    protected function generateQueryWithGemini(string $apiKey, string $title, ?string $brand): ?array
    {
        try {
            $brandLine = ($brand && strtolower($brand) !== 'generic') ? "Brand: {$brand}\n" : '';
            $prompt = "Extract a concise 4 to 8 word English e-commerce search query to find this exact same product on Amazon and CJ Dropshipping.\n"
                . "Rules:\n"
                . "- Keep core product name, brand (if real), model number, or key target audience (e.g. Dogs Cats).\n"
                . "- Remove promotional spam like 2025, 2026, New, Hot Sale, Free Shipping, Dropshipping, Gift, Premium, Supports, All Ages Breeds.\n"
                . "- Output ONLY the plain search query text with no quotes, markdown, or extra explanation.\n\n"
                . $brandLine
                . "Product Title: {$title}";

            $res = $this->callGeminiModelsWithFallback($apiKey, $this->textModels, [
                'contents' => [['parts' => [['text' => $prompt]]]],
                'generationConfig' => [
                    'temperature' => 0.2,
                    'maxOutputTokens' => 80,
                ],
            ], 8);

            if ($res === null) {
                return null;
            }

            $cleaned = strip_tags($res['text']);
            $cleaned = preg_replace('/[\r\n]+/', ' ', $cleaned);
            $cleaned = trim((string) $cleaned, " \t\n\r\0\x0B\"'`*.-");
            $cleaned = preg_replace('/\s+/', ' ', (string) $cleaned);

            if ($cleaned === '' || strlen($cleaned) < 3 || strlen($cleaned) > 90) {
                return null;
            }

            return [
                'query' => $cleaned,
                'model' => $res['model'],
            ];
        } catch (\Throwable $e) {
            Log::warning('Gemini smart query generation skipped: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Deterministic rule-based query cleaner preserving core product + audience keywords.
     */
    public function extractFallbackQuery(string $title, ?string $brand = null): string
    {
        $clean = preg_replace('/\[[^\]]*\]|\([^\)]*\)/', ' ', $title);
        $clean = str_replace('-', ' ', (string) $clean);
        $clean = preg_replace('/[^\p{L}\p{N}\s\.]+/u', ' ', $clean);

        $stopWords = [
            'new', 'hot', 'sale', 'free', 'shipping', 'dropshipping', 'dropship',
            'wholesale', 'high', 'quality', 'best', 'gift', 'gifts', 'for', 'with',
            'and', 'the', '2024', '2025', '2026', '2027', 'fashion', 'luxury',
            'original', 'official', 'latest', 'upgraded', 'portable', 'universal',
            'pcs', 'set', 'premium', 'super', 'ultra', 'deluxe', 'natural', 'pure',
            'grade', 'human', 'formula', 'supports', 'support', 'health', 'healthy',
            'ages', 'breeds', 'breed', 'all', 'perfect', 'picky', 'eaters', 'fatty',
            'acids', 'acid', 'epa', 'dha', 'supplement', 'supplements', 'omega',
            'skin', 'coat', 'joint', 'joints', 'wet', 'dry', 'food', 'unisex',
        ];

        $audienceWords = [
            'dogs', 'dog', 'cats', 'cat', 'pet', 'pets', 'puppy', 'kitten',
            'baby', 'kids', 'men', 'women', 'car', 'kitchen', 'phone', 'watch',
            'bluetooth', 'wireless', 'gaming', 'laptop',
        ];

        $tokens = preg_split('/\s+/', trim((string) $clean)) ?: [];
        $kept = [];
        $foundAudience = [];

        if ($brand && strtolower($brand) !== 'generic') {
            $brandClean = trim($brand);
            if ($brandClean !== '' && stripos($title, $brandClean) === false) {
                $kept[] = $brandClean;
            }
        }

        foreach ($tokens as $tok) {
            $lower = strtolower(trim($tok, '.'));
            if ($lower === '' || strlen($lower) < 2) {
                continue;
            }
            if (ctype_digit($lower)) {
                continue;
            }
            if (in_array($lower, $audienceWords, true)) {
                if (!in_array($tok, $foundAudience, true)) {
                    $foundAudience[] = $tok;
                }
                continue;
            }
            if (in_array($lower, $stopWords, true)) {
                continue;
            }
            if (!in_array($tok, $kept, true) && count($kept) < 5) {
                $kept[] = $tok;
            }
        }

        foreach (array_slice($foundAudience, 0, 2) as $aud) {
            if (!in_array($aud, $kept, true)) {
                $kept[] = $aud;
            }
        }

        if (empty($kept)) {
            $kept = array_slice($tokens, 0, 6);
        }

        return trim(implode(' ', array_slice($kept, 0, 7))) ?: mb_substr($title, 0, 60);
    }

    /**
     * Build progressively shorter queries when a supplier returns zero results.
     */
    protected function buildShorterQueries(string $primaryQuery): array
    {
        $words = preg_split('/\s+/', trim($primaryQuery)) ?: [];
        if (count($words) <= 2) {
            return [];
        }

        $candidates = [];
        if (count($words) > 4) {
            $candidates[] = implode(' ', array_slice($words, 0, 4));
        }
        if (count($words) > 3) {
            $candidates[] = implode(' ', array_slice($words, 0, 3));
        }
        if (count($words) >= 3) {
            $candidates[] = implode(' ', array_slice($words, 1, 3));
        }
        $candidates[] = implode(' ', array_slice($words, 0, 2));

        $unique = [];
        foreach ($candidates as $c) {
            $c = trim($c);
            if ($c !== '' && strcasecmp($c, $primaryQuery) !== 0 && !in_array($c, $unique, true)) {
                $unique[] = $c;
            }
        }

        return array_slice($unique, 0, 3);
    }

    /**
     * Search a supplier with automatic fallback to shorter queries when 0 results return.
     */
    protected function searchSupplierWithFallback(string $supplier, string $query, int $fetchSize, int $page = 1): array
    {
        $items = $this->searchSupplier($supplier, $query, $fetchSize, $page);
        if (!empty($items)) {
            return [
                'items' => $items,
                'used_query' => $query,
            ];
        }

        if ($page === 1) {
            $fallbackQueries = $this->buildShorterQueries($query);
            foreach ($fallbackQueries as $shortQuery) {
                $items = $this->searchSupplier($supplier, $shortQuery, $fetchSize, $page);
                if (!empty($items)) {
                    return [
                        'items' => $items,
                        'used_query' => $shortQuery,
                    ];
                }
            }
        }

        return [
            'items' => [],
            'used_query' => $query,
        ];
    }

    /**
     * Execute supplier search using existing service classes.
     */
    protected function searchSupplier(string $supplier, string $query, int $fetchSize, int $page = 1): array
    {
        return match ($supplier) {
            'aliexpress' => ($this->aliExpressService->searchProducts($query, $page, $fetchSize))['items'] ?? [],
            'cj' => ($this->cjService->searchProducts($query, $page, $fetchSize))['products'] ?? [],
            'amazon' => ($this->amazonScraperService->searchProducts($query, $page, $fetchSize))['products'] ?? [],
            default => [],
        };
    }

    /**
     * Normalize raw supplier search item into unified schema.
     */
    protected function normalizeCandidate(string $supplier, array $raw): ?array
    {
        if ($supplier === 'aliexpress') {
            $pid = (string) ($raw['productId'] ?? '');
            $itemTitle = trim((string) ($raw['title'] ?? ''));
            if ($pid === '' || $itemTitle === '') {
                return null;
            }
            $price = $this->parsePrice($raw['costPrice'] ?? ($raw['price'] ?? null));
            return [
                'supplier' => 'aliexpress',
                'product_id' => $pid,
                'title' => $itemTitle,
                'price' => $price,
                'currency' => (string) ($raw['currency'] ?? 'USD'),
                'shipping' => null,
                'total_cost' => $price,
                'free_shipping' => false,
                'delivery_estimate' => null,
                'image' => $raw['imageUrl'] ?? null,
                'url' => $raw['productUrl'] ?? "https://www.aliexpress.com/item/{$pid}.html",
                'store_name' => $raw['storeName'] ?? 'AliExpress',
                'rating' => isset($raw['rating']) ? (float) $raw['rating'] : null,
                'available' => true,
                'stock' => null,
                'match_score' => 0,
                'lexical_score' => 0,
                'visual_score' => null,
                'ai_verified' => false,
                'detected_size' => null,
                'unit_price_label' => null,
                'size_warning' => null,
                'quality_warning' => null,
                'match_reason' => '',
                'is_source' => false,
                'is_lowest_cost' => false,
                'is_best_value' => false,
                'details_checked' => false,
            ];
        }

        if ($supplier === 'cj') {
            $pid = (string) ($raw['id'] ?? $raw['pid'] ?? '');
            $itemTitle = trim((string) ($raw['productNameEn'] ?? $raw['productName'] ?? ''));
            if ($pid === '' || $itemTitle === '') {
                return null;
            }
            $price = $this->parsePrice($raw['sellPrice'] ?? ($raw['productPrice'] ?? null));
            $img = $raw['productImage'] ?? $raw['bigImage'] ?? null;
            if (is_array($img)) {
                $img = $img[0] ?? null;
            }
            $isFreeShip = (isset($raw['addMarkStatus']) && (int) $raw['addMarkStatus'] === 1) || !empty($raw['isFreeShipping']);
            return [
                'supplier' => 'cj',
                'product_id' => $pid,
                'title' => $itemTitle,
                'price' => $price,
                'currency' => 'USD',
                'shipping' => $isFreeShip ? 0.0 : null,
                'total_cost' => $price,
                'free_shipping' => $isFreeShip,
                'delivery_estimate' => null,
                'image' => is_string($img) ? $img : null,
                'url' => "https://cjdropshipping.com/product/-p-{$pid}.html",
                'store_name' => 'CJ Dropshipping',
                'rating' => null,
                'available' => true,
                'stock' => null,
                'match_score' => 0,
                'lexical_score' => 0,
                'visual_score' => null,
                'ai_verified' => false,
                'detected_size' => null,
                'unit_price_label' => null,
                'size_warning' => null,
                'quality_warning' => null,
                'match_reason' => '',
                'is_source' => false,
                'is_lowest_cost' => false,
                'is_best_value' => false,
                'details_checked' => false,
            ];
        }

        if ($supplier === 'amazon') {
            $asin = (string) ($raw['asin'] ?? $raw['id'] ?? $raw['pid'] ?? '');
            $itemTitle = trim((string) ($raw['title'] ?? $raw['productNameEn'] ?? $raw['productName'] ?? ''));
            if ($asin === '' || $itemTitle === '') {
                return null;
            }
            $price = $this->parsePrice($raw['price'] ?? ($raw['sellPrice'] ?? null));
            return [
                'supplier' => 'amazon',
                'product_id' => $asin,
                'title' => $itemTitle,
                'price' => $price,
                'currency' => 'USD',
                'shipping' => null,
                'total_cost' => $price,
                'free_shipping' => false,
                'delivery_estimate' => null,
                'image' => $raw['image'] ?? $raw['productImage'] ?? $raw['bigImage'] ?? null,
                'url' => $raw['url'] ?? "https://www.amazon.com/dp/{$asin}",
                'store_name' => 'Amazon',
                'rating' => null,
                'available' => true,
                'stock' => null,
                'match_score' => 0,
                'lexical_score' => 0,
                'visual_score' => null,
                'ai_verified' => false,
                'detected_size' => null,
                'unit_price_label' => null,
                'size_warning' => null,
                'quality_warning' => null,
                'match_reason' => '',
                'is_source' => false,
                'is_lowest_cost' => false,
                'is_best_value' => false,
                'details_checked' => false,
            ];
        }

        return null;
    }

    /**
     * Download candidate thumbnails in parallel, compute GD visual similarity, and return downloaded image buffers.
     */
    protected function applyVisualMatchScores(
        ?array $sourceImageData,
        array &$suppliers,
        array &$activeFeatures,
        array &$skippedFeatures
    ): array {
        $downloaded = [];

        if ($sourceImageData === null || !extension_loaded('gd') || !function_exists('imagecreatefromstring')) {
            if ($sourceImageData === null) {
                $skippedFeatures[] = ['feature' => 'gd_visual', 'reason' => 'Source image unavailable'];
            }
            return $downloaded;
        }

        $sourceSig = $this->extractImageSignature($sourceImageData['bytes']);
        if ($sourceSig === null) {
            $skippedFeatures[] = ['feature' => 'gd_visual', 'reason' => 'Could not decode source image'];
            return $downloaded;
        }

        $imageTasks = [];
        foreach ($suppliers as $supplierKey => $bucket) {
            if (($bucket['status'] ?? '') === 'source' || empty($bucket['items'])) {
                continue;
            }
            foreach ($bucket['items'] as $idx => $item) {
                $img = $item['image'] ?? null;
                if (!empty($img) && is_string($img) && str_starts_with($img, 'http')) {
                    $imageTasks["{$supplierKey}:{$idx}"] = $img;
                }
            }
        }

        if (empty($imageTasks)) {
            return $downloaded;
        }

        try {
            $responses = Http::pool(function (Pool $pool) use ($imageTasks) {
                $reqs = [];
                foreach ($imageTasks as $key => $imgUrl) {
                    $reqs[] = $pool->as($key)
                        ->withOptions(['verify' => false])
                        ->withHeaders([
                            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                            'Accept' => 'image/webp,image/jpeg,image/png,image/*;q=0.8',
                        ])
                        ->timeout(5)
                        ->get($imgUrl);
                }
                return $reqs;
            });

            $scoredAny = false;
            foreach ($suppliers as $supplierKey => &$bucket) {
                if (($bucket['status'] ?? '') === 'source' || empty($bucket['items'])) {
                    continue;
                }
                foreach ($bucket['items'] as $idx => &$item) {
                    $respKey = "{$supplierKey}:{$idx}";
                    $resp = $responses[$respKey] ?? null;
                    if (!$resp instanceof \Illuminate\Http\Client\Response || !$resp->successful()) {
                        continue;
                    }

                    $bytes = $resp->body();
                    if (strlen($bytes) < 100 || strlen($bytes) > 4 * 1024 * 1024) {
                        continue;
                    }

                    $mime = explode(';', $resp->header('Content-Type') ?: 'image/jpeg')[0];
                    if (!str_starts_with($mime, 'image/')) {
                        $mime = 'image/jpeg';
                    }
                    $downloaded[$respKey] = ['bytes' => $bytes, 'mime' => $mime];

                    $candSig = $this->extractImageSignature($bytes);
                    if ($candSig === null) {
                        continue;
                    }

                    $scoredAny = true;
                    $visualScore = $this->compareImageSignatures($sourceSig, $candSig);
                    $item['visual_score'] = $visualScore;

                    $lexicalScore = (int) ($item['lexical_score'] ?? 0);
                    $combined = (int) round(0.45 * $lexicalScore + 0.55 * $visualScore);
                    if ($visualScore >= 78 && $lexicalScore >= 25) {
                        $combined = max($combined, min(98, $visualScore + 5));
                    }
                    if (!empty($item['size_warning'])) {
                        $combined = max(0, $combined - 6);
                    }

                    $item['match_score'] = max(0, min(99, $combined));
                    $baseReason = $item['match_reason'] ?: 'Title match';
                    $item['match_reason'] = "Visual {$visualScore}% · {$baseReason}";
                }
                unset($item);
            }
            unset($bucket);

            if ($scoredAny) {
                $activeFeatures[] = 'gd_visual';
            }
        } catch (\Throwable $e) {
            Log::warning('Visual similarity scoring skipped: ' . $e->getMessage());
            $skippedFeatures[] = ['feature' => 'gd_visual', 'reason' => 'Image download error'];
        }

        return $downloaded;
    }

    /**
     * Multimodal Gemini Vision check comparing source image against top candidate images,
     * with 24h per-pair caching and multi-model auto-fallback.
     */
    protected function verifyTopMatchesWithGeminiVision(
        string $apiKey,
        string $sourceTitle,
        ?string $brand,
        array $sourceImageData,
        array &$suppliers,
        array $downloadedImages,
        array &$activeFeatures,
        array &$skippedFeatures,
        ?string &$aiModelUsed = null
    ): bool {
        try {
            $candidates = [];
            foreach ($suppliers as $supplierKey => $bucket) {
                if (($bucket['status'] ?? '') === 'source' || empty($bucket['items'])) {
                    continue;
                }
                foreach ($bucket['items'] as $idx => $item) {
                    $respKey = "{$supplierKey}:{$idx}";
                    if (isset($downloadedImages[$respKey]) && !empty($item['product_id'])) {
                        $candidates[] = [
                            'supplier' => $supplierKey,
                            'idx' => $idx,
                            'key' => $supplierKey . ':' . $item['product_id'],
                            'item' => $item,
                            'img' => $downloadedImages[$respKey],
                        ];
                    }
                }
            }

            if (empty($candidates)) {
                return false;
            }

            // Pick top 4 by visual_score + top 2 by match_score (deduplicated, up to 6 total)
            $byVisual = $candidates;
            usort($byVisual, fn (array $a, array $b) => ($b['item']['visual_score'] ?? 0) <=> ($a['item']['visual_score'] ?? 0));
            $byMatch = $candidates;
            usort($byMatch, fn (array $a, array $b) => ($b['item']['match_score'] ?? 0) <=> ($a['item']['match_score'] ?? 0));

            $top = [];
            $seenKeys = [];
            foreach (array_slice($byVisual, 0, 4) as $c) {
                $top[] = $c;
                $seenKeys[$c['key']] = true;
            }
            foreach ($byMatch as $c) {
                if (count($top) >= 6) {
                    break;
                }
                if (!isset($seenKeys[$c['key']])) {
                    $top[] = $c;
                    $seenKeys[$c['key']] = true;
                }
            }

            $srcHash = md5($sourceImageData['bytes']);
            $decoded = [];
            $uncachedTop = [];

            foreach ($top as $c) {
                $pairKey = 'price_compare_vision_pair_v1:' . md5($srcHash . '|' . md5($c['img']['bytes']) . '|' . $c['item']['title']);
                $cachedPair = Cache::get($pairKey);
                if (is_array($cachedPair) && isset($cachedPair['score'])) {
                    $decoded[$c['key']] = $cachedPair;
                } else {
                    $c['pair_cache_key'] = $pairKey;
                    $uncachedTop[] = $c;
                }
            }

            if (!empty($uncachedTop)) {
                $sourceMeas = $this->extractMeasurements($sourceTitle);
                $sourceSize = $sourceMeas['label'] ?? 'unknown';
                $brandText = ($brand && strtolower($brand) !== 'generic') ? " (Brand: {$brand})" : '';

                $parts = [];
                $parts[] = [
                    'text' => "You are a visual product matching expert for e-commerce dropshipping.\n"
                        . "Below is ONE Source Product image followed by labelled Candidate Product images.\n"
                        . "Compare the ACTUAL bottle/box shape, color scheme, label layout, cap/pump, and graphics in the images.\n"
                        . "IMPORTANT: Private-label/OEM products often omit the brand name in AliExpress titles. Do NOT mark them as 'different brand' merely because a brand word is missing from the text title — judge primarily by the visual packaging and product appearance in the images!\n"
                        . "If the packaging/bottle/label design in a candidate image matches the Source Image, set same_product=true and score between 88 and 99.",
                ];

                $parts[] = ['text' => "SOURCE PRODUCT IMAGE — Title: {$sourceTitle}{$brandText} | Size: {$sourceSize}"];
                $parts[] = [
                    'inlineData' => [
                        'mimeType' => $sourceImageData['mime'],
                        'data' => base64_encode($sourceImageData['bytes']),
                    ],
                ];

                foreach ($uncachedTop as $i => $c) {
                    $num = $i + 1;
                    $priceStr = $c['item']['price'] !== null ? ('$' . number_format((float) $c['item']['price'], 2)) : 'N/A';
                    $candSize = $c['item']['detected_size'] ?? 'unknown';
                    $parts[] = ['text' => "CANDIDATE {$num} (key: {$c['key']}) — Title: {$c['item']['title']} | Price: {$priceStr} | Size: {$candSize}"];
                    $parts[] = [
                        'inlineData' => [
                            'mimeType' => $c['img']['mime'],
                            'data' => base64_encode($c['img']['bytes']),
                        ],
                    ];
                }

                $parts[] = [
                    'text' => "Return ONLY valid JSON (no markdown) mapping every candidate key to:\n"
                        . "{\"same_product\": true/false, \"score\": 0-100, \"reason\": \"<= 50 chars\"}.",
                ];

                $res = $this->callGeminiModelsWithFallback($apiKey, $this->visionModels, [
                    'contents' => [['parts' => $parts]],
                    'generationConfig' => [
                        'temperature' => 0.1,
                        'maxOutputTokens' => 768,
                    ],
                ], 15);

                if ($res !== null && preg_match('/\{[\s\S]*\}/', $res['text'], $m)) {
                    $freshDecoded = json_decode($m[0], true);
                    if (is_array($freshDecoded)) {
                        $aiModelUsed = $res['model'];
                        foreach ($uncachedTop as $c) {
                            $k = $c['key'];
                            if (isset($freshDecoded[$k]) && is_array($freshDecoded[$k]) && isset($freshDecoded[$k]['score'])) {
                                $decoded[$k] = $freshDecoded[$k];
                                Cache::put($c['pair_cache_key'], $freshDecoded[$k], now()->addHours(24));
                            }
                        }
                    }
                } elseif ($res === null && empty($decoded)) {
                    $skippedFeatures[] = ['feature' => 'gemini_vision_verify', 'reason' => 'All vision models busy/quota reached; using GD Visual'];
                    return false;
                }
            }

            if (empty($decoded)) {
                return false;
            }

            $applied = false;
            foreach ($top as $c) {
                $key = $c['key'];
                if (!isset($decoded[$key]) || !is_array($decoded[$key]) || !isset($decoded[$key]['score'])) {
                    continue;
                }
                $aiScore = max(0, min(100, (int) $decoded[$key]['score']));
                $same = (bool) ($decoded[$key]['same_product'] ?? false);
                $reason = isset($decoded[$key]['reason']) ? mb_substr(trim((string) $decoded[$key]['reason']), 0, 55) : '';

                $sKey = $c['supplier'];
                $idx = $c['idx'];
                if (isset($suppliers[$sKey]['items'][$idx])) {
                    $item = &$suppliers[$sKey]['items'][$idx];
                    $vis = $item['visual_score'] ?? $aiScore;

                    if ($same && $aiScore >= 75) {
                        $item['ai_verified'] = true;
                        $score = max($aiScore, (int) round(0.75 * $aiScore + 0.25 * $vis));
                    } else {
                        $item['ai_verified'] = false;
                        $score = (int) round(0.65 * $aiScore + 0.35 * $vis);
                    }

                    if (!empty($item['size_matched'])) {
                        $score += 6;
                    }
                    if (!empty($item['size_warning'])) {
                        $score -= 8;
                    }

                    $item['match_score'] = max(0, min(99, $score));
                    if ($reason !== '') {
                        $item['match_reason'] = $reason;
                    }
                    unset($item);
                    $applied = true;
                }
            }

            if ($applied) {
                $activeFeatures[] = 'gemini_vision_verify';
            }

            return $applied;
        } catch (\Throwable $e) {
            Log::warning('Gemini Vision verification skipped: ' . $e->getMessage());
            $skippedFeatures[] = ['feature' => 'gemini_vision_verify', 'reason' => 'Exception skipped'];
            return false;
        }
    }

    /**
     * Text-only match refinement using Gemma 4 / Flash-Lite chain when vision verification did not run.
     */
    protected function refineMatchScoresWithGeminiText(
        string $apiKey,
        string $sourceTitle,
        ?string $brand,
        array &$suppliers,
        array &$activeFeatures,
        array &$skippedFeatures,
        ?string &$aiModelUsed = null
    ): void {
        try {
            $candidatePool = [];
            foreach ($suppliers as $supplierKey => $bucket) {
                if (($bucket['status'] ?? '') === 'source' || empty($bucket['items'])) {
                    continue;
                }
                foreach (array_slice($bucket['items'], 0, 3) as $candidate) {
                    if (!empty($candidate['product_id'])) {
                        $candidatePool[$supplierKey . ':' . $candidate['product_id']] = $candidate;
                    }
                }
            }

            if (empty($candidatePool)) {
                return;
            }

            $lines = [];
            foreach ($candidatePool as $key => $cand) {
                $priceStr = $cand['price'] !== null ? ('$' . number_format((float) $cand['price'], 2)) : 'N/A';
                $lines[] = "- {$key} | Title: {$cand['title']} | Price: {$priceStr}";
            }

            $brandText = ($brand && strtolower($brand) !== 'generic') ? " (Brand: {$brand})" : '';
            $prompt = "You are matching products across e-commerce suppliers.\n"
                . "Source Product: {$sourceTitle}{$brandText}\n\n"
                . "Candidates:\n" . implode("\n", $lines) . "\n\n"
                . "Return ONLY valid JSON mapping each candidate key to {\"score\": 0-100, \"reason\": \"<= 50 chars\"}.";

            $res = $this->callGeminiModelsWithFallback($apiKey, $this->textModels, [
                'contents' => [['parts' => [['text' => $prompt]]]],
                'generationConfig' => [
                    'temperature' => 0.1,
                    'maxOutputTokens' => 512,
                ],
            ], 10);

            if ($res === null || !preg_match('/\{[\s\S]*\}/', $res['text'], $m)) {
                return;
            }

            $decoded = json_decode($m[0], true);
            if (!is_array($decoded)) {
                return;
            }

            $aiModelUsed = $aiModelUsed ?: $res['model'];
            $applied = false;
            foreach ($suppliers as $supplierKey => &$bucket) {
                if ($bucket['status'] === 'source' || empty($bucket['items'])) {
                    continue;
                }
                foreach ($bucket['items'] as &$item) {
                    $key = $supplierKey . ':' . ($item['product_id'] ?? '');
                    if (!isset($decoded[$key]) || !is_array($decoded[$key]) || !isset($decoded[$key]['score'])) {
                        continue;
                    }
                    $aiScore = max(0, min(100, (int) $decoded[$key]['score']));
                    if ($item['visual_score'] !== null) {
                        $item['match_score'] = (int) round(0.60 * $aiScore + 0.40 * $item['visual_score']);
                    } else {
                        $item['match_score'] = $aiScore;
                    }
                    if (!empty($decoded[$key]['reason'])) {
                        $reason = mb_substr(trim((string) $decoded[$key]['reason']), 0, 55);
                        $item['match_reason'] = ($item['visual_score'] !== null ? "Visual {$item['visual_score']}% · " : '') . $reason;
                    }
                    $applied = true;
                }
                unset($item);
            }
            unset($bucket);

            if ($applied) {
                $activeFeatures[] = 'gemini_text_verify';
            }
        } catch (\Throwable $e) {
            Log::warning('Gemini text refinement skipped: ' . $e->getMessage());
            $skippedFeatures[] = ['feature' => 'gemini_text_verify', 'reason' => 'Text AI skipped'];
        }
    }

    /**
     * Extract 64-bit dHash and 64-bin foreground RGB histogram from image bytes.
     */
    protected function extractImageSignature(string $bytes): ?array
    {
        if (strlen($bytes) < 100) {
            return null;
        }

        $img = @imagecreatefromstring($bytes);
        if ($img === false) {
            return null;
        }

        $w = imagesx($img);
        $h = imagesy($img);
        if ($w < 8 || $h < 8) {
            imagedestroy($img);
            return null;
        }

        $thumbHash = imagecreatetruecolor(9, 8);
        imagecopyresampled($thumbHash, $img, 0, 0, 0, 0, 9, 8, $w, $h);
        $bits = [];
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $rgb1 = imagecolorat($thumbHash, $x, $y);
                $rgb2 = imagecolorat($thumbHash, $x + 1, $y);
                $g1 = (($rgb1 >> 16) & 0xFF) * 0.299 + (($rgb1 >> 8) & 0xFF) * 0.587 + ($rgb1 & 0xFF) * 0.114;
                $g2 = (($rgb2 >> 16) & 0xFF) * 0.299 + (($rgb2 >> 8) & 0xFF) * 0.587 + ($rgb2 & 0xFF) * 0.114;
                $bits[] = ($g1 > $g2) ? 1 : 0;
            }
        }
        imagedestroy($thumbHash);

        $thumbColor = imagecreatetruecolor(48, 48);
        imagecopyresampled($thumbColor, $img, 0, 0, 0, 0, 48, 48, $w, $h);
        $fgHist = array_fill(0, 64, 0.0);
        $allHist = array_fill(0, 64, 0.0);
        $fgCount = 0;
        $totalCount = 48 * 48;

        for ($y = 0; $y < 48; $y++) {
            for ($x = 0; $x < 48; $x++) {
                $rgb = imagecolorat($thumbColor, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $bin = (($r >> 6) << 4) | (($g >> 6) << 2) | ($b >> 6);
                $allHist[$bin] += 1.0;

                if (!($r > 235 && $g > 235 && $b > 235)) {
                    $fgHist[$bin] += 1.0;
                    $fgCount++;
                }
            }
        }
        imagedestroy($thumbColor);
        imagedestroy($img);

        $hist = ($fgCount >= ($totalCount * 0.05)) ? $fgHist : $allHist;

        $normSq = 0.0;
        foreach ($hist as $v) {
            $normSq += $v * $v;
        }
        $norm = sqrt($normSq);
        if ($norm > 0) {
            foreach ($hist as &$v) {
                $v /= $norm;
            }
            unset($v);
        }

        return [
            'dhash' => $bits,
            'hist' => $hist,
        ];
    }

    protected function compareImageSignatures(array $sigA, array $sigB): int
    {
        $hamming = 0;
        for ($i = 0; $i < 64; $i++) {
            if (($sigA['dhash'][$i] ?? 0) !== ($sigB['dhash'][$i] ?? 0)) {
                $hamming++;
            }
        }
        $dhashSim = (1.0 - ($hamming / 64.0)) * 100.0;

        $dot = 0.0;
        for ($i = 0; $i < 64; $i++) {
            $dot += ($sigA['hist'][$i] ?? 0.0) * ($sigB['hist'][$i] ?? 0.0);
        }
        $colorSim = max(0.0, min(1.0, $dot)) * 100.0;

        return (int) max(0, min(100, round(0.60 * $colorSim + 0.40 * $dhashSim)));
    }

    protected function computeLexicalMatchScore(string $sourceTitle, string $candidateTitle, ?string $brand): array
    {
        $sourceTokens = $this->tokenize($sourceTitle);
        $candTokens = $this->tokenize($candidateTitle);

        if (empty($sourceTokens) || empty($candTokens)) {
            return ['score' => 0, 'reason' => 'Insufficient title tokens'];
        }

        $intersection = array_values(array_intersect($sourceTokens, $candTokens));
        $unionCount = count(array_unique(array_merge($sourceTokens, $candTokens)));

        $recall = count($intersection) / max(1, count($sourceTokens));
        $jaccard = count($intersection) / max(1, $unionCount);
        $baseScore = ($recall * 0.65 + $jaccard * 0.35) * 100;

        $modelBonus = 0;
        foreach ($intersection as $tok) {
            if (preg_match('/\d/', $tok)) {
                $modelBonus += 6;
            }
        }
        $modelBonus = min(15, $modelBonus);

        $brandBonus = 0;
        if ($brand && strtolower($brand) !== 'generic' && stripos($candidateTitle, $brand) !== false) {
            $brandBonus = 10;
        }

        $score = (int) min(99, max(0, round($baseScore + $modelBonus + $brandBonus)));

        $sampleKeywords = array_slice($intersection, 0, 4);
        $reason = !empty($sampleKeywords)
            ? 'Matched: ' . implode(', ', $sampleKeywords)
            : 'Low keyword overlap';

        return ['score' => $score, 'reason' => $reason];
    }

    /**
     * Compute both Lowest Cost and Best Value (high match + quality) summaries.
     */
    protected function computeSummary(string $sourceSupplier, array &$sourceItem, array &$suppliers): array
    {
        $sourceEntry = null;
        if ($sourceItem['total_cost'] !== null && $sourceItem['total_cost'] > 0) {
            $sourceEntry = [
                'supplier' => $sourceSupplier,
                'product_id' => $sourceItem['product_id'],
                'total_cost' => (float) $sourceItem['total_cost'],
                'match_score' => 100,
            ];
        }

        $verifiedContenders = [];
        $strongContenders = [];
        $qualityContenders = [];
        $fallbackContenders = [];

        if ($sourceEntry !== null) {
            $verifiedContenders[] = $sourceEntry;
            $strongContenders[] = $sourceEntry;
            $fallbackContenders[] = $sourceEntry;
        }

        foreach ($suppliers as $supplierKey => $bucket) {
            if ($supplierKey === $sourceSupplier && ($bucket['status'] ?? '') === 'source') {
                continue;
            }

            $best = $bucket['best_match'] ?? null;
            if ($best && ($best['total_cost'] ?? null) !== null && (float) $best['total_cost'] > 0) {
                $fallbackContenders[] = [
                    'supplier' => $supplierKey,
                    'product_id' => $best['product_id'],
                    'total_cost' => (float) $best['total_cost'],
                    'match_score' => (int) ($best['match_score'] ?? 0),
                ];
            }

            foreach (($bucket['items'] ?? []) as $item) {
                if (($item['total_cost'] ?? null) === null || (float) $item['total_cost'] <= 0) {
                    continue;
                }
                if (($item['available'] ?? true) === false) {
                    continue;
                }

                $entry = [
                    'supplier' => $supplierKey,
                    'product_id' => $item['product_id'],
                    'total_cost' => (float) $item['total_cost'],
                    'match_score' => (int) ($item['match_score'] ?? 0),
                ];

                if (!empty($item['ai_verified']) && empty($item['size_warning'])) {
                    $verifiedContenders[] = $entry;
                }
                if (($item['match_score'] ?? 0) >= 60 && empty($item['size_warning'])) {
                    $strongContenders[] = $entry;
                }
                if (($item['match_score'] ?? 0) >= 50
                    && ($item['rating'] === null || $item['rating'] >= 4.0)
                    && empty($item['size_warning'])
                ) {
                    $qualityContenders[] = $entry;
                }
            }
        }

        // Prefer AI-verified contenders -> strong matches (>=60%) -> fallback best_match per supplier
        $contenders = count($verifiedContenders) > ($sourceEntry ? 1 : 0)
            ? $verifiedContenders
            : (count($strongContenders) > ($sourceEntry ? 1 : 0) ? $strongContenders : $fallbackContenders);

        $bestSupplier = null;
        $lowestPrice = null;

        if (!empty($contenders)) {
            usort($contenders, fn (array $a, array $b) => $a['total_cost'] <=> $b['total_cost']);
            $winner = $contenders[0];
            $bestSupplier = $winner['supplier'];
            $lowestPrice = round($winner['total_cost'], 2);

            if ($bestSupplier === $sourceSupplier && ($suppliers[$sourceSupplier]['status'] ?? '') === 'source') {
                $sourceItem['is_lowest_cost'] = true;
            }

            if (isset($suppliers[$bestSupplier]['items'])) {
                foreach ($suppliers[$bestSupplier]['items'] as &$item) {
                    if ((string) ($item['product_id'] ?? '') === (string) ($winner['product_id'] ?? '')) {
                        $item['is_lowest_cost'] = true;
                        break;
                    }
                }
                unset($item);
                if (!empty($suppliers[$bestSupplier]['best_match'])
                    && (string) ($suppliers[$bestSupplier]['best_match']['product_id'] ?? '') === (string) ($winner['product_id'] ?? '')
                ) {
                    $suppliers[$bestSupplier]['best_match']['is_lowest_cost'] = true;
                }
            }
        }

        $bestValuePool = count($verifiedContenders) > ($sourceEntry ? 1 : 0)
            ? array_values(array_filter($verifiedContenders, fn (array $c) => $c['supplier'] !== $sourceSupplier || ($suppliers[$sourceSupplier]['status'] ?? '') !== 'source'))
            : $qualityContenders;

        $bestValueSupplier = null;
        $bestValuePrice = null;
        if (!empty($bestValuePool)) {
            usort($bestValuePool, fn (array $a, array $b) => $a['total_cost'] <=> $b['total_cost']);
            $bv = $bestValuePool[0];
            $bestValueSupplier = $bv['supplier'];
            $bestValuePrice = round($bv['total_cost'], 2);

            if (isset($suppliers[$bestValueSupplier]['items'])) {
                foreach ($suppliers[$bestValueSupplier]['items'] as &$item) {
                    if ((string) ($item['product_id'] ?? '') === (string) ($bv['product_id'] ?? '')) {
                        $item['is_best_value'] = true;
                        break;
                    }
                }
                unset($item);
            }
        }

        $sourcePrice = $sourceItem['total_cost'];
        $potentialSavings = ($sourcePrice !== null && $lowestPrice !== null && $sourcePrice > $lowestPrice)
            ? round($sourcePrice - $lowestPrice, 2)
            : 0.0;

        $savingsPercent = ($sourcePrice !== null && $sourcePrice > 0 && $potentialSavings > 0)
            ? round(($potentialSavings / $sourcePrice) * 100, 1)
            : 0.0;

        return [
            'best_supplier' => $bestSupplier,
            'best_supplier_label' => $bestSupplier ? $this->supplierLabel($bestSupplier) : null,
            'lowest_price' => $lowestPrice,
            'best_value_supplier' => $bestValueSupplier,
            'best_value_label' => $bestValueSupplier ? $this->supplierLabel($bestValueSupplier) : null,
            'best_value_price' => $bestValuePrice,
            'source_price' => $sourcePrice,
            'potential_savings' => $potentialSavings,
            'savings_percent' => $savingsPercent,
        ];
    }

    protected function parsePrice(mixed $raw): ?float
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_int($raw) || is_float($raw)) {
            $val = (float) $raw;
            return $val > 0 ? round($val, 2) : null;
        }

        $str = str_replace(',', '', trim((string) $raw));
        if (preg_match('/\d+(?:\.\d+)?/', $str, $matches)) {
            $val = (float) $matches[0];
            return $val > 0 ? round($val, 2) : null;
        }

        return null;
    }

    protected function compareNullableCost(?float $a, ?float $b): int
    {
        if ($a === null && $b === null) {
            return 0;
        }
        if ($a === null) {
            return 1;
        }
        if ($b === null) {
            return -1;
        }
        return $a <=> $b;
    }

    protected function tokenize(string $text): array
    {
        $clean = strtolower($text);
        $clean = str_replace('-', ' ', $clean);
        $clean = preg_replace('/[^\p{L}\p{N}\s\.]+/u', ' ', $clean);
        $parts = preg_split('/\s+/', trim((string) $clean)) ?: [];

        $stop = ['the', 'and', 'for', 'with', 'new', 'hot', 'sale', 'pcs', 'set', 'from', 'in', 'of', 'to', 'a', 'an'];
        $tokens = [];
        foreach ($parts as $p) {
            $p = trim($p, '.');
            if ($p === '' || strlen($p) < 2 || in_array($p, $stop, true)) {
                continue;
            }
            $tokens[] = $p;
        }

        return array_values(array_unique($tokens));
    }

    protected function supplierLabel(string $supplier): string
    {
        return match ($supplier) {
            'aliexpress' => 'AliExpress',
            'cj' => 'CJ Dropshipping',
            'amazon' => 'Amazon',
            'store' => 'Store Catalog',
            default => ucfirst($supplier),
        };
    }
}
