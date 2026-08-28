<?php

namespace CjDropshipping\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductImage;
use App\Models\Category;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Exception;

class CjImportService
{
    protected CjDropshippingService $cjService;

    public function __construct(CjDropshippingService $cjService)
    {
        $this->cjService = $cjService;
    }

    /**
     * Bulk import multiple CJ Products into Laravel MySQL Database in a single call.
     */
    public function bulkImportCjProducts(array $cjProductIds, float $profitMarginPercent = 20.0, ?int $categoryId = null): array
    {
        $importedProducts = [];
        $errors = [];

        foreach ($cjProductIds as $pid) {
            try {
                $product = $this->importCjProduct($pid, $profitMarginPercent, $categoryId);
                $importedProducts[] = [
                    'pid' => $pid,
                    'product_id' => $product->id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                    'price' => $product->price,
                ];
            } catch (Exception $e) {
                $errors[] = [
                    'pid' => $pid,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'total' => count($cjProductIds),
            'successful' => count($importedProducts),
            'failed' => count($errors),
            'imported_products' => $importedProducts,
            'errors' => $errors,
        ];
    }

    /**
     * Import or update a CJ Product into Laravel MySQL Database.
     */
    public function importCjProduct(string $cjProductId, float $profitMarginPercent = 20.0, ?int $categoryId = null): Product
    {
        $cjData = $this->cjService->getProductDetail($cjProductId);

        if (!$cjData) {
            throw new Exception("Product details not found on CJ Dropshipping for PID: {$cjProductId}");
        }

        return DB::transaction(function () use ($cjData, $profitMarginPercent, $categoryId) {
            $costPrice = $this->parsePrice($cjData['sellPrice'] ?? 0);
            $salePrice = round($costPrice * (1 + ($profitMarginPercent / 100)), 2);

            $slug = Str::slug($cjData['productNameEn'] ?? $cjData['productName'] ?? ('cj-product-' . $cjData['pid']));
            
            // Ensure unique slug
            $existingSlugCount = Product::where('slug', $slug)
                ->where('cj_product_id', '!=', $cjData['pid'])
                ->count();
            if ($existingSlugCount > 0) {
                $slug = $slug . '-' . Str::random(5);
            }

            // Fallback category if none specified (prevents NOT NULL constraint error)
            $targetCategoryId = $categoryId ?: (Category::query()->value('id') ?: 1);

            // Calculate total stock from variants if available
            $totalStock = 100;
            if (isset($cjData['variants']) && is_array($cjData['variants'])) {
                $variantStocks = array_map(function ($v) {
                    return (int) ($v['variantStandardQuantity'] ?? 10);
                }, $cjData['variants']);
                if (!empty($variantStocks)) {
                    $totalStock = max(1, array_sum($variantStocks));
                }
            }

            // Build JSON variant options array for storefront selectors
            $variantOptions = $this->buildVariantOptions($cjData);

            // Create or Update Product
            $product = Product::updateOrCreate(
                ['cj_product_id' => $cjData['pid']],
                [
                    'name' => $cjData['productNameEn'] ?? $cjData['productName'] ?? 'CJ Product',
                    'slug' => $slug,
                    'description' => $cjData['description'] ?? '',
                    'short_description' => Str::limit(strip_tags($cjData['description'] ?? ''), 160),
                    'price' => $salePrice,
                    'original_price' => $salePrice,
                    'cost_per_item' => $costPrice,
                    'sku' => $cjData['productSku'] ?? ('CJ-' . $cjData['pid']),
                    'stock_quantity' => $totalStock,
                    'category_id' => $targetCategoryId,
                    'brand_id' => null,
                    'status' => 'published',
                    'weight' => (float) ($cjData['productWeight'] ?? 0),
                    'weight_unit' => $cjData['productUnit'] ?? 'g',
                    'variant_options' => $variantOptions,
                    'is_physical' => true,
                    'is_cj_product' => true,
                    'import_source' => 'cj',
                    'cj_spu' => $cjData['productSku'] ?? null,
                    'cj_delivery_cycle' => $cjData['deliveryCycle'] ?? null,
                    'cj_synced_at' => now(),
                ]
            );

            // Import Product Images with JSON & Array parsing
            $rawMainImg = $cjData['productImage'] ?? $cjData['bigImage'] ?? null;
            $rawGalleryImgs = $cjData['productImageSet'] ?? [];

            $parsedImages = array_unique(array_merge(
                $this->parseImageUrls($rawMainImg),
                $this->parseImageUrls($rawGalleryImgs)
            ));

            if (!empty($parsedImages)) {
                // Delete previous images if re-importing
                $product->images()->delete();

                foreach (array_values($parsedImages) as $index => $imgUrl) {
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
            if (isset($cjData['variants']) && is_array($cjData['variants'])) {
                // Delete previous variants
                $product->variants()->delete();

                $productName = trim($cjData['productNameEn'] ?? $cjData['productName'] ?? '');

                foreach ($cjData['variants'] as $v) {
                    $variantCost = $this->parsePrice($v['variantSellPrice'] ?? 0);
                    $variantPrice = round($variantCost * (1 + ($profitMarginPercent / 100)), 2);
                    $vStock = (int) ($v['variantStandardQuantity'] ?? 10);

                    $rawLabel = $v['variantNameEn'] ?? $v['variantKeyEn'] ?? $v['variantKey'] ?? 'Default Variant';
                    $cleanLabel = $rawLabel;
                    if (!empty($productName)) {
                        $cleanLabel = trim(preg_replace('/^' . preg_quote($productName, '/') . '\s*/i', '', $rawLabel));
                    }

                    $colorVal = null;
                    $sizeVal = null;

                    if (preg_match('/^(.*?)\s+(XS|S|M|L|XL|2XL|XXL|3XL|4XL|5XL|6XL)$/i', $cleanLabel, $m)) {
                        $colorVal = trim($m[1]);
                        $sizeVal = strtoupper(trim($m[2]));
                    }

                    ProductVariant::create([
                        'product_id' => $product->id,
                        'combination_label' => $cleanLabel,
                        'price' => $variantPrice,
                        'discount_price' => null,
                        'sku' => $v['variantSku'] ?? ($product->sku . '-' . ($v['vid'] ?? Str::random(4))),
                        'stock' => $vStock,
                        'available_quantity' => $vStock,
                        'color' => $colorVal,
                        'size' => $sizeVal,
                        'cj_vid' => $v['vid'] ?? null,
                        'cj_variant_sku' => $v['variantSku'] ?? null,
                        'cj_weight' => (float) ($v['variantWeight'] ?? 0),
                    ]);
                }
            }

            return $product;
        });
    }

    /**
     * Build variant_options JSON structure for Product model from CJ API data.
     */
    protected function buildVariantOptions(array $cjData): ?array
    {
        if (empty($cjData['variants']) || !is_array($cjData['variants'])) {
            return null;
        }

        $optionsCollector = [];
        $productName = trim($cjData['productNameEn'] ?? $cjData['productName'] ?? '');

        // Check if CJ provides explicit option keys, e.g. "Color,Size"
        $rawKeys = $cjData['variantKeyEn'] ?? $cjData['productKeyEn'] ?? $cjData['variantKey'] ?? $cjData['productKey'] ?? null;
        $keyNames = [];
        if ($rawKeys && is_string($rawKeys)) {
            $keyNames = array_map('trim', preg_split('/[,;\/-]+/', $rawKeys));
        }

        foreach ($cjData['variants'] as $v) {
            $label = $v['variantNameEn'] ?? $v['variantKeyEn'] ?? $v['variantKey'] ?? $v['variantName'] ?? '';
            if (empty($label)) continue;

            // Strip leading product name if present
            if (!empty($productName)) {
                $cleanLabel = trim(preg_replace('/^' . preg_quote($productName, '/') . '\s*/i', '', $label));
                if (!empty($cleanLabel)) {
                    $label = $cleanLabel;
                }
            }

            // Check if label has key-value pairs like "Color: Black, Size: M"
            if (str_contains($label, ':')) {
                $pairs = preg_split('/[,;\n]+/', $label);
                foreach ($pairs as $p) {
                    if (str_contains($p, ':')) {
                        [$k, $val] = explode(':', $p, 2);
                        $k = trim($k);
                        $val = trim($val);
                        if (!empty($k) && !empty($val)) {
                            $optionsCollector[ucfirst($k)][$val] = true;
                        }
                    }
                }
            } else if (preg_match('/^(.*?)\s+(XS|S|M|L|XL|2XL|XXL|3XL|4XL|5XL|6XL)$/i', $label, $matches)) {
                // Intelligent Color & Size Extractor
                $color = trim($matches[1]);
                $size = strtoupper(trim($matches[2]));
                if (!empty($color)) {
                    $optionsCollector['Color'][$color] = true;
                }
                $optionsCollector['Size'][$size] = true;
            } else {
                // Split label by ' / ', '-', ','
                $parts = array_map('trim', preg_split('/[\/\-,]+/', $label));
                foreach ($parts as $idx => $part) {
                    if (empty($part)) continue;
                    $optName = $keyNames[$idx] ?? (count($parts) === 1 ? 'Option' : ('Option ' . ($idx + 1)));
                    $optionsCollector[ucfirst($optName)][$part] = true;
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

    /**
     * Safely parse image URLs handling JSON string arrays, comma separated strings, and arrays.
     */
    protected function parseImageUrls($imgData): array
    {
        if (empty($imgData)) return [];

        if (is_array($imgData)) {
            $urls = [];
            foreach ($imgData as $item) {
                $urls = array_merge($urls, $this->parseImageUrls($item));
            }
            return $urls;
        }

        if (is_string($imgData)) {
            $trimmed = trim($imgData);
            if (empty($trimmed)) return [];

            if (str_starts_with($trimmed, '[') || str_starts_with($trimmed, '{')) {
                $decoded = json_decode($trimmed, true);
                if ($decoded) {
                    return $this->parseImageUrls($decoded);
                }
            }

            if (str_contains($trimmed, ',')) {
                return array_map('trim', explode(',', $trimmed));
            }

            if (str_starts_with($trimmed, 'http://') || str_starts_with($trimmed, 'https://') || str_starts_with($trimmed, '//')) {
                return [str_starts_with($trimmed, '//') ? "https:{$trimmed}" : $trimmed];
            }

            return [$trimmed];
        }

        return [];
    }

    /**
     * Clean and parse price strings into floats.
     */
    protected function parsePrice($price): float
    {
        if (is_numeric($price)) {
            return (float) $price;
        }

        if (is_string($price)) {
            $cleaned = preg_replace('/[^\d.]/', '', $price);
            return (float) $cleaned;
        }

        return 0.0;
    }
}
