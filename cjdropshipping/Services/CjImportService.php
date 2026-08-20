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
                    'is_physical' => true,
                    'is_cj_product' => true,
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

                foreach ($cjData['variants'] as $v) {
                    $variantCost = $this->parsePrice($v['variantSellPrice'] ?? 0);
                    $variantPrice = round($variantCost * (1 + ($profitMarginPercent / 100)), 2);
                    $vStock = (int) ($v['variantStandardQuantity'] ?? 10);

                    ProductVariant::create([
                        'product_id' => $product->id,
                        'combination_label' => $v['variantNameEn'] ?? $v['variantKeyEn'] ?? $v['variantKey'] ?? 'Default Variant',
                        'price' => $variantPrice,
                        'discount_price' => null,
                        'sku' => $v['variantSku'] ?? ($product->sku . '-' . ($v['vid'] ?? Str::random(4))),
                        'stock' => $vStock,
                        'available_quantity' => $vStock,
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
