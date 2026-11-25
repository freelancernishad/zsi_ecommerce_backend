<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImageSignature;
use App\Models\SiteSetting;
use App\Models\SystemSetting;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ProductImageSearchService
{
    protected string $signatureCacheKey = 'product_image_signatures_cache_v2';

    protected function getTenantDbKey(): string
    {
        $db = (string) config('database.connections.mysql.database', 'default');
        return preg_replace('/[^a-zA-Z0-9_-]/', '_', $db) ?: 'default';
    }

    protected function getSignatureCacheKey(): string
    {
        return $this->signatureCacheKey . ':' . $this->getTenantDbKey();
    }

    /** @var string[] Verified Gemini Vision models */
    protected array $geminiVisionModels = [
        'gemini-3.5-flash-lite',
        'gemini-3.1-flash-lite',
        'gemini-3.8-flash',
        'gemini-2.5-flash-lite',
    ];

    /** @var string[] Verified Groq Vision models */
    protected array $groqVisionModels = [
        'qwen/qwen3.8-27b',
    ];

    /** @var string[] Verified OpenRouter Free Vision models */
    protected array $openRouterVisionModels = [
        'openrouter/free',
        'google/gemma-4-31b-it:free',
        'google/gemma-4-26b-a4b-it:free',
    ];

    /**
     * Hybrid Storefront Image Search:
     * Layer 1: Multi-provider AI Vision semantic tagging -> SQL keyword matching
     * Layer 2: Pre-indexed PHP GD perceptual dHash + foreground color histogram scan across all DB products
     */
    public function searchByImage(string $imageBytes, string $mimeType = 'image/jpeg', int $limit = 16): array
    {
        $limit = max(1, min(30, $limit));

        // Path A: AI Vision semantic analysis (fault-tolerant; skips cleanly if unavailable)
        $aiResult = $this->analyzeQueryImageWithAi($imageBytes, $mimeType);
        $detected = $aiResult['detected'] ?? null;
        $aiModelUsed = $aiResult['model'] ?? null;

        $textMatches = [];
        if (is_array($detected)) {
            $textMatches = $this->findProductsBySemanticKeywords($detected, 50);
        }

        // Path B: Pre-indexed GD visual signature scan across all published products
        $visualDetails = [];
        $visualScores = [];
        $indexedCount = 0;
        $querySig = $this->extractImageSignature($imageBytes);

        if ($querySig !== null && Schema::hasTable('product_image_signatures')) {
            $allSignatures = $this->loadCachedSignatures();
            $indexedCount = count($allSignatures);

            foreach ($allSignatures as $row) {
                if (empty($row['dhash']) || empty($row['color_hist'])) {
                    continue;
                }
                $detail = $this->compareSignaturesDetailed($querySig, $row);
                $pid = (int) $row['product_id'];
                $visualDetails[$pid] = $detail;
                $visualScores[$pid] = $detail['score'];
            }
        }

        arsort($visualScores);
        $topVisualIds = [];
        if ($detected !== null) {
            foreach ($visualScores as $pid => $sc) {
                if (!empty($visualDetails[$pid]['is_exact_visual']) || !empty($visualDetails[$pid]['is_partial_visual'])) {
                    $topVisualIds[] = (int) $pid;
                    if (count($topVisualIds) >= 20) {
                        break;
                    }
                }
            }
        } else {
            foreach ($visualScores as $pid => $sc) {
                $detail = $visualDetails[$pid] ?? null;
                if ($detail && (!empty($detail['is_exact_visual']) || !empty($detail['is_partial_visual']) || $detail['score'] >= 30)) {
                    $topVisualIds[] = (int) $pid;
                    if (count($topVisualIds) >= 25) {
                        break;
                    }
                }
            }
        }

        // Merge candidate product IDs from both Path A & Path B
        $candidateIds = array_values(array_unique(array_merge(
            array_keys($textMatches),
            $topVisualIds
        )));

        if (empty($candidateIds)) {
            return [
                'detected' => $detected,
                'ai_model_used' => $aiModelUsed,
                'visual_reranked' => false,
                'indexed_products_count' => $indexedCount,
                'total_matches' => 0,
                'products' => [],
            ];
        }

        $relations = ['images', 'category'];
        if (method_exists(Product::class, 'brand')) {
            $relations[] = 'brand';
        }

        $products = Product::with($relations)
            ->where('status', 'published')
            ->whereIn('id', $candidateIds)
            ->get()
            ->keyBy('id');

        $ranked = [];
        $exactVisualMap = [];
        foreach ($candidateIds as $pid) {
            $product = $products->get($pid);
            if (!$product) {
                continue;
            }

            $textScore = $textMatches[$pid]['score'] ?? 0;
            $detail = $visualDetails[$pid] ?? null;
            $visualScore = $detail['score'] ?? null;
            $rawDhash = (float) ($detail['raw_dhash'] ?? 0.0);
            $rawColor = (float) ($detail['raw_color'] ?? 0.0);
            $isExactVisual = !empty($detail['is_exact_visual']);
            $isPartialVisual = !empty($detail['is_partial_visual']);
            $bestRegionDhash = (float) ($detail['best_region_dhash'] ?? 0.0);
            $bestRegionColor = (float) ($detail['best_region_color'] ?? 0.0);
            $exactVisualMap[$product->id] = $isExactVisual;

            if ($detected !== null) {
                if ($isExactVisual && $textScore >= 40) {
                    $combined = max(
                        92,
                        min(99, (int) round(0.45 * $textScore + 0.55 * max($visualScore ?? 0, (int) round($rawDhash)) + 4))
                    );
                } elseif ($isExactVisual) {
                    $combined = min(96, max(82, (int) round(0.65 * $rawDhash + 0.35 * $rawColor)));
                } elseif ($isPartialVisual && $textScore >= 35) {
                    $baseCombined = (int) round(0.78 * $textScore + 0.22 * ($visualScore ?? 0));
                    $partialBoost = min(95, (int) round(0.50 * $textScore + 0.50 * max($visualScore ?? 0, (int) round($bestRegionDhash))));
                    $combined = max($baseCombined, $partialBoost);
                } elseif ($isPartialVisual) {
                    $combined = min(84, max(58, (int) round(0.70 * $bestRegionDhash + 0.30 * $bestRegionColor)));
                } elseif ($textScore >= 35) {
                    $combined = (int) round(0.78 * $textScore + 0.22 * ($visualScore ?? 0));
                    if ($textScore >= 70) {
                        $combined = max($combined, (int) round(0.88 * $textScore + 0.12 * ($visualScore ?? 0)));
                    }
                } else {
                    continue;
                }
            } else {
                if (!$isExactVisual && !$isPartialVisual && ($visualScore ?? 0) < 35) {
                    continue;
                }
                if ($isExactVisual) {
                    $combined = min(96, max($visualScore ?? 0, (int) round(0.65 * $rawDhash + 0.35 * $rawColor)));
                } elseif ($isPartialVisual) {
                    $combined = min(88, max($visualScore ?? 0, (int) round(0.70 * $bestRegionDhash + 0.30 * $bestRegionColor)));
                } else {
                    $combined = (int) ($visualScore ?? 0);
                }
            }

            $combined = max(0, min(99, (int) $combined));

            $ranked[] = [
                'id' => $product->id,
                'name' => $product->name,
                'slug' => $product->slug,
                'price' => (float) $product->price,
                'sale_price' => $product->sale_price !== null ? (float) $product->sale_price : null,
                'original_price' => $product->original_price !== null ? (float) $product->original_price : null,
                'stock_quantity' => (int) ($product->stock_quantity ?? 0),
                'rating' => (float) ($product->rating ?? 0),
                'images' => $product->images->map(fn ($img) => [
                    'id' => $img->id,
                    'image_path' => $img->image_path,
                    'is_primary' => (bool) $img->is_primary,
                ])->values()->all(),
                'category' => $product->category ? [
                    'id' => $product->category->id,
                    'name' => $product->category->name,
                    'slug' => $product->category->slug,
                ] : null,
                'match_score' => $combined,
                'visual_score' => $visualScore,
                'text_score' => $textScore,
            ];
        }

        usort($ranked, function (array $a, array $b) {
            if ($a['match_score'] !== $b['match_score']) {
                return $b['match_score'] <=> $a['match_score'];
            }
            if ($a['text_score'] !== $b['text_score']) {
                return $b['text_score'] <=> $a['text_score'];
            }
            $visA = $a['visual_score'] ?? -1;
            $visB = $b['visual_score'] ?? -1;
            return $visB <=> $visA;
        });

        // Option 2: Free Vision AI 2x3 Collage Visual Re-Rank to catch partial crops & kill visual false positives
        $visualReranked = false;
        $hasAnyAiKey = ($this->getGeminiApiKey() || $this->getGroqApiKey() || $this->getOpenRouterApiKey());
        $alreadySingleCertain = count($ranked) === 1
            && !empty($exactVisualMap[$ranked[0]['id']])
            && ($ranked[0]['text_score'] ?? 0) >= 85;

        if (!empty($ranked) && $hasAnyAiKey && !$alreadySingleCertain) {
            $rerankRes = $this->rerankCandidatesWithVisionCollage($imageBytes, $mimeType, $ranked, $exactVisualMap);
            $ranked = $rerankRes['ranked'];
            $visualReranked = $rerankRes['reranked'];
        }

        $topScore = $ranked[0]['match_score'] ?? 0;
        $cutoff = $topScore >= 80 ? 44 : 36;

        $finalList = array_slice(
            array_values(array_filter(
                $ranked,
                fn (array $item) => $item['match_score'] >= $cutoff
            )),
            0,
            $limit
        );

        return [
            'detected' => $detected,
            'ai_model_used' => $aiModelUsed,
            'visual_reranked' => $visualReranked,
            'indexed_products_count' => $indexedCount,
            'total_matches' => count($finalList),
            'products' => $finalList,
        ];
    }

    /**
     * Index primary image signatures for all published products using parallel HTTP fetching.
     */
    public function indexProductSignatures(bool $fresh = false, int $batchSize = 20): array
    {
        if (!Schema::hasTable('product_image_signatures')) {
            return ['total' => 0, 'indexed' => 0, 'skipped' => 0, 'failed' => 0];
        }

        $products = Product::with('images')
            ->where('status', 'published')
            ->select(['id', 'status'])
            ->get();

        $existing = ProductImageSignature::pluck('image_url', 'product_id')->toArray();

        $total = $products->count();
        $indexed = 0;
        $skipped = 0;
        $failed = 0;
        $remoteQueue = [];

        foreach ($products as $product) {
            $primary = $product->images->firstWhere('is_primary', true) ?? $product->images->first();
            $rawPath = $primary ? trim((string) $primary->image_path) : '';

            if ($rawPath === '') {
                $failed++;
                continue;
            }

            if (!$fresh && isset($existing[$product->id]) && $existing[$product->id] === $rawPath) {
                $skipped++;
                continue;
            }

            if (!str_starts_with($rawPath, 'http://') && !str_starts_with($rawPath, 'https://')) {
                $bytes = $this->readLocalImageBytes($rawPath);
                if ($bytes !== null && ($sig = $this->extractImageSignature($bytes)) !== null) {
                    $this->upsertSignature($product->id, $rawPath, $sig);
                    $indexed++;
                } else {
                    $failed++;
                }
                continue;
            }

            $remoteQueue[] = [
                'product_id' => $product->id,
                'url' => $rawPath,
            ];
        }

        foreach (array_chunk($remoteQueue, max(1, $batchSize)) as $chunk) {
            try {
                $responses = Http::pool(function (Pool $pool) use ($chunk) {
                    $reqs = [];
                    foreach ($chunk as $item) {
                        $reqs[] = $pool->as((string) $item['product_id'])
                            ->withOptions([
                                'verify' => false,
                                'curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4],
                            ])
                            ->withHeaders([
                                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                                'Accept' => 'image/webp,image/jpeg,image/png,image/*;q=0.8',
                            ])
                            ->timeout(7)
                            ->get($item['url']);
                    }
                    return $reqs;
                });

                foreach ($chunk as $item) {
                    $pid = $item['product_id'];
                    $resp = $responses[(string) $pid] ?? null;
                    if (!$resp instanceof \Illuminate\Http\Client\Response || !$resp->successful()) {
                        $failed++;
                        continue;
                    }

                    $bytes = $resp->body();
                    $sig = $this->extractImageSignature($bytes);
                    if ($sig === null) {
                        $failed++;
                        continue;
                    }

                    $this->upsertSignature($pid, $item['url'], $sig);
                    $indexed++;
                }
            } catch (\Throwable $e) {
                Log::warning('Batch image indexing chunk failed: ' . $e->getMessage());
                $failed += count($chunk);
            }
        }

        Cache::forget($this->getSignatureCacheKey());

        return [
            'total' => $total,
            'indexed' => $indexed,
            'skipped' => $skipped,
            'failed' => $failed,
        ];
    }

    protected function upsertSignature(int $productId, string $imageUrl, array $sig): void
    {
        $payload = [
            'image_url' => mb_substr($imageUrl, 0, 1000),
            'dhash' => $sig['dhash'],
            'color_hist' => $sig['color_hist'],
            'indexed_at' => now(),
        ];

        if (Schema::hasColumn('product_image_signatures', 'region_hashes')) {
            $payload['region_hashes'] = $sig['region_hashes'] ?? null;
        }

        ProductImageSignature::updateOrCreate(
            ['product_id' => $productId],
            $payload
        );
    }

    protected function loadCachedSignatures(): array
    {
        return Cache::remember($this->getSignatureCacheKey(), now()->addMinutes(10), function () {
            $hasRegions = Schema::hasColumn('product_image_signatures', 'region_hashes');
            $cols = [
                'product_image_signatures.product_id',
                'product_image_signatures.dhash',
                'product_image_signatures.color_hist',
            ];
            if ($hasRegions) {
                $cols[] = 'product_image_signatures.region_hashes';
            }

            return ProductImageSignature::query()
                ->join('products', 'products.id', '=', 'product_image_signatures.product_id')
                ->where('products.status', 'published')
                ->get($cols)
                ->map(fn ($r) => [
                    'product_id' => (int) $r->product_id,
                    'dhash' => (string) $r->dhash,
                    'color_hist' => is_array($r->color_hist) ? $r->color_hist : [],
                    'region_hashes' => $hasRegions && is_array($r->region_hashes) ? $r->region_hashes : [],
                ])
                ->all();
        });
    }

    protected function readLocalImageBytes(string $path): ?string
    {
        $clean = ltrim($path, '/');
        $clean = preg_replace('/^storage\//', '', $clean);

        $candidates = [
            public_path($path),
            public_path('storage/' . $clean),
            storage_path('app/public/' . $clean),
        ];

        foreach ($candidates as $file) {
            if (is_file($file) && is_readable($file)) {
                $data = @file_get_contents($file);
                if ($data !== false && strlen($data) > 100) {
                    return $data;
                }
            }
        }

        return null;
    }

    /**
     * Compute 64-bit dHash + foreground mean RGB for a specified rectangle inside a GD image.
     */
    protected function computeDhashAndMeanRgb($gdImg, int $srcX, int $srcY, int $srcW, int $srcH): array
    {
        $srcW = max(8, $srcW);
        $srcH = max(8, $srcH);

        $thumbHash = imagecreatetruecolor(9, 8);
        imagecopyresampled($thumbHash, $gdImg, 0, 0, $srcX, $srcY, 9, 8, $srcW, $srcH);
        $hex = '';
        $nibble = 0;
        $bitPos = 0;

        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $rgb1 = imagecolorat($thumbHash, $x, $y);
                $rgb2 = imagecolorat($thumbHash, $x + 1, $y);
                $g1 = (($rgb1 >> 16) & 0xFF) * 0.299 + (($rgb1 >> 8) & 0xFF) * 0.587 + ($rgb1 & 0xFF) * 0.114;
                $g2 = (($rgb2 >> 16) & 0xFF) * 0.299 + (($rgb2 >> 8) & 0xFF) * 0.587 + ($rgb2 & 0xFF) * 0.114;

                $bit = ($g1 > $g2) ? 1 : 0;
                $nibble = ($nibble << 1) | $bit;
                $bitPos++;
                if ($bitPos === 4) {
                    $hex .= dechex($nibble);
                    $nibble = 0;
                    $bitPos = 0;
                }
            }
        }
        imagedestroy($thumbHash);

        $thumbColor = imagecreatetruecolor(24, 24);
        imagecopyresampled($thumbColor, $gdImg, 0, 0, $srcX, $srcY, 24, 24, $srcW, $srcH);
        $fgSum = [0.0, 0.0, 0.0];
        $allSum = [0.0, 0.0, 0.0];
        $fgCount = 0;
        $total = 24 * 24;

        for ($y = 0; $y < 24; $y++) {
            for ($x = 0; $x < 24; $x++) {
                $rgb = imagecolorat($thumbColor, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $allSum[0] += $r;
                $allSum[1] += $g;
                $allSum[2] += $b;

                if (!($r > 235 && $g > 235 && $b > 235)) {
                    $fgSum[0] += $r;
                    $fgSum[1] += $g;
                    $fgSum[2] += $b;
                    $fgCount++;
                }
            }
        }
        imagedestroy($thumbColor);

        if ($fgCount >= ($total * 0.05)) {
            $meanRgb = [
                (int) round($fgSum[0] / $fgCount),
                (int) round($fgSum[1] / $fgCount),
                (int) round($fgSum[2] / $fgCount),
            ];
        } else {
            $meanRgb = [
                (int) round($allSum[0] / $total),
                (int) round($allSum[1] / $total),
                (int) round($allSum[2] / $total),
            ];
        }

        return [
            'dhash' => str_pad($hex, 16, '0', STR_PAD_LEFT),
            'rgb' => $meanRgb,
        ];
    }

    /**
     * Extract 16-char hex 64-bit dHash, 64-bin foreground RGB histogram, mean RGB, and 7 overlapping sub-region hashes.
     */
    public function extractImageSignature(string $bytes): ?array
    {
        if (strlen($bytes) < 100 || !extension_loaded('gd') || !function_exists('imagecreatefromstring')) {
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

        // 1. Full image dHash + mean RGB
        $fullSig = $this->computeDhashAndMeanRgb($img, 0, 0, $w, $h);

        // 2. 64-bin L2-normalized foreground RGB histogram (48x48)
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

        // 3. 7 overlapping sub-region signatures for partial / cropped product lookup
        $regionsSpec = [
            'center' => [0.20, 0.20, 0.60, 0.60],
            'tl' => [0.00, 0.00, 0.60, 0.60],
            'tr' => [0.40, 0.00, 0.60, 0.60],
            'bl' => [0.00, 0.40, 0.60, 0.60],
            'br' => [0.40, 0.40, 0.60, 0.60],
            'left' => [0.00, 0.10, 0.55, 0.80],
            'right' => [0.45, 0.10, 0.55, 0.80],
        ];
        $regionHashes = [];
        foreach ($regionsSpec as $rKey => [$fx, $fy, $fw, $fh]) {
            $rx = (int) round($fx * $w);
            $ry = (int) round($fy * $h);
            $rw = max(8, (int) round($fw * $w));
            $rh = max(8, (int) round($fh * $h));
            if ($rx + $rw > $w) {
                $rw = max(8, $w - $rx);
            }
            if ($ry + $rh > $h) {
                $rh = max(8, $h - $ry);
            }
            $regionHashes[$rKey] = $this->computeDhashAndMeanRgb($img, $rx, $ry, $rw, $rh);
        }

        imagedestroy($img);

        $hist = ($fgCount >= ($totalCount * 0.05)) ? $fgHist : $allHist;

        $normSq = 0.0;
        foreach ($hist as $v) {
            $normSq += $v * $v;
        }
        $norm = sqrt($normSq);
        if ($norm > 0) {
            foreach ($hist as &$v) {
                $v = round($v / $norm, 4);
            }
            unset($v);
        }

        return [
            'dhash' => $fullSig['dhash'],
            'color_hist' => $hist,
            'mean_rgb' => $fullSig['rgb'],
            'region_hashes' => $regionHashes,
        ];
    }

    protected function dhashSimilarity(string $hexA, string $hexB): float
    {
        $hexA = strtolower($hexA);
        $hexB = strtolower($hexB);
        if (strlen($hexA) !== 16 || strlen($hexB) !== 16) {
            return 0.0;
        }
        $hamming = 0;
        for ($i = 0; $i < 16; $i++) {
            $xor = hexdec($hexA[$i]) ^ hexdec($hexB[$i]);
            while ($xor > 0) {
                $hamming += ($xor & 1);
                $xor >>= 1;
            }
        }
        return (1.0 - ($hamming / 64.0)) * 100.0;
    }

    protected function rgbSimilarity(array $rgbA, array $rgbB): float
    {
        if (count($rgbA) < 3 || count($rgbB) < 3) {
            return 50.0;
        }
        $dr = (float) $rgbA[0] - (float) $rgbB[0];
        $dg = (float) $rgbA[1] - (float) $rgbB[1];
        $db = (float) $rgbA[2] - (float) $rgbB[2];
        $dist = sqrt($dr * $dr + $dg * $dg + $db * $db);
        return max(0.0, min(100.0, (1.0 - ($dist / 441.673)) * 100.0));
    }

    /**
     * Compare two visual signatures with calibrated noise floors + multi-region partial-crop support.
     */
    public function compareSignaturesDetailed(array $sigA, array $sigB): array
    {
        $rawDhash = $this->dhashSimilarity((string) ($sigA['dhash'] ?? ''), (string) ($sigB['dhash'] ?? ''));

        $histA = $sigA['color_hist'] ?? [];
        $histB = $sigB['color_hist'] ?? [];
        $dot = 0.0;
        for ($i = 0; $i < 64; $i++) {
            $dot += ((float) ($histA[$i] ?? 0.0)) * ((float) ($histB[$i] ?? 0.0));
        }
        $rawColor = max(0.0, min(1.0, $dot)) * 100.0;

        $dhashCalibrated = max(0.0, min(100.0, (($rawDhash - 56.0) / 44.0) * 100.0));
        $colorCalibrated = max(0.0, min(100.0, (($rawColor - 55.0) / 45.0) * 100.0));
        $fullScore = (int) max(0, min(100, round(0.72 * $dhashCalibrated + 0.28 * $colorCalibrated)));
        $isExactVisual = ($rawDhash >= 84.0 && $rawColor >= 75.0) || ($rawDhash >= 90.0);

        $bestRegionDhash = 0.0;
        $bestRegionColor = 0.0;
        $bestRegionScore = 0;
        $queryHex = (string) ($sigA['dhash'] ?? '');
        $queryRgb = is_array($sigA['mean_rgb'] ?? null) ? $sigA['mean_rgb'] : [];

        foreach ((array) ($sigB['region_hashes'] ?? []) as $reg) {
            if (!is_array($reg) || empty($reg['dhash'])) {
                continue;
            }
            $rDhash = $this->dhashSimilarity($queryHex, (string) $reg['dhash']);
            $rRgb = !empty($queryRgb) && is_array($reg['rgb'] ?? null)
                ? $this->rgbSimilarity($queryRgb, $reg['rgb'])
                : $rawColor;
            $rDCal = max(0.0, min(100.0, (($rDhash - 56.0) / 44.0) * 100.0));
            $rCCal = max(0.0, min(100.0, (($rRgb - 55.0) / 45.0) * 100.0));
            $rScore = (int) max(0, min(100, round(0.75 * $rDCal + 0.25 * $rCCal)));

            if ($rDhash > $bestRegionDhash || ($rDhash === $bestRegionDhash && $rScore > $bestRegionScore)) {
                $bestRegionDhash = $rDhash;
                $bestRegionColor = $rRgb;
                $bestRegionScore = $rScore;
            }
        }

        $isPartialVisual = ($bestRegionDhash >= 82.0 && $bestRegionColor >= 72.0)
            || ($bestRegionDhash >= 88.0 && $bestRegionColor >= 60.0);

        return [
            'raw_dhash' => round($rawDhash, 2),
            'raw_color' => round($rawColor, 2),
            'dhash_calibrated' => round($dhashCalibrated, 2),
            'color_calibrated' => round($colorCalibrated, 2),
            'best_region_dhash' => round($bestRegionDhash, 2),
            'best_region_color' => round($bestRegionColor, 2),
            'score' => max($fullScore, $bestRegionScore),
            'is_exact_visual' => $isExactVisual,
            'is_partial_visual' => $isPartialVisual,
        ];
    }

    /**
     * Compare two visual signatures -> calibrated similarity score 0..100.
     */
    public function compareSignatures(array $sigA, array $sigB): int
    {
        return $this->compareSignaturesDetailed($sigA, $sigB)['score'];
    }

    /**
     * Check whether a whole word or exact multi-word phrase appears (blocking partial matches like non-stick / sticker).
     */
    protected function containsWordOrPhrase(string $text, string $term): bool
    {
        if ($text === '' || $term === '') {
            return false;
        }
        return preg_match('/(?<![a-z0-9-])' . preg_quote($term, '/') . '(?![a-z0-9])/i', $text) === 1;
    }

    /**
     * Query DB products semantically using phrase + core-token word-boundary matching & category alignment.
     */
    protected function findProductsBySemanticKeywords(array $detected, int $maxResults = 40): array
    {
        $brand = (!empty($detected['brand']) && strtolower((string) $detected['brand']) !== 'null')
            ? trim((string) preg_replace('/\s+/', ' ', strtolower((string) $detected['brand'])))
            : null;
        $brandWords = $brand ? array_filter(explode(' ', $brand)) : [];

        $stop = [
            'the', 'and', 'for', 'with', 'from', 'this', 'that', 'product', 'item', 'photo', 'image', 'null',
            'new', 'set', 'kit', 'pack', 'mini', 'pro', 'max', 'plus', 'smart', 'device', 'accessories', 'accessory',
            'black', 'white', 'blue', 'red', 'pink', 'green', 'gray', 'grey', 'beige', 'brown', 'gold', 'silver',
            'clear', 'style', 'design', 'women', 'men', 'kids', 'home',
        ];
        $essentialTwoChar = ['tv', '4k', '8k', 'hd', 'pc', 'ac', 'dc', 'vr', 'rc', 'uv', '3d', '2k', '5g'];

        $isAllowedToken = function (string $t) use ($stop, $essentialTwoChar, $brandWords): bool {
            if ($t === '' || in_array($t, $stop, true) || in_array($t, $brandWords, true)) {
                return false;
            }
            if (strlen($t) >= 3) {
                return true;
            }
            return in_array($t, $essentialTwoChar, true) || preg_match('/^\d+[a-z]+$/', $t) === 1;
        };

        $tokenize = function (string $str): array {
            $raw = preg_split('/[^a-z0-9]+/', strtolower($str)) ?: [];
            return array_values(array_filter($raw, fn ($w) => $w !== ''));
        };

        $normalizePhrase = function (string $str): string {
            $str = strtolower(trim($str, ".,-!?\"'()[] "));
            return trim((string) preg_replace('/\s+/', ' ', $str));
        };

        $phrasesMap = [];
        $addPhrase = function (string $raw) use (&$phrasesMap, $normalizePhrase, $stop, $brand, $brandWords) {
            $p = $normalizePhrase($raw);
            if (strlen($p) < 4 || !str_contains($p, ' ')) {
                return;
            }
            if ($brand !== null && $p === $brand) {
                return;
            }
            $words = preg_split('/\s+/', $p) ?: [];
            $meaningful = array_filter($words, fn ($w) => strlen($w) >= 2 && !in_array($w, $stop, true) && !in_array($w, $brandWords, true));
            if (count($meaningful) >= 2) {
                $phrasesMap[$p] = true;
            }
        };

        foreach ((array) ($detected['phrases'] ?? []) as $p) {
            $addPhrase((string) $p);
        }
        if (!empty($detected['core_type']) && is_string($detected['core_type'])) {
            $addPhrase($detected['core_type']);
        }
        foreach ((array) ($detected['keywords'] ?? []) as $kw) {
            $addPhrase((string) $kw);
        }
        if (!empty($detected['label']) && is_string($detected['label'])) {
            $labelWords = $tokenize($detected['label']);
            $cnt = count($labelWords);
            for ($i = 0; $i < $cnt; $i++) {
                if ($i + 1 < $cnt) {
                    $addPhrase($labelWords[$i] . ' ' . $labelWords[$i + 1]);
                }
                if ($i + 2 < $cnt) {
                    $addPhrase($labelWords[$i] . ' ' . $labelWords[$i + 1] . ' ' . $labelWords[$i + 2]);
                }
            }
        }
        $phrases = array_slice(array_keys($phrasesMap), 0, 12);

        $coreTokensMap = [];
        foreach ([$detected['core_type'] ?? '', $detected['label'] ?? ''] as $src) {
            foreach ($tokenize((string) $src) as $w) {
                if ($isAllowedToken($w)) {
                    $coreTokensMap[$w] = true;
                }
            }
        }
        $coreTokens = array_keys($coreTokensMap);

        $tokensMap = $coreTokensMap;
        foreach (array_merge($phrases, (array) ($detected['keywords'] ?? [])) as $src) {
            foreach ($tokenize((string) $src) as $w) {
                if ($isAllowedToken($w)) {
                    $tokensMap[$w] = true;
                }
            }
        }
        $tokens = array_slice(array_keys($tokensMap), 0, 12);

        if (empty($phrases) && empty($tokens)) {
            return [];
        }

        $matchedCategories = [];
        foreach (array_merge((array) ($detected['store_categories'] ?? []), (array) ($detected['category_hints'] ?? [])) as $cat) {
            $c = trim((string) preg_replace('/\s+/', ' ', strtolower((string) $cat)));
            if ($c !== '' && $c !== 'null') {
                $matchedCategories[$c] = true;
            }
        }
        $matchedCategoryList = array_keys($matchedCategories);

        $likePhrases = array_slice($phrases, 0, 6);
        $likeTokens = array_slice($tokens, 0, 8);

        $query = Product::with(['category'])
            ->where('status', 'published')
            ->where(function ($q) use ($likePhrases, $likeTokens) {
                foreach ($likePhrases as $phrase) {
                    $like = '%' . $phrase . '%';
                    $q->orWhere('name', 'like', $like)
                        ->orWhere('short_description', 'like', $like)
                        ->orWhere('tags', 'like', $like);
                }
                foreach ($likeTokens as $tok) {
                    $like = '%' . $tok . '%';
                    $q->orWhere('name', 'like', $like)
                        ->orWhere('tags', 'like', $like);
                }
            })
            ->select(['id', 'name', 'short_description', 'tags', 'category_id'])
            ->limit(200)
            ->get();

        $coreTypeNorm = !empty($detected['core_type']) ? $normalizePhrase((string) $detected['core_type']) : '';
        $scores = [];

        foreach ($query as $p) {
            $nameLower = trim((string) preg_replace('/\s+/', ' ', strtolower((string) $p->name)));
            $catLower = trim((string) preg_replace('/\s+/', ' ', strtolower((string) ($p->category->name ?? ''))));
            $tagsLower = is_array($p->tags) ? strtolower(implode(' ', $p->tags)) : strtolower((string) $p->tags);
            $descLower = strtolower((string) ($p->short_description ?? ''));
            $metaLower = trim($tagsLower . ' ' . $descLower);

            $phraseNameHits = 0;
            foreach ($phrases as $phrase) {
                if ($this->containsWordOrPhrase($nameLower, $phrase)) {
                    $phraseNameHits++;
                }
            }

            $coreTypeHit = ($coreTypeNorm !== '' && $this->containsWordOrPhrase($nameLower, $coreTypeNorm));

            $coreTokenNameHits = 0;
            foreach ($coreTokens as $ct) {
                if ($this->containsWordOrPhrase($nameLower, $ct)) {
                    $coreTokenNameHits++;
                }
            }

            $tokenNameHits = 0;
            $tokenMetaHits = 0;
            foreach ($tokens as $tok) {
                if ($this->containsWordOrPhrase($nameLower, $tok)) {
                    $tokenNameHits++;
                } elseif ($this->containsWordOrPhrase($metaLower, $tok)) {
                    $tokenMetaHits++;
                }
            }

            if ($phraseNameHits === 0 && $tokenNameHits === 0 && $tokenMetaHits < 2) {
                continue;
            }

            $brandHit = ($brand !== null && $this->containsWordOrPhrase($nameLower, $brand));

            $categoryMatch = false;
            if ($catLower !== '' && !empty($matchedCategoryList)) {
                foreach ($matchedCategoryList as $mc) {
                    if ($catLower === $mc) {
                        $categoryMatch = true;
                        break;
                    }
                    if ($catLower === 'accessories' || $mc === 'accessories') {
                        continue;
                    }
                    if ((strlen($mc) >= 4 && str_contains($catLower, $mc)) || (strlen($catLower) >= 4 && str_contains($mc, $catLower))) {
                        $categoryMatch = true;
                        break;
                    }
                }
            }

            $raw = 0.0;
            if ($phraseNameHits >= 1) {
                $raw += min(66.0, 42.0 + ($phraseNameHits - 1) * 16.0);
            }
            if ($coreTypeHit) {
                $raw += 20.0;
            }
            if (count($coreTokens) > 0 && $coreTokenNameHits > 0) {
                $raw += round(($coreTokenNameHits / min(4, count($coreTokens))) * 34.0);
            }
            $raw += min(36.0, $tokenNameHits * 11.0);
            $raw += min(12.0, $tokenMetaHits * 4.0);

            if ($brandHit && ($phraseNameHits >= 1 || $tokenNameHits >= 1)) {
                $raw += 10.0;
            }

            if ($categoryMatch) {
                $raw += 10.0;
            } elseif (!empty($matchedCategoryList) && $phraseNameHits === 0) {
                $raw *= 0.55;
            }

            $score = (int) round($raw);
            if ($phraseNameHits === 0 && $coreTokenNameHits <= 1 && $tokenNameHits <= 1) {
                $score = min($score, ($categoryMatch && $brandHit) ? 42 : 34);
            }

            $score = max(0, min(96, $score));
            if ($score >= 28) {
                $scores[$p->id] = [
                    'score' => $score,
                    'hits' => $phraseNameHits + $tokenNameHits,
                ];
            }
        }

        uasort($scores, fn (array $a, array $b) => $b['score'] <=> $a['score']);
        return array_slice($scores, 0, $maxResults, true);
    }

    /**
     * Load published/store categories to guide Vision AI tagging.
     */
    protected function getStoreCategoryNames(): array
    {
        return Cache::remember('store_image_search_categories_v1:' . $this->getTenantDbKey(), now()->addMinutes(30), function () {
            try {
                return Category::query()
                    ->pluck('name')
                    ->map(fn ($n) => trim((string) preg_replace('/\s+/', ' ', (string) $n)))
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();
            } catch (\Throwable $e) {
                return [];
            }
        });
    }

    /**
     * Analyze customer-uploaded image using multi-provider Vision AI (Gemini -> Groq -> OpenRouter).
     */
    protected function analyzeQueryImageWithAi(string $imageBytes, string $mimeType): ?array
    {
        $cacheKey = 'store_image_search_ai_v2:' . $this->getTenantDbKey() . ':' . md5($imageBytes);
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && !empty($cached['detected'])) {
            return $cached;
        }

        $geminiKey = $this->getGeminiApiKey();
        $groqKey = $this->getGroqApiKey();
        $openRouterKey = $this->getOpenRouterApiKey();

        if (!$geminiKey && !$groqKey && !$openRouterKey) {
            return null;
        }

        $storeCategories = $this->getStoreCategoryNames();
        $catLine = !empty($storeCategories)
            ? 'Store categories: ' . implode(', ', array_slice($storeCategories, 0, 40)) . "\n"
            : '';

        $prompt = "You are a precise e-commerce visual search analyzer.\n"
            . "Identify the main product shown in this customer image (focus strictly on the product itself, ignoring background).\n"
            . $catLine
            . "Return ONLY valid JSON (no markdown) with:\n"
            . "{\n"
            . "  \"label\": \"concise exact product name in 3 to 7 words\",\n"
            . "  \"core_type\": \"primary product noun or type in 1 to 3 words (e.g. streaming stick, tote bag)\",\n"
            . "  \"phrases\": [\"most specific 2-4 word search phrase\", \"second phrase\", \"third phrase\"],\n"
            . "  \"keywords\": [\"keyword1\", \"keyword2\", \"keyword3\", \"keyword4\", \"keyword5\", \"keyword6\"],\n"
            . "  \"store_categories\": [\"up to 3 exact best-matching names picked from Store categories above\"],\n"
            . "  \"category_hints\": [\"broad fallback category 1\", \"broad fallback category 2\"],\n"
            . "  \"brand\": \"brand name if clearly visible or null\",\n"
            . "  \"color\": \"dominant product color (NOT background color) or null\"\n"
            . "}";

        $b64 = base64_encode($imageBytes);
        $imagesPayload = [['mime' => $mimeType, 'b64' => $b64]];
        $rawRes = null;

        if ($geminiKey) {
            $rawRes = $this->callGeminiVision($geminiKey, $prompt, $imagesPayload, 384);
        }

        if ($rawRes === null && $groqKey) {
            $rawRes = $this->callOpenAiVision(
                'groq',
                'https://api.groq.com/openai/v1/chat/completions',
                $groqKey,
                $this->groqVisionModels,
                $prompt,
                $imagesPayload,
                [],
                384
            );
        }

        if ($rawRes === null && $openRouterKey) {
            $rawRes = $this->callOpenAiVision(
                'openrouter',
                'https://openrouter.ai/api/v1/chat/completions',
                $openRouterKey,
                $this->openRouterVisionModels,
                $prompt,
                $imagesPayload,
                ['HTTP-Referer' => config('app.url', 'http://localhost'), 'X-Title' => 'Storefront Image Search'],
                512
            );
        }

        if ($rawRes === null || !preg_match('/\{[\s\S]*\}/', $rawRes['text'], $m)) {
            return null;
        }

        $decoded = json_decode($m[0], true);
        if (!is_array($decoded) || (empty($decoded['label']) && empty($decoded['keywords']))) {
            return null;
        }

        $cleanStringArray = fn ($val, int $max): array => array_values(array_slice(
            array_filter(array_map(fn ($v) => trim((string) $v), (array) $val)),
            0,
            $max
        ));

        $result = [
            'detected' => [
                'label' => isset($decoded['label']) ? mb_substr(trim((string) $decoded['label']), 0, 80) : null,
                'core_type' => !empty($decoded['core_type']) ? mb_substr(trim((string) $decoded['core_type']), 0, 50) : null,
                'phrases' => $cleanStringArray($decoded['phrases'] ?? [], 5),
                'keywords' => $cleanStringArray($decoded['keywords'] ?? [], 8),
                'store_categories' => $cleanStringArray($decoded['store_categories'] ?? [], 3),
                'category_hints' => $cleanStringArray($decoded['category_hints'] ?? [], 3),
                'brand' => !empty($decoded['brand']) && strtolower((string) $decoded['brand']) !== 'null' ? trim((string) $decoded['brand']) : null,
                'color' => !empty($decoded['color']) && strtolower((string) $decoded['color']) !== 'null' ? trim((string) $decoded['color']) : null,
            ],
            'model' => $rawRes['model'],
        ];

        Cache::put($cacheKey, $result, now()->addHours(12));
        return $result;
    }

    /**
     * Option 2: Visually re-rank top candidates by sending Query Image + a numbered 2x3 Candidate Collage to Vision AI.
     */
    protected function rerankCandidatesWithVisionCollage(
        string $queryBytes,
        string $queryMime,
        array $ranked,
        array $exactVisualMap
    ): array {
        $topCandidates = array_slice($ranked, 0, 6);
        if (empty($topCandidates)) {
            return ['ranked' => $ranked, 'reranked' => false];
        }

        $topIds = array_map(fn ($c) => (int) $c['id'], $topCandidates);
        $cacheKey = 'store_image_search_rerank_v1:' . $this->getTenantDbKey() . ':' . md5($queryBytes) . ':' . implode(',', $topIds);
        $cachedScores = Cache::get($cacheKey);

        if (!is_array($cachedScores)) {
            $loaded = [];
            $remoteQueue = [];

            foreach ($topCandidates as $idx => $cand) {
                $primary = collect($cand['images'] ?? [])->firstWhere('is_primary', true) ?? ($cand['images'][0] ?? null);
                $rawPath = $primary ? trim((string) ($primary['image_path'] ?? '')) : '';
                if ($rawPath === '') {
                    continue;
                }
                $num = $idx + 1;
                if (!str_starts_with($rawPath, 'http://') && !str_starts_with($rawPath, 'https://')) {
                    $bytes = $this->readLocalImageBytes($rawPath);
                    if ($bytes !== null) {
                        $loaded[$num] = ['num' => $num, 'id' => (int) $cand['id'], 'bytes' => $bytes];
                    }
                } else {
                    $remoteQueue[] = ['num' => $num, 'id' => (int) $cand['id'], 'url' => $rawPath];
                }
            }

            if (!empty($remoteQueue)) {
                try {
                    $responses = Http::pool(function (Pool $pool) use ($remoteQueue) {
                        $reqs = [];
                        foreach ($remoteQueue as $item) {
                            $reqs[] = $pool->as((string) $item['num'])
                                ->withOptions([
                                    'verify' => false,
                                    'curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4],
                                ])
                                ->withHeaders([
                                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                                    'Accept' => 'image/webp,image/jpeg,image/png,image/*;q=0.8',
                                ])
                                ->timeout(5)
                                ->get($item['url']);
                        }
                        return $reqs;
                    });

                    foreach ($remoteQueue as $item) {
                        $resp = $responses[(string) $item['num']] ?? null;
                        if ($resp instanceof \Illuminate\Http\Client\Response && $resp->successful()) {
                            $bytes = $resp->body();
                            if (strlen($bytes) > 100) {
                                $loaded[$item['num']] = ['num' => $item['num'], 'id' => $item['id'], 'bytes' => $bytes];
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    Log::warning('Collage candidate fetch failed: ' . $e->getMessage());
                }
            }

            if (empty($loaded)) {
                return ['ranked' => $ranked, 'reranked' => false];
            }

            ksort($loaded);
            $collageBytes = $this->buildNumberedCandidateCollage(array_values($loaded));
            if ($collageBytes === null) {
                return ['ranked' => $ranked, 'reranked' => false];
            }

            $listLines = [];
            foreach ($loaded as $c) {
                $candItem = $topCandidates[$c['num'] - 1] ?? null;
                $listLines[] = '#' . $c['num'] . ': ' . mb_substr((string) ($candItem['name'] ?? 'Product'), 0, 75);
            }

            $prompt = "You are verifying e-commerce visual search results.\n"
                . "Image 1 is the customer's query photo (which may be zoomed or cropped to just a part of a product).\n"
                . "Image 2 is a collage of store product candidates labeled #1, #2, etc.\n"
                . "Candidate names:\n" . implode("\n", $listLines) . "\n\n"
                . "Score EACH numbered candidate from 0 to 100 strictly by visual match to the product shown in Image 1:\n"
                . "- 90-99: Exact same product (including if Image 1 is a cropped/zoomed part of that exact product).\n"
                . "- 70-89: Same product model/family or very close visual variant (different angle/color/edition).\n"
                . "- 40-69: Same general product category/type, noticeably different product design.\n"
                . "- 0-39: Visually different product or wrong item (e.g. wooden blocks vs silicone pop-it).\n"
                . "Return ONLY valid JSON: {\"scores\":{\"1\":85,\"2\":20}}";

            $imagesPayload = [
                ['mime' => $queryMime, 'b64' => base64_encode($queryBytes)],
                ['mime' => 'image/jpeg', 'b64' => base64_encode($collageBytes)],
            ];

            $geminiKey = $this->getGeminiApiKey();
            $groqKey = $this->getGroqApiKey();
            $openRouterKey = $this->getOpenRouterApiKey();
            $rawRes = null;

            if ($geminiKey) {
                $rawRes = $this->callGeminiVision($geminiKey, $prompt, $imagesPayload, 300);
            }
            if ($rawRes === null && $groqKey) {
                $rawRes = $this->callOpenAiVision(
                    'groq',
                    'https://api.groq.com/openai/v1/chat/completions',
                    $groqKey,
                    $this->groqVisionModels,
                    $prompt,
                    $imagesPayload,
                    [],
                    300
                );
            }
            if ($rawRes === null && $openRouterKey) {
                $rawRes = $this->callOpenAiVision(
                    'openrouter',
                    'https://openrouter.ai/api/v1/chat/completions',
                    $openRouterKey,
                    $this->openRouterVisionModels,
                    $prompt,
                    $imagesPayload,
                    ['HTTP-Referer' => config('app.url', 'http://localhost'), 'X-Title' => 'Storefront Image Search'],
                    400
                );
            }

            if ($rawRes === null || !preg_match('/\{[\s\S]*\}/', $rawRes['text'], $m)) {
                return ['ranked' => $ranked, 'reranked' => false];
            }

            $decoded = json_decode($m[0], true);
            $scoreMap = is_array($decoded['scores'] ?? null) ? $decoded['scores'] : (is_array($decoded) ? $decoded : null);
            if (!is_array($scoreMap)) {
                return ['ranked' => $ranked, 'reranked' => false];
            }

            $cachedScores = [];
            foreach ($loaded as $c) {
                $numStr = (string) $c['num'];
                if (isset($scoreMap[$numStr]) && is_numeric($scoreMap[$numStr])) {
                    $cachedScores[(string) $c['id']] = max(0, min(99, (int) round((float) $scoreMap[$numStr])));
                }
            }

            if (empty($cachedScores)) {
                return ['ranked' => $ranked, 'reranked' => false];
            }

            Cache::put($cacheKey, $cachedScores, now()->addHours(6));
        }

        foreach ($ranked as &$item) {
            $pidKey = (string) $item['id'];
            if (!isset($cachedScores[$pidKey])) {
                continue;
            }
            $aiVis = (int) $cachedScores[$pidKey];
            $isExactVisual = !empty($exactVisualMap[$item['id']]);
            $textScore = (int) ($item['text_score'] ?? 0);

            if ($isExactVisual && $textScore >= 40) {
                $newScore = max($item['match_score'], $aiVis);
            } else {
                $blended = (int) round(0.75 * $aiVis + 0.25 * $item['match_score']);
                if ($aiVis <= 32) {
                    $newScore = min($blended, 35);
                } elseif ($aiVis >= 88) {
                    $newScore = max($blended, $aiVis);
                } else {
                    $newScore = $blended;
                }
            }

            $item['match_score'] = max(0, min(99, $newScore));
            $item['ai_visual_score'] = $aiVis;
        }
        unset($item);

        usort($ranked, function (array $a, array $b) {
            if ($a['match_score'] !== $b['match_score']) {
                return $b['match_score'] <=> $a['match_score'];
            }
            $aiA = $a['ai_visual_score'] ?? -1;
            $aiB = $b['ai_visual_score'] ?? -1;
            if ($aiA !== $aiB) {
                return $aiB <=> $aiA;
            }
            return ($b['text_score'] ?? 0) <=> ($a['text_score'] ?? 0);
        });

        return ['ranked' => $ranked, 'reranked' => true];
    }

    /**
     * Render up to 6 candidates into a clean 2x3 numbered collage JPEG for Vision AI verification.
     */
    protected function buildNumberedCandidateCollage(array $loadedCandidates): ?string
    {
        if (!extension_loaded('gd') || !function_exists('imagecreatefromstring') || empty($loadedCandidates)) {
            return null;
        }

        $items = [];
        foreach (array_slice($loadedCandidates, 0, 6) as $cand) {
            $gd = @imagecreatefromstring($cand['bytes']);
            if ($gd !== false) {
                $items[] = ['num' => $cand['num'], 'gd' => $gd];
            }
        }
        if (empty($items)) {
            return null;
        }

        $count = count($items);
        $cols = min(3, $count);
        $rows = (int) ceil($count / $cols);

        $cellW = 240;
        $cellH = 260;
        $pad = 10;
        $headerH = 30;
        $canvasW = $cols * $cellW + ($cols + 1) * $pad;
        $canvasH = $rows * $cellH + ($rows + 1) * $pad;

        $canvas = imagecreatetruecolor($canvasW, $canvasH);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        $border = imagecolorallocate($canvas, 226, 232, 240);
        $headerBg = imagecolorallocate($canvas, 248, 250, 252);
        $badgeBg = imagecolorallocate($canvas, 15, 23, 42);
        imagefilledrectangle($canvas, 0, 0, $canvasW, $canvasH, $white);

        foreach ($items as $idx => $entry) {
            $col = $idx % $cols;
            $row = intdiv($idx, $cols);
            $x0 = $pad + $col * ($cellW + $pad);
            $y0 = $pad + $row * ($cellH + $pad);

            imagefilledrectangle($canvas, $x0, $y0, $x0 + $cellW - 1, $y0 + $headerH - 1, $headerBg);
            imagerectangle($canvas, $x0, $y0, $x0 + $cellW - 1, $y0 + $cellH - 1, $border);

            $src = $entry['gd'];
            $sw = imagesx($src);
            $sh = imagesy($src);
            $availW = $cellW - 20;
            $availH = $cellH - $headerH - 10;
            $scale = min($availW / max(1, $sw), $availH / max(1, $sh));
            $dw = max(1, (int) round($sw * $scale));
            $dh = max(1, (int) round($sh * $scale));
            $dx = $x0 + (int) round(($cellW - $dw) / 2);
            $dy = $y0 + $headerH + (int) round(($availH - $dh) / 2) + 4;
            imagecopyresampled($canvas, $src, $dx, $dy, 0, 0, $dw, $dh, $sw, $sh);
            imagedestroy($src);

            // Bold 2x+ scaled badge (#1..#6) at top-left
            $label = '#' . $entry['num'];
            $tmpW = strlen($label) * 9 + 4;
            $tmpH = 16;
            $tmp = imagecreatetruecolor($tmpW, $tmpH);
            $tmpBg = imagecolorallocate($tmp, 15, 23, 42);
            $tmpFg = imagecolorallocate($tmp, 255, 255, 255);
            imagefilledrectangle($tmp, 0, 0, $tmpW, $tmpH, $tmpBg);
            imagestring($tmp, 5, 2, 0, $label, $tmpFg);

            $scaledW = (int) round($tmpW * 1.65);
            $scaledH = (int) round($tmpH * 1.65);
            imagefilledrectangle($canvas, $x0 + 4, $y0 + 2, $x0 + 8 + $scaledW, $y0 + 4 + $scaledH, $badgeBg);
            imagecopyresized($canvas, $tmp, $x0 + 6, $y0 + 3, 0, 0, $scaledW, $scaledH, $tmpW, $tmpH);
            imagedestroy($tmp);
        }

        ob_start();
        imagejpeg($canvas, null, 82);
        $out = ob_get_clean();
        imagedestroy($canvas);

        return is_string($out) && strlen($out) > 100 ? $out : null;
    }

    protected function callGeminiVision(string $apiKey, string $prompt, array $images, int $maxOutputTokens = 384): ?array
    {
        $parts = [['text' => $prompt]];
        foreach ($images as $img) {
            if (!empty($img['b64']) && !empty($img['mime'])) {
                $parts[] = ['inlineData' => ['mimeType' => $img['mime'], 'data' => $img['b64']]];
            }
        }

        foreach ($this->geminiVisionModels as $model) {
            $cooldownKey = "gemini_model_cooldown:{$model}";
            if (Cache::has($cooldownKey)) {
                continue;
            }

            try {
                $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . $apiKey;
                $resp = Http::withHeaders(['Content-Type' => 'application/json'])
                    ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
                    ->timeout(15)
                    ->post($url, [
                        'contents' => [['parts' => $parts]],
                        'generationConfig' => ['temperature' => 0.15, 'maxOutputTokens' => $maxOutputTokens],
                    ]);

                if ($resp->successful()) {
                    $text = $resp->json()['candidates'][0]['content']['parts'][0]['text'] ?? null;
                    if (!empty($text)) {
                        return ['text' => (string) $text, 'model' => $model];
                    }
                    continue;
                }

                $status = $resp->status();
                if ($status === 429) {
                    $isDaily = str_contains($resp->body(), 'PerDay') || str_contains($resp->body(), 'RESOURCE_EXHAUSTED');
                    Cache::put($cooldownKey, true, now()->addMinutes($isDaily ? 45 : 1));
                } elseif ($status === 404) {
                    Cache::put($cooldownKey, true, now()->addHours(12));
                } elseif ($status >= 500) {
                    Cache::put($cooldownKey, true, now()->addMinutes(3));
                }
            } catch (\Throwable $e) {
                Cache::put($cooldownKey, true, now()->addMinutes(3));
            }
        }

        return null;
    }

    protected function callOpenAiVision(
        string $providerName,
        string $endpoint,
        string $apiKey,
        array $models,
        string $prompt,
        array $images,
        array $extraHeaders = [],
        int $maxTokens = 384
    ): ?array {
        $content = [['type' => 'text', 'text' => $prompt]];
        foreach ($images as $img) {
            if (!empty($img['b64']) && !empty($img['mime'])) {
                $content[] = [
                    'type' => 'image_url',
                    'image_url' => ['url' => "data:{$img['mime']};base64,{$img['b64']}"],
                ];
            }
        }

        foreach ($models as $model) {
            $cooldownKey = "ai_model_cooldown:{$providerName}:{$model}";
            if (Cache::has($cooldownKey)) {
                continue;
            }

            try {
                $headers = array_merge([
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                ], $extraHeaders);

                $resp = Http::withHeaders($headers)
                    ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
                    ->timeout(15)
                    ->post($endpoint, [
                        'model' => $model,
                        'messages' => [[
                            'role' => 'user',
                            'content' => $content,
                        ]],
                        'temperature' => 0.15,
                        'max_tokens' => $maxTokens,
                    ]);

                if ($resp->successful()) {
                    $text = $resp->json()['choices'][0]['message']['content'] ?? null;
                    if (!empty($text)) {
                        return ['text' => (string) $text, 'model' => "{$providerName}:{$model}"];
                    }
                    continue;
                }

                $status = $resp->status();
                if ($status === 429) {
                    Cache::put($cooldownKey, true, now()->addMinutes(5));
                } elseif ($status === 404 || $status === 401) {
                    Cache::put($cooldownKey, true, now()->addMinutes(30));
                }
            } catch (\Throwable $e) {
                Cache::put($cooldownKey, true, now()->addMinutes(3));
            }
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
            // Fall through
        }
        $envKey = config('services.gemini.key');
        return !empty($envKey) ? trim((string) $envKey) : null;
    }

    protected function getGroqApiKey(): ?string
    {
        try {
            $enabled = SiteSetting::getValue('groq_enabled');
            if ($enabled === '0' || $enabled === 0 || $enabled === false) {
                return null;
            }
            $siteKey = SiteSetting::getValue('groq_api_key');
            if (!empty($siteKey)) {
                return trim((string) $siteKey);
            }
        } catch (\Throwable $e) {
            // Fall through
        }
        $envKey = env('GROQ_API_KEY');
        return !empty($envKey) ? trim((string) $envKey) : null;
    }

    protected function getOpenRouterApiKey(): ?string
    {
        try {
            $enabled = SiteSetting::getValue('openrouter_enabled');
            if ($enabled === '0' || $enabled === 0 || $enabled === false) {
                return null;
            }
            $siteKey = SiteSetting::getValue('openrouter_api_key');
            if (!empty($siteKey)) {
                return trim((string) $siteKey);
            }
        } catch (\Throwable $e) {
            // Fall through
        }
        $envKey = env('OPENROUTER_API_KEY');
        return !empty($envKey) ? trim((string) $envKey) : null;
    }
}
