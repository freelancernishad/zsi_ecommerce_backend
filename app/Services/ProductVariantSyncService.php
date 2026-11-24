<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\FileSystem\FileUploadService;
use Illuminate\Http\UploadedFile;

class ProductVariantSyncService
{
    protected FileUploadService $fileUploadService;

    public function __construct(FileUploadService $fileUploadService)
    {
        $this->fileUploadService = $fileUploadService;
    }

    /**
     * Derive normalized and naturally sorted variant_options JSON from actual variant rows,
     * preserving existing option order and guide metadata.
     */
    public function buildVariantOptions(array $variants, array $existingOptions = []): ?array
    {
        if (empty($variants)) {
            return null;
        }

        $optionNames = [];
        $metaByLowerName = [];

        foreach ($existingOptions as $opt) {
            if (!is_array($opt) || empty($opt['name'])) {
                continue;
            }
            $name = trim((string) $opt['name']);
            if ($name === '') {
                continue;
            }
            $lower = strtolower($name);
            if (!isset($metaByLowerName[$lower])) {
                $optionNames[] = $name;
                $metaByLowerName[$lower] = [
                    'name' => $name,
                    'guide_content' => $opt['guide_content'] ?? null,
                    'guide_text' => $opt['guide_text'] ?? null,
                    'guide_link' => $opt['guide_link'] ?? null,
                ];
            }
        }

        $optionsCollector = [];
        $canonicalNames = [];

        foreach ($optionNames as $name) {
            $lower = strtolower($name);
            $canonicalNames[$lower] = $name;
            $optionsCollector[$lower] = [];
        }

        foreach ($variants as $variant) {
            $attributes = is_array($variant['attributes'] ?? null) ? $variant['attributes'] : [];
            if (empty($attributes)) {
                $label = (string) ($variant['combination_label'] ?? $variant['label'] ?? '');
                $attributes = $this->deriveAttributesFromLabel($label, $optionNames);
            }

            foreach ($attributes as $rawOptName => $rawVal) {
                $optName = trim((string) $rawOptName);
                $optVal = trim((string) $rawVal);
                if ($optName === '' || $this->isPlaceholderValue($optVal)) {
                    continue;
                }

                $lower = strtolower($optName);
                if (!isset($canonicalNames[$lower])) {
                    $canonicalNames[$lower] = $optName;
                    $optionsCollector[$lower] = [];
                }
                $optionsCollector[$lower][$optVal] = true;
            }
        }

        $formatted = [];
        foreach ($optionsCollector as $lower => $valsMap) {
            $vals = array_values(array_map('strval', array_keys($valsMap)));
            if (empty($vals)) {
                continue;
            }

            $vals = $this->sortOptionValuesNaturally($vals);
            $meta = $metaByLowerName[$lower] ?? null;

            $entry = [
                'name' => $canonicalNames[$lower],
                'values' => $vals,
            ];

            if (!empty($meta['guide_content'])) {
                $entry['guide_content'] = $meta['guide_content'];
            }
            if (!empty($meta['guide_text'])) {
                $entry['guide_text'] = $meta['guide_text'];
            }
            if (!empty($meta['guide_link'])) {
                $entry['guide_link'] = $meta['guide_link'];
            }

            $formatted[] = $entry;
        }

        return !empty($formatted) ? $formatted : null;
    }

    /**
     * Extract legacy color and size columns from variant attributes.
     */
    public function extractColorAndSize(array $attributes, ?string $combinationLabel = null): array
    {
        $color = null;
        $size = null;

        foreach ($attributes as $optName => $rawVal) {
            $val = trim((string) $rawVal);
            if ($this->isPlaceholderValue($val)) {
                continue;
            }

            $lowerName = strtolower(trim((string) $optName));
            if (($lowerName === 'color' || $lowerName === 'colour') && $color === null) {
                $color = $val;
            } elseif ($lowerName === 'size' && $size === null) {
                $size = $val;
            }
        }

        return [
            'color' => $color,
            'size' => $size,
        ];
    }

    /**
     * Upload a variant image to S3 (when configured) or fall back to local public storage.
     */
    public function uploadVariantImage(UploadedFile $file): string
    {
        if (!empty(config('filesystems.disks.s3.bucket'))) {
            return $this->fileUploadService->uploadFileToS3($file, 'products/variants');
        }

        return $file->store('products/variants', 'public');
    }

    /**
     * Synchronize product variants, images, derived variant_options, and total stock_quantity
     * using the same persistence semantics as the import pipeline.
     */
    public function syncVariants(
        Product $product,
        array $rawVariants,
        array $uploadedImages = [],
        array $existingOptions = []
    ): array {
        if (empty($rawVariants)) {
            $product->variants()->delete();
            $product->variant_options = null;
            $product->save();

            return [
                'variants_count' => 0,
                'total_stock' => null,
                'variant_options' => null,
            ];
        }

        $optionNames = [];
        foreach ($existingOptions as $opt) {
            if (is_array($opt) && !empty($opt['name'])) {
                $optionNames[] = trim((string) $opt['name']);
            }
        }

        $incomingIds = [];
        foreach ($rawVariants as $v) {
            if (is_array($v) && !empty($v['id']) && is_numeric($v['id'])) {
                $incomingIds[] = (int) $v['id'];
            }
        }
        $incomingIds = array_values(array_unique($incomingIds));

        if (!empty($incomingIds)) {
            $product->variants()->whereNotIn('id', $incomingIds)->delete();
        } else {
            $product->variants()->delete();
        }

        $normalizedForOptions = [];
        $totalStock = 0;

        foreach ($rawVariants as $index => $variant) {
            if (!is_array($variant)) {
                continue;
            }

            $rawLabel = trim((string) ($variant['label'] ?? $variant['combination_label'] ?? ''));

            $attributes = [];
            if (!empty($variant['attributes']) && is_array($variant['attributes'])) {
                foreach ($variant['attributes'] as $k => $val) {
                    $cleanKey = trim((string) $k);
                    $cleanVal = trim((string) $val);
                    if ($cleanKey !== '' && $cleanVal !== '') {
                        $attributes[$cleanKey] = $cleanVal;
                    }
                }
            } elseif ($rawLabel !== '') {
                $attributes = $this->deriveAttributesFromLabel($rawLabel, $optionNames);
            }

            $label = $rawLabel;
            if ($label === '' && !empty($attributes)) {
                $label = implode(' / ', array_values($attributes));
            }
            if ($label === '') {
                $label = 'Default';
            }

            $rawPrice = $variant['price'] ?? null;
            $price = ($rawPrice !== null && $rawPrice !== '' && is_numeric($rawPrice))
                ? (float) $rawPrice
                : (float) $product->price;

            $rawDiscount = $variant['discount_price'] ?? ($variant['discount'] ?? null);
            $discountPrice = ($rawDiscount !== null && $rawDiscount !== '' && is_numeric($rawDiscount))
                ? (float) $rawDiscount
                : null;

            $qty = max(0, (int) ($variant['qty'] ?? $variant['available_quantity'] ?? $variant['stock'] ?? 0));

            ['color' => $color, 'size' => $size] = $this->extractColorAndSize($attributes, $label);

            $sku = isset($variant['sku']) && trim((string) $variant['sku']) !== ''
                ? trim((string) $variant['sku'])
                : null;

            $payload = [
                'combination_label' => $label,
                'price' => $price,
                'discount_price' => $discountPrice,
                'sku' => $sku,
                'available_quantity' => $qty,
                'stock' => $qty,
                'color' => $color,
                'size' => $size,
            ];

            $uploadedFile = $uploadedImages[$index] ?? ($variant['image_file'] ?? null);
            if ($uploadedFile instanceof UploadedFile && $uploadedFile->isValid()) {
                $payload['image'] = $this->uploadVariantImage($uploadedFile);
            } elseif (array_key_exists('image', $variant)) {
                $imgStr = is_string($variant['image']) ? trim($variant['image']) : '';
                $payload['image'] = $imgStr !== '' ? $imgStr : null;
            }

            if (!empty($variant['id']) && is_numeric($variant['id'])) {
                $existingVariant = $product->variants()->where('id', (int) $variant['id'])->first();
                if ($existingVariant) {
                    $existingVariant->update($payload);
                } else {
                    ProductVariant::create(array_merge(['product_id' => $product->id], $payload));
                }
            } else {
                ProductVariant::create(array_merge(['product_id' => $product->id], $payload));
            }

            $normalizedForOptions[] = [
                'attributes' => $attributes,
                'combination_label' => $label,
            ];
            $totalStock += $qty;
        }

        $derivedOptions = $this->buildVariantOptions($normalizedForOptions, $existingOptions);
        $product->variant_options = $derivedOptions;
        $product->stock_quantity = $totalStock;
        $product->save();

        return [
            'variants_count' => count($normalizedForOptions),
            'total_stock' => $totalStock,
            'variant_options' => $derivedOptions,
        ];
    }

    protected function deriveAttributesFromLabel(string $label, array $optionNames): array
    {
        $trimmed = trim($label);
        if ($this->isPlaceholderValue($trimmed)) {
            return [];
        }

        $parts = array_values(array_filter(
            array_map('trim', explode(' / ', $trimmed)),
            fn (string $p) => !$this->isPlaceholderValue($p)
        ));

        $attributes = [];
        foreach ($parts as $idx => $part) {
            $optName = $optionNames[$idx] ?? (count($parts) === 1 ? 'Option' : ('Option ' . ($idx + 1)));
            $attributes[$optName] = $part;
        }

        return $attributes;
    }

    protected function sortOptionValuesNaturally(array $vals): array
    {
        if (count($vals) <= 1) {
            return $vals;
        }

        $allNumeric = collect($vals)->every(fn ($v) => is_numeric(trim((string) $v)));
        if ($allNumeric) {
            usort($vals, fn ($a, $b) => (float) $a <=> (float) $b);
            return $vals;
        }

        $hasNumberedSizes = collect($vals)->contains(
            fn ($v) => (bool) preg_match('/^size\s+\d+/i', trim((string) $v))
        );
        if ($hasNumberedSizes) {
            usort($vals, fn ($a, $b) => strnatcasecmp((string) $a, (string) $b));
        }

        return $vals;
    }

    protected function isPlaceholderValue(string $value): bool
    {
        $normalized = strtolower(trim($value));
        return in_array($normalized, ['', 'default', 'default title', 'default variant'], true);
    }
}
