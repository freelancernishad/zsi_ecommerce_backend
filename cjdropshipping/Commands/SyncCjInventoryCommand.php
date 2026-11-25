<?php

namespace CjDropshipping\Commands;

use App\Models\Product;
use CjDropshipping\Services\CjImportService;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SyncCjInventoryCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cj:sync-inventory
        {--limit=0 : Maximum products to sync per database (0 = unlimited / all)}
        {--broken-only : Only sync CJ products missing variant_options, variant labels, images, price, or stock}
        {--all-tenants : Run across all configured tenant databases (same discovery as migrate:all)}
        {--database= : Run on a specific database name}';

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
        @set_time_limit(0);

        $limit = max(0, (int) $this->option('limit'));
        $brokenOnly = (bool) $this->option('broken-only');
        $allTenants = (bool) $this->option('all-tenants');
        $specificDb = trim((string) ($this->option('database') ?? ''));

        $originalDb = Config::get('database.connections.mysql.database');
        $existingDatabases = collect(DB::select('SHOW DATABASES'))
            ->map(fn($row) => array_values((array) $row)[0] ?? null)
            ->filter()
            ->flip()
            ->all();

        if ($specificDb !== '') {
            $targetDbs = [$specificDb];
        } elseif ($allTenants) {
            $targetDbs = array_values(array_unique(array_filter(array_merge(
                array_values(config('tenants.domains', [])),
                [config('tenants.fallback'), $originalDb]
            ))));
        } else {
            $targetDbs = [$originalDb];
        }

        $grandSuccess = 0;
        $grandFail = 0;

        try {
            foreach ($targetDbs as $dbName) {
                if (!isset($existingDatabases[$dbName])) {
                    $this->warn("Skipping {$dbName} (database does not exist on this server).");
                    continue;
                }

                Config::set('database.connections.mysql.database', $dbName);
                DB::purge('mysql');
                DB::reconnect('mysql');

                if (!Schema::hasTable('products')) {
                    $this->warn("Skipping {$dbName} (products table not found).");
                    continue;
                }

                $query = Product::where('is_cj_product', true)
                    ->whereNotNull('cj_product_id');

                if ($brokenOnly) {
                    $query->where(function ($q) {
                        $q->where(function ($sub) {
                            $sub->whereNull('variant_options')->whereHas('variants');
                        })
                        ->orWhere('stock_quantity', '<=', 0)
                        ->orWhere('price', '<=', 0)
                        ->orWhereDoesntHave('images')
                        ->orWhereHas('variants', function ($vq) {
                            $vq->whereNull('combination_label')->orWhere('combination_label', '');
                        });
                    });
                }

                $query->orderBy('cj_synced_at', 'asc');
                if ($limit > 0) {
                    $query->take($limit);
                }

                $products = $query->get();

                if ($products->isEmpty()) {
                    $this->info("[{$dbName}] No matching CJ products found to sync.");
                    continue;
                }

                $this->info("[{$dbName}] Starting CJ inventory sync for {$products->count()} products...");

                $successCount = 0;
                $failCount = 0;

                foreach ($products as $index => $product) {
                    if ($index > 0) {
                        usleep(1200000); // Respect CJ 1 QPS rate limit
                    }

                    try {
                        $this->output->write("[{$dbName}] Syncing PID {$product->cj_product_id} ('{$product->name}')... ");

                        $cjImportService->importCjProduct($product->cj_product_id, null, null);

                        $this->info("✅ DONE");
                        $successCount++;
                    } catch (Exception $e) {
                        $this->error("❌ FAILED: " . $e->getMessage());
                        $failCount++;
                    }
                }

                $this->info("[{$dbName}] Completed! Success: {$successCount}, Failed: {$failCount}.");
                $grandSuccess += $successCount;
                $grandFail += $failCount;
            }
        } finally {
            Config::set('database.connections.mysql.database', $originalDb);
            DB::purge('mysql');
            DB::reconnect('mysql');
        }

        if (count($targetDbs) > 1) {
            $this->newLine();
            $this->info("All-tenant CJ sync finished! Total Success: {$grandSuccess}, Total Failed: {$grandFail}.");
        }

        return Command::SUCCESS;
    }
}
