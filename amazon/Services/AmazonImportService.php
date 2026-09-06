<?php

namespace Amazon\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductImage;
use App\Models\Category;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Exception;

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
        float $profitMarginPercent = 15.0,
        ?int $categoryId = null,
        int $defaultStock = 20,
        string $status = 'published',
        ?callable $onProgress = null
    ): array {
        @set_time_limit(600);

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
                    $status
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
            } catch (Exception $e) {
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
        float $profitMarginPercent = 15.0,
        ?int $categoryId = null,
        int $defaultStock = 20,
        string $status = 'published'
    ): Product {
        // Load Site Settings for Amazon defaults
        $siteSettings = \App\Models\SiteSetting::where('group', 'amazon')->pluck('value', 'key')->toArray();
        if ($profitMarginPercent == 15.0 && !empty($siteSettings['amazon_default_profit_margin'])) {
            $profitMarginPercent = (float) $siteSettings['amazon_default_profit_margin'];
        }
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

        return DB::transaction(function () use ($amazonData, $profitMarginPercent, $categoryId, $defaultStock, $status, $affiliateTag) {
            $costPrice = (float) ($amazonData['price'] ?? 0.0);
            $salePrice = round($costPrice * (1 + ($profitMarginPercent / 100)), 2);
            if ($salePrice <= 0) {
                $salePrice = 59.99; // Fallback retail price if cost is 0
            }

            $asin = $amazonData['asin'] ?? $this->scraperService->extractAsin($asinOrUrl);
            $slug = Str::slug($amazonData['title']);

            // Ensure unique slug for products
            $existingSlugCount = Product::where('slug', $slug)
                ->where('amazon_asin', '!=', $asin)
                ->count();
            if ($existingSlugCount > 0) {
                $slug = $slug . '-' . Str::random(5);
            }

            // Fallback category if none specified
            $targetCategoryId = $categoryId ?: (Category::query()->value('id') ?: 1);

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
            $product = Product::updateOrCreate(
                ['amazon_asin' => $asin],
                [
                    'name' => $amazonData['title'],
                    'slug' => $slug,
                    'description' => $amazonData['description'] ?? '',
                    'short_description' => Str::limit(strip_tags($amazonData['description'] ?? ''), 160),
                    'price' => $salePrice,
                    'original_price' => $salePrice,
                    'cost_per_item' => $costPrice,
                    'sku' => $amazonData['sku'] ?? ("AMZ-" . $asin),
                    'stock_quantity' => $defaultStock,
                    'category_id' => $targetCategoryId,
                    'brand_id' => null,
                    'status' => in_array($status, ['published', 'draft']) ? $status : 'published',
                    'variant_options' => $variantOptions,
                    'is_physical' => true,
                    'is_amazon_product' => true,
                    'import_source' => 'amazon',
                    'amazon_asin' => $asin,
                    'amazon_url' => $amazonUrl,
                    'amazon_synced_at' => now(),
                ]
            );

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
                    $vPrice = round($vCost * (1 + ($profitMarginPercent / 100)), 2);
                    if ($vPrice <= 0) $vPrice = $salePrice;

                    $vLabel = $v['title'] ?? 'Default Title';
                    $vSku = $v['sku'] ?? ($product->sku . '-' . ($v['id'] ?? Str::random(4)));

                    ProductVariant::create([
                        'product_id' => $product->id,
                        'combination_label' => $vLabel,
                        'price' => $vPrice,
                        'discount_price' => null,
                        'sku' => $vSku,
                        'stock' => $defaultStock,
                        'available_quantity' => $defaultStock,
                        'color' => $v['attributes']['Color'] ?? $v['attributes']['Option'] ?? null,
                        'size' => $v['attributes']['Size'] ?? null,
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
                if (!empty($optName) && !empty($val)) {
                    $optionsCollector[ucfirst($optName)][$val] = true;
                }
            }
        }

        if (empty($optionsCollector)) {
            return null;
        }

        $formatted = [];
        foreach ($optionsCollector as $optName => $valsMap) {
            $formatted[] = [
                'name' => ucfirst($optName),
                'values' => array_values(array_keys($valsMap)),
            ];
        }

        return $formatted;
    }
}
