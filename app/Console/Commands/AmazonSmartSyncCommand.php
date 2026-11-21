<?php

namespace App\Console\Commands;

use App\Services\AmazonSmartSyncService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class AmazonSmartSyncCommand extends Command
{
    protected $signature = 'amazon:smart-sync
                            {--limit= : Maximum Amazon products to sync in this run}
                            {--tier=all : Tier to sync (all, hot, active)}
                            {--force : Run even if disabled or frequency interval has not elapsed}';

    protected $description = 'Cost-optimized Amazon inventory & price sync (Tiered, Lightweight, PA-API / Keepa / BrightData)';

    public function handle(AmazonSmartSyncService $smartSyncService): int
    {
        $config = $smartSyncService->getConfig();
        $isForced = (bool) $this->option('force');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;
        $tier = (string) $this->option('tier');

        if (!$isForced && !$config['enabled']) {
            $this->warn('Amazon Smart Sync is disabled in Amazon Sync Settings. Use --force to run anyway.');
            return Command::SUCCESS;
        }

        if (!$isForced && !empty($config['last_sync_at'])) {
            $freqHours = max(1, (int) $config['sync_frequency_hours']);
            $hoursPassed = Carbon::parse($config['last_sync_at'])->diffInHours(now());
            if ($hoursPassed < $freqHours) {
                $this->info("Skipping Amazon Smart Sync: {$hoursPassed}h passed, configured frequency is {$freqHours}h.");
                return Command::SUCCESS;
            }
        }

        $this->info("Starting Amazon Smart Sync [Provider: {$config['sync_provider']}, Tier: {$tier}]...");
        $summary = $smartSyncService->runSmartSync($limit, $tier, $isForced);

        if (!empty($summary['message'])) {
            $this->warn($summary['message']);
        }

        $this->table(
            ['Metric', 'Value'],
            [
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

        return Command::SUCCESS;
    }
}
