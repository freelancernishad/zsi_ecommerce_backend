<?php

namespace App\Console\Commands;

use App\Services\AmazonSmartSyncService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AmazonSmartSyncCommand extends Command
{
    protected $signature = 'amazon:smart-sync
                            {--limit= : Maximum Amazon products to sync in this run}
                            {--tier=all : Tier to sync (all, hot, active)}
                            {--force : Run even if disabled or frequency interval has not elapsed}
                            {--all-tenants : Run across all configured tenant databases}
                            {--database= : Run on a specific tenant database name}';

    protected $description = 'Cost-optimized Amazon inventory & price sync (Tiered, Lightweight, PA-API / Keepa / BrightData)';

    public function handle(AmazonSmartSyncService $smartSyncService): int
    {
        @set_time_limit(0);

        $isForced = (bool) $this->option('force');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;
        $tier = (string) $this->option('tier');
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

                $config = $smartSyncService->getConfig();

                if (!$isForced && !$config['enabled']) {
                    $this->warn("[{$dbName}] Amazon Smart Sync is disabled in Amazon Sync Settings. Use --force to run anyway.");
                    continue;
                }

                if (!$isForced && !empty($config['last_sync_at'])) {
                    $freqHours = max(1, (int) $config['sync_frequency_hours']);
                    $hoursPassed = Carbon::parse($config['last_sync_at'])->diffInHours(now());
                    if ($hoursPassed < $freqHours) {
                        $this->info("[{$dbName}] Skipping Amazon Smart Sync: {$hoursPassed}h passed, configured frequency is {$freqHours}h.");
                        continue;
                    }
                }

                $this->info("[{$dbName}] Starting Amazon Smart Sync [Provider: {$config['sync_provider']}, Tier: {$tier}]...");
                $summary = $smartSyncService->runSmartSync($limit, $tier, $isForced);

                if (!empty($summary['message'])) {
                    $this->warn("[{$dbName}] {$summary['message']}");
                }

                $this->table(
                    ['Metric', 'Value'],
                    [
                        ['Database', $dbName],
                        ['Provider', $summary['provider']],
                        ['Total Checked', $summary['total_checked']],
                        ['Successful', $summary['successful']],
                        ['Failed', $summary['failed']],
                        ['Skipped (Daily Cap)', $summary['skipped_cap']],
                        ['API Requests Made', $summary['api_requests_made']],
                        ['Stock Changes', $summary['stock_changes']],
                        ['Price Changes', $summary['price_changes']],
                        ['Out of Stock', $summary['out_of_stock_detected']],
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
