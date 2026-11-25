<?php

namespace Amazon\Services;

use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductImage;
use App\Models\Category;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Exception;
use Throwable;

class AmazonImportService
{
    protected AmazonScraperService $scraperService;

    public function __construct(AmazonScraperService $scraperService)
    {
        $this->scraperService = $scraperService;
    }

    /**
     * Bulk import multiple Amazon Products into Laravel MySQL Database.
     */
    public function bulkImportAmazonProducts(
        array $asinsOrUrls,
        float $profitMarginPercent = 0.0,
        ?int $categoryId = null,
        int $defaultStock = 20,
        string $status = 'published',
        ?callable $onProgress = null,
        string $markupType = 'percentage',
        float $fixedMarkupAmount = 0.0
    ): array {
        @set_time_limit(PHP_SAPI === 'cli' ? 0 : 600);

        $importedProducts = [];
        $errors = [];
        $total = count($asinsOrUrls);

        foreach ($asinsOrUrls as $index => $item) {
            if ($index > 0) {
                usleep(1500000); // 1.5s delay between requests
            }

            try {
                $product = $this->importAmazonProduct(
                    $item,
                    $profitMarginPercent,
                    $categoryId,
                    $defaultStock,
                    $status,
                    $markupType,
                    $fixedMarkupAmount
                );

                $itemResult = [
                    'asin_or_url' => $item,
                    'status' => 'completed',
                    'product_id' => $product->id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                    'price' => $product->price,
                ];
                $importedProducts[] = $itemResult;

                if (is_callable($onProgress)) {
                    $onProgress($index + 1, $total, $itemResult);
                }
            } catch (Throwable $e) {
                $itemError = [
                    'asin_or_url' => $item,
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                ];
                $errors[] = $itemError;

                if (is_callable($onProgress)) {
                    $onProgress($index + 1, $total, $itemError);
                }
            }
        }

        return [
            'total' => $total,
            'successful' => count($importedProducts),
            'failed' => count($errors),
            'imported_products' => $importedProducts,
            'errors' => $errors,
        ];
    }

    /**
     * Import or update an Amazon Product into Laravel MySQL Database.
     */
    public function importAmazonProduct(
        string $asinOrUrl,
        float $profitMarginPercent = 0.0,
        ?int $categoryId = null,
        int $defaultStock = 20,
        string $status = 'published',
        string $markupType = 'percentage',
        float $fixedMarkupAmount = 0.0
    ): Product {
        // Load Site Settings for Amazon defaults
        $siteSettings = \App\Models\SiteSetting::where('group', 'amazon')->pluck('value', 'key')->toArray();
        if ($defaultStock == 20 && !empty($siteSettings['amazon_default_stock'])) {
            $defaultStock = (int) $siteSettings['amazon_default_stock'];
        }
        if ($status == 'published' && !empty($siteSettings['amazon_default_status'])) {
            $status = $siteSettings['amazon_default_status'];
        }
        $affiliateTag = $siteSettings['amazon_affiliate_tag'] ?? '';

        $amazonData = $this->scraperService->getProductDetail($asinOrUrl);

        if (empty($amazonData) || empty($amazonData['title'])) {
            throw new Exception("Product details not found or title empty for Amazon item: {$asinOrUrl}");
        }

        return DB::transaction(function () use ($asinOrUrl, $amazonData, $profitMarginPercent, $categoryId, $defaultStock, $status, $affiliateTag, $markupType, $fixedMarkupAmount) {
            $asin = $amazonData['asin'] ?? $this->scraperService->extractAsin($asinOrUrl);
            $costPrice = (float) ($amazonData['price'] ?? 0.0);

            if ($costPrice <= 0 && !empty($amazonData['variants']) && is_array($amazonData['variants'])) {
                $positiveVarPrices = array_values(array_filter(array_map(
                    fn($v) => (float) ($v['price'] ?? 0.0),
                    $amazonData['variants']
                ), fn($p) => $p > 0));
                if (!empty($positiveVarPrices)) {
                    $costPrice = min($positiveVarPrices);
                }
            }

            if ($costPrice <= 0) {
                throw new Exception("Unable to extract a valid price from Amazon for ASIN {$asin}. The product may be out of stock or currently unavailable.");
            }

            if ($markupType === 'fixed') {
                $salePrice = round($costPrice + $fixedMarkupAmount, 2);
            } else {
                $salePrice = round($costPrice * (1 + ($profitMarginPercent / 100)), 2);
            }

            $listCost = (float) ($amazonData['list_price'] ?? 0.0);
            if ($listCost > $costPrice) {
                $originalPrice = ($markupType === 'fixed')
                    ? round($listCost + $fixedMarkupAmount, 2)
                    : round($listCost * (1 + ($profitMarginPercent / 100)), 2);
            } else {
                $originalPrice = $salePrice;
            }

            $existingAmazonProduct = Product::where('amazon_asin', $asin)->first();

            // Preserve existing slug when updating, or generate safe & unique slug
            if ($existingAmazonProduct && !empty($existingAmazonProduct->slug)) {
                $slug = $existingAmazonProduct->slug;
            } else {
                $titleName = $amazonData['title'] ?? ('amazon-product-' . $asin);
                $baseSlug = Str::limit(Str::slug($titleName), 120, '');
                $slug = $baseSlug;

                while (Product::where('slug', $slug)->where('amazon_asin', '!=', $asin)->exists()) {
                    $slug = $baseSlug . '-' . Str::random(6);
                }
            }

            // Preserve existing category on re-import if caller omitted categoryId
            $targetCategoryId = $categoryId
                ?: ($existingAmazonProduct?->category_id)
                ?: (Category::query()->value('id') ?: 1);

            // Resolve Brand entity if brand name is valid
            $brandId = $existingAmazonProduct?->brand_id ?: null;
            $rawBrand = trim((string) ($amazonData['brand'] ?? ''));
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

            // Build JSON variant options array
            $variantOptions = $this->buildVariantOptions($amazonData);

            // Build Amazon URL with Affiliate Tag if configured
            $amazonUrl = $amazonData['url'] ?? "https://www.amazon.com/dp/{$asin}";
            if (!empty($affiliateTag)) {
                $separator = str_contains($amazonUrl, '?') ? '&' : '?';
                if (!str_contains($amazonUrl, 'tag=')) {
                    $amazonUrl .= "{$separator}tag=" . urlencode($affiliateTag);
                }
            }

            // Create or Update Product in DB
            $resolvedMarkupType = ($markupType === 'fixed') ? 'fixed' : 'percentage';
            $resolvedMarkupValue = $resolvedMarkupType === 'fixed' ? $fixedMarkupAmount : $profitMarginPercent;

            $realProductStock = $defaultStock;
            if (!empty($amazonData['variants']) && is_array($amazonData['variants'])) {
                $realProductStock = array_sum(array_map(function ($v) use ($defaultStock) {
                    if (isset($v['available']) && $v['available'] === false) return 0;
                    return isset($v['stock']) && is_numeric($v['stock']) ? max(0, (int)$v['stock']) : $defaultStock;
                }, $amazonData['variants']));
            } elseif (isset($amazonData['available']) && $amazonData['available'] === false) {
                $realProductStock = 0;
            } elseif (isset($amazonData['stock']) && is_numeric($amazonData['stock'])) {
                $realProductStock = max(0, (int)$amazonData['stock']);
            }

            $rating = min(5.0, max(0.0, round((float) ($amazonData['rating'] ?? 0.0), 2)));
            $reviewCount = max(0, (int) ($amazonData['review_count'] ?? 0));

            $productPayload = [
                'name' => $amazonData['title'],
                'slug' => $slug,
                'description' => $amazonData['description'] ?? '',
                'short_description' => Str::limit(strip_tags($amazonData['description'] ?? ''), 160),
                'price' => $salePrice,
                'original_price' => $originalPrice,
                'cost_per_item' => $costPrice,
                'sync_markup_type' => $resolvedMarkupType,
                'sync_markup_value' => $resolvedMarkupValue,
                'sku' => $amazonData['sku'] ?? ("AMZ-" . $asin),
                'stock_quantity' => $realProductStock,
                'category_id' => $targetCategoryId,
                'brand_id' => $brandId,
                'status' => in_array($status, ['published', 'draft']) ? $status : 'published',
                'rating' => $rating,
                'review_count' => $reviewCount,
                'weight' => $amazonData['weight'] ?? null,
                'weight_unit' => $amazonData['weight_unit'] ?? 'kg',
                'width' => $amazonData['width'] ?? null,
                'width_unit' => $amazonData['width_unit'] ?? 'in',
                'height' => $amazonData['height'] ?? null,
                'height_unit' => $amazonData['height_unit'] ?? 'in',
                'variant_options' => $variantOptions,
                'is_physical' => array_key_exists('is_physical', $amazonData) ? (bool) $amazonData['is_physical'] : true,
                'is_amazon_product' => true,
                'import_source' => 'amazon',
                'amazon_asin' => $asin,
                'amazon_url' => $amazonUrl,
                'amazon_synced_at' => now(),
            ];

            try {
                $product = Product::updateOrCreate(['amazon_asin' => $asin], $productPayload);
            } catch (\Illuminate\Database\QueryException $e) {
                // If duplicate product, slug or SKU collision occurs, update existing product & mark SUCCESS
                $existingProduct = Product::where('amazon_asin', $asin)
                    ->orWhere('sku', $productPayload['sku'])
                    ->first();

                if ($existingProduct) {
                    $existingProduct->update([
                        'name' => $amazonData['title'],
                        'description' => $productPayload['description'],
                        'short_description' => $productPayload['short_description'],
                        'price' => $salePrice,
                        'original_price' => $originalPrice,
                        'cost_per_item' => $costPrice,
                        'sync_markup_type' => $resolvedMarkupType,
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

            // Import Product Images
            if (!empty($amazonData['images']) && is_array($amazonData['images'])) {
                // Remove previous images if updating
                $product->images()->delete();

                foreach (array_values($amazonData['images']) as $index => $imgUrl) {
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

            // Import Variants
            if (!empty($amazonData['variants']) && is_array($amazonData['variants'])) {
                $product->variants()->delete();

                foreach ($amazonData['variants'] as $v) {
                    $vCost = (float) ($v['price'] ?? $costPrice);
                    if ($markupType === 'fixed') {
                        $vPrice = round($vCost + $fixedMarkupAmount, 2);
                    } else {
                        $vPrice = round($vCost * (1 + ($profitMarginPercent / 100)), 2);
                    }
                    if ($vPrice <= 0) $vPrice = $salePrice;

                    $vLabel = $v['title'] ?? 'Default Title';
                    $vSku = $v['sku'] ?? ($product->sku . '-' . ($v['id'] ?? Str::random(4)));
                    $vStock = (isset($v['available']) && $v['available'] === false)
                        ? 0
                        : (isset($v['stock']) && is_numeric($v['stock']) ? max(0, (int)$v['stock']) : $defaultStock);

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
     * Build variant_options JSON structure for Product model from Amazon data.
     */
    protected function buildVariantOptions(array $amazonData): ?array
    {
        if (empty($amazonData['variants']) || !is_array($amazonData['variants'])) {
            return null;
        }

        $optionsCollector = [];

        foreach ($amazonData['variants'] as $v) {
            if (empty($v['attributes']) || !is_array($v['attributes'])) {
                continue;
            }
            foreach ($v['attributes'] as $optName => $val) {
                $cleanOpt = trim((string) $optName);
                $cleanVal = trim((string) $val);
                if (!empty($cleanOpt) && !empty($cleanVal)) {
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
            // Sort numeric counts (e.g. 32, 96, 164, 198) or "Size N" labels naturally
            $allNumeric = count($vals) > 1 && collect($vals)->every(fn($v) => is_numeric(trim((string) $v)));
            if ($allNumeric) {
                usort($vals, fn($a, $b) => (float) $a <=> (float) $b);
            } else {
                $hasNumberedSizes = count($vals) > 1 && collect($vals)->contains(fn($v) => (bool) preg_match('/^size\s+\d+/i', (string) $v));
                if ($hasNumberedSizes) {
                    usort($vals, fn($a, $b) => strnatcasecmp((string) $a, (string) $b));
                }
            }
            $formatted[] = [
                'name' => ucfirst($optName),
                'values' => $vals,
            ];
        }

        return $formatted;
    }
}
