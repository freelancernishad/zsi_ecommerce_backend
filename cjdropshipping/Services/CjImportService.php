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
    public function bulkImportCjProducts(
        array $cjProductIds,
        float $profitMarginPercent = 0.0,
        ?int $categoryId = null,
        ?callable $onProgress = null
    ): array {
        @set_time_limit(600);

        $importedProducts = [];
        $errors = [];
        $total = count($cjProductIds);

        foreach ($cjProductIds as $index => $pid) {
            if ($index > 0) {
                usleep(1200000); // 1.2s delay to satisfy CJ API 1 QPS rate limit
            }

            try {
                $product = $this->importCjProduct($pid, $profitMarginPercent, $categoryId);
                $itemResult = [
                    'pid' => $pid,
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
                    'pid' => $pid,
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
     * Import or update a CJ Product into Laravel MySQL Database.
     */
    public function importCjProduct(string $cjProductId, ?float $profitMarginPercent = null, ?int $categoryId = null): Product
    {
        $cjData = $this->cjService->getProductDetail($cjProductId);

        if (!$cjData) {
            throw new Exception("Product details not found on CJ Dropshipping for PID: {$cjProductId}");
        }

        $existing = Product::where('cj_product_id', $cjProductId)->first();

        if ($profitMarginPercent !== null) {
            $resolvedMargin = (float) $profitMarginPercent;
        } elseif ($existing && $existing->sync_markup_type === 'percentage' && $existing->sync_markup_value !== null) {
            $resolvedMargin = (float) $existing->sync_markup_value;
        } elseif ($existing && (float) ($existing->cost_per_item ?? 0) > 0 && (float) $existing->price > (float) $existing->cost_per_item) {
            $resolvedMargin = round((((float) $existing->price - (float) $existing->cost_per_item) / (float) $existing->cost_per_item) * 100, 2);
        } else {
            $resolvedMargin = 0.0;
        }

        return DB::transaction(function () use ($cjData, $resolvedMargin, $categoryId, $existing) {
            $costPrice = $this->parsePrice($cjData['sellPrice'] ?? 0);
            $salePrice = round($costPrice * (1 + ($resolvedMargin / 100)), 2);

            $pid = $cjData['pid'] ?? '';
            $productName = $cjData['productNameEn'] ?? $cjData['productName'] ?? ('cj-product-' . $pid);

            // Preserve existing slug when updating, or generate a safe unique slug
            if ($existing && !empty($existing->slug)) {
                $slug = $existing->slug;
            } else {
                $baseSlug = Str::limit(Str::slug($productName), 120, '');
                $slug = $baseSlug;

                while (Product::where('slug', $slug)->where('cj_product_id', '!=', $pid)->exists()) {
                    $slug = $baseSlug . '-' . Str::random(6);
                }
            }

            // Preserve existing category or use requested/fallback category
            $targetCategoryId = $categoryId ?: ($existing?->category_id ?: (Category::query()->value('id') ?: 1));

            // Calculate real total stock from CJ variants or product stock
            $totalStock = (int) ($cjData['productStock'] ?? $cjData['storageNum'] ?? $cjData['inventoryNum'] ?? 0);
            if (isset($cjData['variants']) && is_array($cjData['variants']) && count($cjData['variants']) > 0) {
                $variantStocks = array_map(function ($v) {
                    foreach (['stock', 'variantStandardQuantity', 'storageNum', 'inventoryNum'] as $stockKey) {
                        if (isset($v[$stockKey]) && is_numeric($v[$stockKey])) {
                            return max(0, (int) $v[$stockKey]);
                        }
                    }
                    return 0;
                }, $cjData['variants']);
                $totalStock = array_sum($variantStocks);
            }

            // Build JSON variant options array for storefront selectors
            $variantOptions = $this->buildVariantOptions($cjData);
            $shortDesc = $this->extractCleanShortDescription($cjData['description'] ?? '');

            // Create or Update Product
            $cjPayload = [
                'name' => $cjData['productNameEn'] ?? $cjData['productName'] ?? 'CJ Product',
                'slug' => $slug,
                'description' => $cjData['description'] ?? '',
                'short_description' => $shortDesc,
                'price' => $salePrice,
                'original_price' => $salePrice,
                'cost_per_item' => $costPrice,
                'sku' => $cjData['productSku'] ?? ('CJ-' . $cjData['pid']),
                'stock_quantity' => $totalStock,
                'category_id' => $targetCategoryId,
                'brand_id' => null,
                'status' => $existing?->status ?: 'published',
                'weight' => (float) ($cjData['productWeight'] ?? 0),
                'weight_unit' => $cjData['productUnit'] ?? 'g',
                'variant_options' => $variantOptions,
                'is_physical' => true,
                'is_cj_product' => true,
                'import_source' => 'cj',
                'cj_spu' => $cjData['productSku'] ?? null,
                'cj_delivery_cycle' => $cjData['deliveryCycle'] ?? null,
                'cj_synced_at' => now(),
            ];

            try {
                $product = Product::updateOrCreate(['cj_product_id' => $cjData['pid']], $cjPayload);
            } catch (\Illuminate\Database\QueryException $e) {
                // If duplicate product, slug or SKU collision occurs, update existing product & mark SUCCESS
                $existingProduct = Product::where('cj_product_id', $cjData['pid'])
                    ->orWhere('sku', $cjPayload['sku'])
                    ->first();

                if ($existingProduct) {
                    $existingProduct->update([
                        'price' => $salePrice,
                        'original_price' => $salePrice,
                        'cost_per_item' => $costPrice,
                        'short_description' => $shortDesc,
                        'variant_options' => $variantOptions,
                        'stock_quantity' => $totalStock,
                        'cj_synced_at' => now(),
                    ]);
                    $product = $existingProduct;
                } else {
                    $cjPayload['slug'] = Str::limit($slug, 90, '') . '-' . uniqid() . '-' . rand(100, 999);
                    $product = Product::create($cjPayload);
                }
            }

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
                $rawKeys = $cjData['variantKeyEn'] ?? $cjData['productKeyEn'] ?? $cjData['variantKey'] ?? $cjData['productKey'] ?? null;
                $keyNames = ($rawKeys && is_string($rawKeys))
                    ? array_values(array_filter(array_map('trim', preg_split('/[,;\/-]+/', $rawKeys))))
                    : [];

                foreach ($cjData['variants'] as $v) {
                    $variantCost = $this->parsePrice($v['variantSellPrice'] ?? 0);
                    $variantPrice = round($variantCost * (1 + ($resolvedMargin / 100)), 2);

                    $vStock = 0;
                    foreach (['stock', 'variantStandardQuantity', 'storageNum', 'inventoryNum'] as $stockKey) {
                        if (isset($v[$stockKey]) && is_numeric($v[$stockKey])) {
                            $vStock = max(0, (int) $v[$stockKey]);
                            break;
                        }
                    }

                    $resolvedLabel = $this->resolveVariantLabel($v, $productName);
                    $parsedAttr = $this->parseVariantAttributes($resolvedLabel, $keyNames);

                    $vImage = null;
                    $rawVarImg = $v['variantImage'] ?? $v['image'] ?? null;
                    if (is_string($rawVarImg) && trim($rawVarImg) !== '') {
                        $parsedVarImgs = $this->parseImageUrls($rawVarImg);
                        $vImage = $parsedVarImgs[0] ?? trim($rawVarImg);
                    }

                    ProductVariant::create([
                        'product_id' => $product->id,
                        'combination_label' => $parsedAttr['combination_label'],
                        'price' => $variantPrice,
                        'discount_price' => null,
                        'sku' => $v['variantSku'] ?? ($product->sku . '-' . ($v['vid'] ?? Str::random(4))),
                        'stock' => $vStock,
                        'available_quantity' => $vStock,
                        'color' => $parsedAttr['color'],
                        'size' => $parsedAttr['size'],
                        'image' => $vImage,
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
     * Resolve first non-empty variant label from CJ variant payload (handles empty-string variantNameEn: "").
     */
    public function resolveVariantLabel(array $v, string $productName = ''): string
    {
        $label = '';
        foreach (['variantNameEn', 'variantKeyEn', 'variantKey', 'variantName'] as $field) {
            $candidate = trim((string) ($v[$field] ?? ''));
            if ($candidate !== '') {
                $label = $candidate;
                break;
            }
        }

        if ($label !== '' && $productName !== '') {
            $stripped = trim(preg_replace('/^' . preg_quote($productName, '/') . '\s*/i', '', $label));
            if ($stripped !== '') {
                $label = $stripped;
            }
        }

        $label = trim($label, " \t\n\r\0\x0B-/");

        return $label !== '' ? $label : 'Default Variant';
    }

    /**
     * Parse structured attributes, color, size, and normalized combination_label from a CJ variant label.
     */
    public function parseVariantAttributes(string $label, array $keyNames = []): array
    {
        $label = trim($label);
        if ($label === '' || strcasecmp($label, 'Default Variant') === 0) {
            return [
                'attributes' => [],
                'color' => null,
                'size' => null,
                'combination_label' => 'Default Variant',
            ];
        }

        $attributes = [];
        $color = null;
        $size = null;

        if (str_contains($label, ':')) {
            $pairs = preg_split('/[,;\n]+/', $label);
            foreach ($pairs as $p) {
                if (str_contains($p, ':')) {
                    [$k, $val] = explode(':', $p, 2);
                    $k = ucfirst(trim($k));
                    $val = trim($val);
                    if ($k !== '' && $val !== '') {
                        $attributes[$k] = $val;
                        $lowerK = strtolower($k);
                        if (($lowerK === 'color' || $lowerK === 'colour') && $color === null) {
                            $color = $val;
                        } elseif ($lowerK === 'size' && $size === null) {
                            $size = $val;
                        }
                    }
                }
            }
            $comb = !empty($attributes) ? implode(' / ', array_values($attributes)) : $label;
            return [
                'attributes' => $attributes,
                'color' => $color,
                'size' => $size,
                'combination_label' => $comb,
            ];
        }

        if (preg_match('/^(.*?)[\s\-\/]+(XXS|XS|S|M|L|XL|2XL|XXL|3XL|4XL|5XL|6XL)$/i', $label, $m)) {
            $color = trim($m[1], " \t\n\r\0\x0B-/");
            $size = strtoupper(trim($m[2]));
            if ($color !== '') {
                $attributes['Color'] = $color;
            }
            $attributes['Size'] = $size;
            return [
                'attributes' => $attributes,
                'color' => $color !== '' ? $color : null,
                'size' => $size,
                'combination_label' => $color !== '' ? "{$color} / {$size}" : $size,
            ];
        }

        if (preg_match('/^(XXS|XS|S|M|L|XL|2XL|XXL|3XL|4XL|5XL|6XL)$/i', $label)) {
            $size = strtoupper($label);
            $attributes['Size'] = $size;
            return [
                'attributes' => $attributes,
                'color' => null,
                'size' => $size,
                'combination_label' => $size,
            ];
        }

        $parts = array_values(array_filter(array_map('trim', preg_split('/\s*[\/\-,]\s*/', $label)), fn($p) => $p !== ''));
        if (empty($parts)) {
            return [
                'attributes' => [],
                'color' => null,
                'size' => null,
                'combination_label' => $label,
            ];
        }

        foreach ($parts as $idx => $part) {
            $optName = !empty($keyNames[$idx])
                ? ucfirst(trim((string) $keyNames[$idx]))
                : (count($parts) === 1 ? 'Option' : ('Option ' . ($idx + 1)));
            $attributes[$optName] = $part;
            $lowerOpt = strtolower($optName);
            if (($lowerOpt === 'color' || $lowerOpt === 'colour') && $color === null) {
                $color = $part;
            } elseif ($lowerOpt === 'size' && $size === null) {
                $size = $part;
            }
        }

        return [
            'attributes' => $attributes,
            'color' => $color,
            'size' => $size,
            'combination_label' => implode(' / ', $parts),
        ];
    }

    /**
     * Extract clean single-line short description or null when CJ HTML only has images/whitespace.
     */
    public function extractCleanShortDescription(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }
        $plain = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $clean = trim((string) preg_replace('/\s+/u', ' ', $plain));
        if ($clean === '') {
            return null;
        }
        return Str::limit($clean, 160);
    }

    /**
     * Build variant_options JSON structure for Product model from CJ API data.
     */
    public function buildVariantOptions(array $cjData): ?array
    {
        if (empty($cjData['variants']) || !is_array($cjData['variants'])) {
            return null;
        }

        $optionsCollector = [];
        $productName = trim((string) ($cjData['productNameEn'] ?? $cjData['productName'] ?? ''));

        $rawKeys = $cjData['variantKeyEn'] ?? $cjData['productKeyEn'] ?? $cjData['variantKey'] ?? $cjData['productKey'] ?? null;
        $keyNames = ($rawKeys && is_string($rawKeys))
            ? array_values(array_filter(array_map('trim', preg_split('/[,;\/-]+/', $rawKeys))))
            : [];

        foreach ($cjData['variants'] as $v) {
            $label = $this->resolveVariantLabel($v, $productName);
            $parsed = $this->parseVariantAttributes($label, $keyNames);
            foreach ($parsed['attributes'] as $optName => $val) {
                $optionsCollector[ucfirst($optName)][$val] = true;
            }
        }

        if (empty($optionsCollector)) {
            return null;
        }

        $sizeOrder = ['XXS' => 1, 'XS' => 2, 'S' => 3, 'M' => 4, 'L' => 5, 'XL' => 6, '2XL' => 7, 'XXL' => 8, '3XL' => 9, '4XL' => 10, '5XL' => 11, '6XL' => 12];

        $formatted = [];
        foreach ($optionsCollector as $optName => $valsMap) {
            $vals = array_values(array_map('strval', array_keys($valsMap)));
            if (strtolower($optName) === 'size' && count($vals) > 1) {
                $allClothingSizes = collect($vals)->every(fn($v) => isset($sizeOrder[strtoupper(trim($v))]));
                if ($allClothingSizes) {
                    usort($vals, fn($a, $b) => ($sizeOrder[strtoupper(trim($a))] ?? 999) <=> ($sizeOrder[strtoupper(trim($b))] ?? 999));
                }
            }
            $formatted[] = [
                'name' => ucfirst($optName),
                'values' => $vals,
            ];
        }

        return $formatted;
    }

    /**
     * Safely parse image URLs handling JSON string arrays, comma separated strings, and arrays.
     */
    public function parseImageUrls($imgData): array
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
