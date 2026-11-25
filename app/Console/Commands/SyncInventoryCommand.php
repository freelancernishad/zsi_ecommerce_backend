<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\InventorySyncService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Exception;

class SyncInventoryCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'inventory:sync 
                            {--channel=all : Channels to sync (all, aliexpress, cjdropshipping, amazon)}
                            {--limit= : Maximum products to process per channel}
                            {--force : Force sync even if auto-sync is disabled}
                            {--all-tenants : Run across all configured tenant databases}
                            {--database= : Run on a specific tenant database name}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Synchronize inventory stock, variant availability and prices from suppliers';

    /**
     * Execute the console command.
     */
    public function handle(InventorySyncService $syncService)
    {
        @set_time_limit(0);

        $channel = (string)$this->option('channel');
        $limit = $this->option('limit') ? (int)$this->option('limit') : null;
        $isForced = (bool)$this->option('force');
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

                $config = $syncService->getConfig();

                if (!$isForced && !$config['auto_sync_enabled']) {
                    $this->warn("[{$dbName}] Auto-sync is currently disabled in site settings. Use --force to run anyway.");
                    continue;
                }

                // Check if configured frequency has elapsed
                if (!$isForced && !empty($config['last_sync_at'])) {
                    $freqHours = max(1, (int)$config['sync_frequency_hours']);
                    $lastSync = \Carbon\Carbon::parse($config['last_sync_at']);
                    $hoursPassed = now()->diffInHours($lastSync);
                    if ($hoursPassed < $freqHours) {
                        $this->info("[{$dbName}] Skipping sync: Frequency is set to every {$freqHours}h, but only {$hoursPassed}h passed since last sync ({$config['last_sync_at']}). Use --force to override.");
                        continue;
                    }
                }

                $this->info("------------------------------------------------------------");
                $this->info(" [{$dbName}] Starting Supplier Inventory Sync [Channel: {$channel}]");
                $this->info("------------------------------------------------------------");

                $summary = $syncService->syncAll($channel, $limit, function ($currentSummary) {
                    $this->output->write(".");
                });

                $this->newLine();
                $this->info(" [{$dbName}] Inventory Sync Completed!");
                $this->table(
                    ['Metric', 'Value'],
                    [
                        ['Total Products Checked', $summary['total_checked']],
                        ['Successfully Synced', $summary['successful']],
                        ['Failed / Timeouts', $summary['failed']],
                        ['Stock Changes Detected', $summary['stock_changes']],
                        ['Price Changes Detected', $summary['price_changes']],
                        ['Out of Stock Detected', $summary['out_of_stock_detected']],
                        ['Restocked', $summary['restocked']],
                        ['Finished At', $summary['finished_at']],
                    ]
                );
            }
        } finally {
            Config::set('database.connections.mysql.database', $originalDb);
            DB::purge('mysql');
            DB::reconnect('mysql');
        }

        return Command::SUCCESS;
    }
}
