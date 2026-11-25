<?php

namespace CjDropshipping\Commands;

use Illuminate\Console\Command;
use App\Models\Product;
use CjDropshipping\Services\CjImportService;
use Exception;

class SyncCjInventoryCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cj:sync-inventory {--limit=50 : Maximum products to sync per run} {--broken-only : Only sync CJ products missing variant_options, variant labels, or stock}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync product inventory and prices from CJ Dropshipping API for imported products';

    /**
     * Execute the console command.
     */
    public function handle(CjImportService $cjImportService)
    {
        $limit = (int) $this->option('limit');
        $brokenOnly = (bool) $this->option('broken-only');

        $query = Product::where('is_cj_product', true)
            ->whereNotNull('cj_product_id');

        if ($brokenOnly) {
            $query->where(function ($q) {
                $q->where(function ($sub) {
                    $sub->whereNull('variant_options')->whereHas('variants');
                })
                ->orWhere('stock_quantity', '<=', 0)
                ->orWhereHas('variants', function ($vq) {
                    $vq->whereNull('combination_label')->orWhere('combination_label', '');
                });
            });
        }

        $products = $query->orderBy('cj_synced_at', 'asc')
            ->take($limit)
            ->get();

        if ($products->isEmpty()) {
            $this->info('No CJ products found to sync.');
            return Command::SUCCESS;
        }

        $this->info("Starting CJ inventory sync for {$products->count()} products...");

        $successCount = 0;
        $failCount = 0;

        foreach ($products as $index => $product) {
            if ($index > 0) {
                usleep(1200000); // Respect CJ 1 QPS rate limit
            }

            try {
                $this->output->write("Syncing PID {$product->cj_product_id} ('{$product->name}')... ");
                
                $cjImportService->importCjProduct($product->cj_product_id, null, null);
                
                $this->info("✅ DONE");
                $successCount++;
            } catch (Exception $e) {
                $this->error("❌ FAILED: " . $e->getMessage());
                $failCount++;
            }
        }

        $this->info("Sync completed! Success: {$successCount}, Failed: {$failCount}.");
        return Command::SUCCESS;
    }
}
