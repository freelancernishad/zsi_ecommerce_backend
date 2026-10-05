<?php

namespace AliExpress\Commands;

use Illuminate\Console\Command;
use App\Models\Product;
use AliExpress\Services\AliExpressImportService;
use Illuminate\Support\Facades\Log;
use Exception;

class SyncAliExpressInventoryCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'aliexpress:sync-inventory {--limit=50 : Maximum number of products to sync per run}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync inventory stock & pricing for imported AliExpress products';

    /**
     * Execute the console command.
     */
    public function handle(AliExpressImportService $importService)
    {
        $limit = (int)$this->option('limit');
        $products = Product::where('is_aliexpress_product', true)
            ->whereNotNull('aliexpress_product_id')
            ->orderBy('aliexpress_synced_at', 'asc')
            ->take($limit)
            ->get();

        $this->info("Found " . $products->count() . " AliExpress products to sync.");

        $synced = 0;
        $failed = 0;

        foreach ($products as $product) {
            try {
                $importService->importAliExpressProduct(
                    $product->aliexpress_product_id,
                    20.0,
                    $product->category_id,
                    $product->stock,
                    $product->status
                );
                $synced++;
                $this->info("Synced Product: {$product->name} (#{$product->id})");
            } catch (Exception $e) {
                $failed++;
                $this->error("Failed syncing Product #{$product->id}: " . $e->getMessage());
            }

            usleep(1000000);
        }

        $this->info("Completed AliExpress inventory sync. Synced: {$synced}, Failed: {$failed}");
        return Command::SUCCESS;
    }
}
