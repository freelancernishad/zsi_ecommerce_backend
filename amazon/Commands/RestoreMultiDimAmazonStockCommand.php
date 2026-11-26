<?php

namespace Amazon\Commands;

use App\Models\InventorySyncLog;
use App\Models\Product;
use App\Models\SiteSetting;
use Illuminate\Console\Command;

class RestoreMultiDimAmazonStockCommand extends Command
{
    protected $signature = 'amazon:restore-multidim-stock
        {--dry-run : Preview affected products without modifying the database}
        {--stock= : Override stock quantity to restore (defaults to amazon_default_stock setting or 20)}';

    protected $description = 'Restore variant stock on multi-dimension Amazon products that were falsely zeroed by twister UNAVAILABLE sync';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $overrideStock = $this->option('stock');

        $defaultStock = 20;
        if ($overrideStock !== null && is_numeric($overrideStock)) {
            $defaultStock = max(1, (int) $overrideStock);
        } else {
            $settingStock = SiteSetting::where('group', 'amazon')
                ->where('key', 'amazon_default_stock')
                ->value('value');
            if ($settingStock !== null && is_numeric($settingStock) && (int) $settingStock > 0) {
                $defaultStock = (int) $settingStock;
            }
        }

        $products = Product::where('is_amazon_product', true)
            ->where('status', 'published')
            ->with('variants')
            ->get();

        $affectedCount = 0;
        $restoredVariantsTotal = 0;

        foreach ($products as $product) {
            $variantOptions = is_array($product->variant_options)
                ? $product->variant_options
                : json_decode((string) $product->variant_options, true);

            if (!is_array($variantOptions) || count($variantOptions) <= 1) {
                continue;
            }

            if ($product->variants->isEmpty()) {
                continue;
            }

            $positiveCount = $product->variants->where('stock', '>', 0)->count();
            $zeroVariants = $product->variants->where('stock', '<=', 0);

            if ($positiveCount === 0 || $zeroVariants->isEmpty()) {
                continue;
            }

            $parentAsin = strtoupper(trim((string) $product->amazon_asin));
            $latestLog = InventorySyncLog::where('product_id', $product->id)
                ->latest('id')
                ->first();
            $parentExplicitlyZero = $latestLog && (int) ($latestLog->new_stock ?? -1) === 0;

            $restoredForProduct = 0;

            foreach ($zeroVariants as $variant) {
                $vAsin = strtoupper(trim((string) ($variant->amazon_asin ?: $parentAsin)));
                if ($parentExplicitlyZero && $vAsin === $parentAsin) {
                    continue;
                }

                if (!$dryRun) {
                    $variant->stock = $defaultStock;
                    $variant->available_quantity = $defaultStock;
                    $variant->save();
                }
                $restoredForProduct++;
            }

            if ($restoredForProduct === 0) {
                continue;
            }

            $newTotalStock = $dryRun
                ? ((int) $product->variants->sum(fn($v) => max(0, (int) $v->stock)) + ($restoredForProduct * $defaultStock))
                : (int) $product->variants()->sum('stock');

            if (!$dryRun) {
                $product->stock_quantity = $newTotalStock;
                $product->save();
            }

            $affectedCount++;
            $restoredVariantsTotal += $restoredForProduct;

            $prefix = $dryRun ? '[DRY-RUN] ' : '';
            $this->line(sprintf(
                '%sProduct #%d (%s) — restored %d/%d zeroed variant(s) to %d; total stock_quantity: %d -> %d | %s',
                $prefix,
                $product->id,
                $parentAsin,
                $restoredForProduct,
                $product->variants->count(),
                $defaultStock,
                (int) $product->stock_quantity,
                $newTotalStock,
                substr((string) $product->name, 0, 55)
            ));
        }

        if ($affectedCount === 0) {
            $this->info('No affected multi-dimension Amazon products found.');
        } else {
            $modeLabel = $dryRun ? 'Would restore' : 'Restored';
            $this->info("{$modeLabel} {$restoredVariantsTotal} variant(s) across {$affectedCount} multi-dimension Amazon product(s).");
        }

        return self::SUCCESS;
    }
}
