<?php

namespace AliExpress\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductImage;
use App\Models\Category;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Exception;

class AliExpressImportService
{
    protected AliExpressService $aliExpressService;

    public function __construct(AliExpressService $aliExpressService)
    {
        $this->aliExpressService = $aliExpressService;
    }

    /**
     * Bulk import multiple AliExpress Products into Laravel database.
     */
    public function bulkImportAliExpressProducts(
        array $productIdsOrUrls,
        float $profitMarginPercent = 20.0,
        ?int $categoryId = null,
        int $defaultStock = 50,
        string $status = 'published',
        ?callable $onProgress = null
    ): array {
        @set_time_limit(600);

        $importedProducts = [];
        $errors = [];
        $total = count($productIdsOrUrls);

        foreach ($productIdsOrUrls as $index => $item) {
            if ($index > 0) {
                usleep(1200000); // 1.2s delay to prevent rate limits
            }

            try {
                $product = $this->importAliExpressProduct(
                    $item,
                    $profitMarginPercent,
                    $categoryId,
                    $defaultStock,
                    $status
                );

                $itemResult = [
                    'product_id_or_url' => $item,
                    'status' => 'completed',
                    'product_id' => $product->id,
                    'aliexpress_product_id' => $product->aliexpress_product_id,
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
                    'product_id_or_url' => $item,
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
     * Import or update an AliExpress product into the Laravel MySQL database.
     */
    public function importAliExpressProduct(
        string $productIdOrUrl,
        float $profitMarginPercent = 20.0,
        ?int $categoryId = null,
        int $defaultStock = 50,
        string $status = 'published'
    ): Product {
        $aliData = $this->aliExpressService->getProductDetail($productIdOrUrl);

        if (empty($aliData) || empty($aliData['productId'])) {
            throw new Exception("Product details could not be retrieved from AliExpress for: {$productIdOrUrl}");
        }

        return DB::transaction(function () use ($aliData, $profitMarginPercent, $categoryId, $defaultStock, $status) {
            $costPrice = (float)($aliData['costPrice'] ?? 0);
            $retailPrice = round($costPrice * (1 + ($profitMarginPercent / 100)), 2);

            $productId = (string)$aliData['productId'];
            $productName = $aliData['title'] ?? ('AliExpress Product ' . $productId);

            // Safe unique slug
            $baseSlug = Str::limit(Str::slug($productName), 120, '');
            if (empty($baseSlug)) {
                $baseSlug = 'aliexpress-product-' . $productId;
            }
            $slug = $baseSlug;

            $existingProduct = Product::where('aliexpress_product_id', $productId)->first();
            if ($existingProduct) {
                $slug = $existingProduct->slug;
            } else {
                $count = Product::where('slug', 'LIKE', "{$baseSlug}%")->count();
                if ($count > 0) {
                    $slug = "{$baseSlug}-" . ($count + 1);
                }
            }

            // Category assignment
            $finalCategoryId = $categoryId;
            if (!$finalCategoryId) {
                $defaultCategory = Category::first();
                $finalCategoryId = $defaultCategory ? $defaultCategory->id : 1;
            }

            $productData = [
                'name' => $productName,
                'slug' => $slug,
                'sku' => 'ALI-' . ($aliData['spu'] ?? $productId),
                'description' => $aliData['description'] ?? '',
                'summary' => Str::limit(strip_tags($aliData['description'] ?? ''), 250),
                'cost_price' => $costPrice,
                'price' => $retailPrice,
                'stock' => (int)($aliData['stock'] ?? $defaultStock),
                'category_id' => $finalCategoryId,
                'status' => $status,
                'is_featured' => false,
                'aliexpress_product_id' => $productId,
                'aliexpress_url' => $aliData['productUrl'] ?? "https://www.aliexpress.com/item/{$productId}.html",
                'is_aliexpress_product' => true,
                'aliexpress_synced_at' => now(),
                'aliexpress_store_name' => $aliData['storeName'] ?? null,
            ];

            if ($existingProduct) {
                $existingProduct->update($productData);
                $product = $existingProduct;
            } else {
                $product = Product::create($productData);
            }

            // Sync Product Images
            $mainImage = $aliData['mainImage'] ?? null;
            $galleryImages = $aliData['images'] ?? [];

            if ($mainImage) {
                // Delete old images if re-importing
                ProductImage::where('product_id', $product->id)->delete();

                ProductImage::create([
                    'product_id' => $product->id,
                    'image_path' => $mainImage,
                    'is_primary' => true,
                ]);

                foreach ($galleryImages as $img) {
                    if ($img !== $mainImage) {
                        ProductImage::create([
                            'product_id' => $product->id,
                            'image_path' => $img,
                            'is_primary' => false,
                        ]);
                    }
                }
            }

            // Sync Product Variants
            $rawVariants = $aliData['variants'] ?? [];
            if (!empty($rawVariants)) {
                ProductVariant::where('product_id', $product->id)->delete();

                foreach ($rawVariants as $index => $v) {
                    $vCostPrice = (float)($v['cost_price'] ?? $costPrice);
                    $vRetailPrice = round($vCostPrice * (1 + ($profitMarginPercent / 100)), 2);

                    ProductVariant::create([
                        'product_id' => $product->id,
                        'combination_label' => $v['name'] ?? ('Option ' . ($index + 1)),
                        'sku' => $v['sku_id'] ?? ('ALI-' . $productId . '-' . ($index + 1)),
                        'price' => $vRetailPrice,
                        'stock' => (int)($v['stock'] ?? $defaultStock),
                        'aliexpress_sku_id' => $v['sku_id'] ?? null,
                        'aliexpress_variant_sku' => $v['sku_id'] ?? null,
                    ]);
                }
            } else {
                // Ensure default variant exists if none from supplier
                if ($product->variants()->count() === 0) {
                    ProductVariant::create([
                        'product_id' => $product->id,
                        'combination_label' => 'Default',
                        'sku' => $product->sku,
                        'price' => $retailPrice,
                        'stock' => $product->stock,
                    ]);
                }
            }

            return $product->fresh(['images', 'variants']);
        });
    }
}
