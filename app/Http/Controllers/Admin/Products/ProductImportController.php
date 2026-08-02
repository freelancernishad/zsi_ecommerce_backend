<?php

namespace App\Http\Controllers\Admin\Products;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProductImportController extends Controller
{
    public function importShopify(Request $request)
    {
        $request->validate([
            'file'          => 'required|file|mimes:csv,txt|max:51200', // max 50MB
            'category_id'   => 'nullable',
            'default_stock' => 'nullable|numeric|min:0',
            'price_markup'  => 'nullable|numeric|min:0',
            'status'        => 'nullable|string|in:published,draft',
        ]);

        $file         = $request->file('file');
        $categoryId   = $request->input('category_id');
        if ($categoryId === 'Uncategorized' || !$categoryId) {
            $categoryId = \App\Models\Category::first()?->id;
        }
        $defaultStock = (int) $request->input('default_stock', 10);
        $priceMarkup  = (float) $request->input('price_markup', 0);
        $forcedStatus = $request->input('status', 'published');

        // Read and parse CSV
        $path = $file->getRealPath();
        $handle = fopen($path, 'r');
        if (!$handle) {
            return response()->json([
                'isError' => true,
                'message' => 'Unable to open CSV file.',
            ], 400);
        }

        // BOM removal if present
        $headerLine = fgets($handle);
        $headerLine = preg_replace('/^\xEF\xBB\xBF/', '', $headerLine);
        rewind($handle);

        $headers = fgetcsv($handle);
        if (!$headers) {
            fclose($handle);
            return response()->json([
                'isError' => true,
                'message' => 'Invalid or empty CSV file.',
            ], 400);
        }

        // Clean headers
        $cleanHeaders = array_map(function ($h) {
            return trim($h);
        }, $headers);

        // Header indexes mapping
        $headerMap = array_flip($cleanHeaders);

        // Find all OptionX Name and OptionX Value column indexes
        $optionPairs = []; // [ ['name_idx' => i, 'val_idx' => j, 'num' => 1], ... ]
        for ($i = 1; $i <= 10; $i++) {
            $nameHeader = "Option{$i} Name";
            $valHeader  = "Option{$i} Value";
            if (isset($headerMap[$nameHeader]) && isset($headerMap[$valHeader])) {
                $optionPairs[] = [
                    'num'      => $i,
                    'name_idx' => $headerMap[$nameHeader],
                    'val_idx'  => $headerMap[$valHeader],
                ];
            }
        }

        // Group rows by Handle
        $groupedProducts = [];
        $handleIdx       = $headerMap['Handle'] ?? null;
        $titleIdx        = $headerMap['Title'] ?? null;

        if ($handleIdx === null && $titleIdx === null) {
            fclose($handle);
            return response()->json([
                'isError' => true,
                'message' => 'CSV file must contain a Handle or Title column.',
            ], 422);
        }

        $rowNum = 1;
        while (($row = fgetcsv($handle)) !== false) {
            $rowNum++;
            if (empty($row) || (count($row) == 1 && $row[0] === null)) {
                continue;
            }

            $productHandle = $handleIdx !== null && isset($row[$handleIdx]) ? trim($row[$handleIdx]) : '';
            $productTitle  = $titleIdx !== null && isset($row[$titleIdx]) ? trim($row[$titleIdx]) : '';

            if (!$productHandle && !$productTitle) {
                continue;
            }

            $key = $productHandle ?: Str::slug($productTitle);

            if (!isset($groupedProducts[$key])) {
                $groupedProducts[$key] = [
                    'handle'   => $key,
                    'first_row' => $row,
                    'rows'      => [],
                ];
            }
            $groupedProducts[$key]['rows'][] = $row;
        }
        fclose($handle);

        if (empty($groupedProducts)) {
            return response()->json([
                'isError' => true,
                'message' => 'No valid products found in CSV.',
            ], 422);
        }

        $importedProductsCount = 0;
        $importedVariantsCount = 0;
        $importedImagesCount   = 0;

        DB::beginTransaction();
        try {
            foreach ($groupedProducts as $key => $pData) {
                $firstRow = $pData['first_row'];
                $rows     = $pData['rows'];

                // Product basic fields
                $title       = $this->getValue($firstRow, $headerMap, 'Title') ?: ucfirst(str_replace('-', ' ', $key));
                $bodyHtml    = $this->getValue($firstRow, $headerMap, 'Body (HTML)') ?: '';
                $vendor      = $this->getValue($firstRow, $headerMap, 'Vendor');
                $tagsRaw     = $this->getValue($firstRow, $headerMap, 'Tags');
                $seoTitle    = $this->getValue($firstRow, $headerMap, 'SEO Title');
                $seoDesc     = $this->getValue($firstRow, $headerMap, 'SEO Description');
                $published   = strtolower($this->getValue($firstRow, $headerMap, 'Published')) !== 'false';

                $tags = [];
                if ($tagsRaw) {
                    $tags = array_map('trim', explode(',', $tagsRaw));
                    $tags = array_filter($tags);
                }

                // Detect option names from the first row or across rows
                $optionNames = []; // [ 1 => "Color", 2 => "Size" ]
                foreach ($optionPairs as $pair) {
                    $optName = $this->getValue($firstRow, $headerMap, "Option{$pair['num']} Name");
                    if ($optName && strtolower($optName) !== 'title') {
                        $optionNames[$pair['num']] = $optName;
                    }
                }

                // Extract Images & Variant Data across rows
                $imagesList = [];
                $variantRows = [];
                $optionValuesCollector = []; // [ "Color" => ["Red", "Blue"] ]

                foreach ($rows as $r) {
                    // Extract Image Src
                    $imgSrc = $this->getValue($r, $headerMap, 'Image Src');
                    if ($imgSrc && !in_array($imgSrc, $imagesList)) {
                        $imagesList[] = $imgSrc;
                    }

                    // Extract Variant Image
                    $varImgSrc = $this->getValue($r, $headerMap, 'Variant Image');
                    if ($varImgSrc && !in_array($varImgSrc, $imagesList)) {
                        $imagesList[] = $varImgSrc;
                    }

                    // Variant option values for this row
                    $rowOptValues = [];
                    $rowOptParts  = [];
                    $colorVal     = null;
                    $sizeVal      = null;

                    foreach ($optionPairs as $pair) {
                        $num = $pair['num'];
                        $val = $this->getValue($r, $headerMap, "Option{$num} Value");
                        $optName = $optionNames[$num] ?? $this->getValue($r, $headerMap, "Option{$num} Name");

                        if ($optName && strtolower($optName) !== 'title' && $val && strtolower($val) !== 'default title') {
                            $rowOptValues[$optName] = $val;
                            $rowOptParts[] = "{$val}";
                            $optionValuesCollector[$optName][$val] = true;

                            if (strtolower($optName) === 'color') {
                                $colorVal = $val;
                            }
                            if (strtolower($optName) === 'size') {
                                $sizeVal = $val;
                            }
                        }
                    }

                    $rawPrice   = (float) ($this->getValue($r, $headerMap, 'Variant Price') ?: 0);
                    $rawCompare = (float) ($this->getValue($r, $headerMap, 'Variant Compare At Price') ?: 0);
                    $rawQty     = $this->getValue($r, $headerMap, 'Variant Inventory Qty');
                    $sku        = $this->getValue($r, $headerMap, 'Variant SKU');

                    $price = $rawPrice;
                    if ($priceMarkup > 0 && $price > 0) {
                        $price = round($price * (1 + ($priceMarkup / 100)), 2);
                    }

                    $comparePrice = $rawCompare;
                    if ($priceMarkup > 0 && $comparePrice > 0) {
                        $comparePrice = round($comparePrice * (1 + ($priceMarkup / 100)), 2);
                    }

                    $qty = (is_numeric($rawQty) && (int)$rawQty > 0) ? (int)$rawQty : $defaultStock;

                    // Only consider it a variant row if it has an option value, SKU, or Price
                    if (!empty($rowOptValues) || $sku || $rawPrice > 0) {
                        $variantRows[] = [
                            'combination_label' => implode(' / ', $rowOptParts),
                            'price'             => $price,
                            'discount_price'    => $comparePrice > 0 ? $comparePrice : null,
                            'sku'               => $sku ?: ($key . '-' . (count($variantRows) + 1)),
                            'available_quantity'=> $qty,
                            'stock'             => $qty,
                            'color'             => $colorVal,
                            'size'              => $sizeVal,
                        ];
                    }
                }

                // Base Product Price & Sale Price
                $basePrice     = count($variantRows) > 0 ? $variantRows[0]['price'] : 0;
                $baseSalePrice = count($variantRows) > 0 ? $variantRows[0]['discount_price'] : null;

                // Build variant_options JSON
                $variantOptionsFormatted = [];
                foreach ($optionValuesCollector as $optName => $valsMap) {
                    $variantOptionsFormatted[] = [
                        'name'   => $optName,
                        'values' => array_keys($valsMap),
                    ];
                }

                // Total stock calculation
                $totalStock = 0;
                if (!empty($variantRows)) {
                    foreach ($variantRows as $vr) {
                        $totalStock += $vr['available_quantity'];
                    }
                } else {
                    $totalStock = $defaultStock;
                }

                $mainSku = $variantRows[0]['sku'] ?? ($key . '-MAIN');

                // Check if product already exists by url_handle or sku
                $existingProduct = Product::where('url_handle', $key)
                    ->orWhere('sku', $mainSku)
                    ->first();

                if ($existingProduct) {
                    $product = $existingProduct;
                    $product->update([
                        'name'              => $title,
                        'description'       => $bodyHtml,
                        'short_description' => Str::limit(strip_tags($bodyHtml), 150),
                        'price'             => $basePrice,
                        'sale_price'        => $baseSalePrice,
                        'original_price'    => $baseSalePrice,
                        'stock_quantity'    => $totalStock,
                        'category_id'       => $categoryId ?: $product->category_id,
                        'status'            => $forcedStatus ?: ($published ? 'published' : 'draft'),
                        'tags'              => $tags,
                        'variant_options'   => !empty($variantOptionsFormatted) ? $variantOptionsFormatted : null,
                    ]);

                    // Remove old images & variants before re-importing
                    $product->images()->delete();
                    $product->variants()->delete();
                } else {
                    // Generate slug
                    $slug = Str::slug($title);
                    if (Product::where('slug', $slug)->exists()) {
                        $slug .= '-' . time() . '-' . rand(10, 99);
                    }

                    // Ensure main SKU is unique if colliding with another product
                    if ($mainSku && Product::where('sku', $mainSku)->exists()) {
                        $mainSku = $mainSku . '-' . rand(100, 999);
                    }

                    // Create Product
                    $product = Product::create([
                        'name'              => $title,
                        'slug'              => $slug,
                        'description'       => $bodyHtml,
                        'short_description' => Str::limit(strip_tags($bodyHtml), 150),
                        'price'             => $basePrice,
                        'sale_price'        => $baseSalePrice,
                        'original_price'    => $baseSalePrice,
                        'stock_quantity'    => $totalStock,
                        'sku'               => $mainSku,
                        'category_id'       => $categoryId,
                        'status'            => $forcedStatus ?: ($published ? 'published' : 'draft'),
                        'url_handle'        => $key,
                        'tags'              => $tags,
                        'variant_options'   => !empty($variantOptionsFormatted) ? $variantOptionsFormatted : null,
                        'is_physical'       => true,
                        'charge_tax'        => true,
                    ]);
                }
                $importedProductsCount++;

                // Create Product Images
                foreach ($imagesList as $index => $imgUrl) {
                    ProductImage::create([
                        'product_id' => $product->id,
                        'image_path' => $imgUrl,
                        'is_primary' => $index === 0,
                    ]);
                    $importedImagesCount++;
                }

                // Create Product Variants
                if (!empty($variantRows)) {
                    foreach ($variantRows as $vData) {
                        ProductVariant::create([
                            'product_id'        => $product->id,
                            'combination_label' => $vData['combination_label'] ?: 'Default',
                            'price'             => $vData['price'],
                            'discount_price'    => $vData['discount_price'],
                            'sku'               => $vData['sku'],
                            'available_quantity'=> $vData['available_quantity'],
                            'stock'             => $vData['stock'],
                            'color'             => $vData['color'],
                            'size'              => $vData['size'],
                        ]);
                        $importedVariantsCount++;
                    }
                }
            }

            DB::commit();

            return response()->json([
                'isError' => false,
                'status'  => 'success',
                'message' => "Successfully imported {$importedProductsCount} products with {$importedVariantsCount} variants.",
                'data'    => [
                    'imported_products' => $importedProductsCount,
                    'imported_variants' => $importedVariantsCount,
                    'imported_images'   => $importedImagesCount,
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Shopify Product Import Error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);

            return response()->json([
                'isError' => true,
                'message' => 'Failed to import Shopify CSV: ' . $e->getMessage(),
            ], 500);
        }
    }

    private function getValue(array $row, array $headerMap, string $columnName): ?string
    {
        if (isset($headerMap[$columnName])) {
            $idx = $headerMap[$columnName];
            if (isset($row[$idx])) {
                $val = trim($row[$idx]);
                return $val !== '' ? $val : null;
            }
        }
        return null;
    }
}
