<?php

namespace AliExpress\Commands;

use Illuminate\Console\Command;
use App\Models\Product;
use AliExpress\Services\AliExpressImportService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Exception;

class SyncAliExpressInventoryCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'aliexpress:sync-inventory
        {--limit=0 : Maximum number of products to sync per database (0 = unlimited)}
        {--all-tenants : Run across all configured tenant databases}
        {--database= : Run on a specific tenant database name}';

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
        @set_time_limit(0);

        $limit = max(0, (int)$this->option('limit'));
        $allTenants = (bool)$this->option('all-tenants');
        $specificDb = trim((string)($this->option('database') ?? ''));

        $originalDb = Config::get('database.connections.mysql.database');
        $existingDatabases = collect(DB::select('SHOW DATABASES'))
            ->map(fn($row) => array_values((array)$row)[0] ?? null)
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

        try {
            foreach ($targetDbs as $dbName) {
                if (!isset($existingDatabases[$dbName])) {
                    $this->warn("[{$dbName}] Skipping (database does not exist on this server).");
                    continue;
                }

                Config::set('database.connections.mysql.database', $dbName);
                DB::purge('mysql');
                DB::reconnect('mysql');

                if (!Schema::hasTable('products')) {
                    $this->warn("[{$dbName}] Skipping (products table not found).");
                    continue;
                }

                $query = Product::where('is_aliexpress_product', true)
                    ->whereNotNull('aliexpress_product_id')
                    ->orderBy('aliexpress_synced_at', 'asc');

                if ($limit > 0) {
                    $query->take($limit);
                }

                $products = $query->get();

                $this->info("[{$dbName}] Found " . $products->count() . " AliExpress products to sync.");

                $synced = 0;
                $failed = 0;

                foreach ($products as $product) {
                    try {
                        $importService->importAliExpressProduct(
                            $product->aliexpress_product_id,
                            null,
                            $product->category_id,
                            max(1, (int)($product->stock_quantity ?? 50)),
                            $product->status ?: 'published'
                        );
                        $synced++;
                        $this->info("[{$dbName}] Synced Product: {$product->name} (#{$product->id})");
                    } catch (Exception $e) {
                        $failed++;
                        $this->error("[{$dbName}] Failed syncing Product #{$product->id}: " . $e->getMessage());
                    }

                    usleep(1000000);
                }

                $this->info("[{$dbName}] Completed AliExpress inventory sync. Synced: {$synced}, Failed: {$failed}");
            }
        } finally {
            Config::set('database.connections.mysql.database', $originalDb);
            DB::purge('mysql');
            DB::reconnect('mysql');
        }

        return Command::SUCCESS;
    }
}
