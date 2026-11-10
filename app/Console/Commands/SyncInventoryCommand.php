<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\InventorySyncService;
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
                            {--force : Force sync even if auto-sync is disabled}';

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
        $channel = (string)$this->option('channel');
        $limit = $this->option('limit') ? (int)$this->option('limit') : null;
        $isForced = (bool)$this->option('force');

        $config = $syncService->getConfig();

        if (!$isForced && !$config['auto_sync_enabled']) {
            $this->warn('Auto-sync is currently disabled in site settings. Use --force to run anyway.');
            return Command::SUCCESS;
        }

        // Check if configured frequency has elapsed
        if (!$isForced && !empty($config['last_sync_at'])) {
            $freqHours = max(1, (int)$config['sync_frequency_hours']);
            $lastSync = \Carbon\Carbon::parse($config['last_sync_at']);
            $hoursPassed = now()->diffInHours($lastSync);
            if ($hoursPassed < $freqHours) {
                $this->info("Skipping sync: Frequency is set to every {$freqHours}h, but only {$hoursPassed}h passed since last sync ({$config['last_sync_at']}). Use --force to override.");
                return Command::SUCCESS;
            }
        }

        $this->info("------------------------------------------------------------");
        $this->info(" Starting Supplier Inventory & Variant Sync [Channel: {$channel}]");
        $this->info("------------------------------------------------------------");

        $bar = null;

        $summary = $syncService->syncAll($channel, $limit, function ($currentSummary) {
            $this->output->write(".");
        });

        $this->newLine();
        $this->info("============================================================");
        $this->info(" Inventory Sync Completed Successfully!");
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
        $this->info("============================================================");

        return Command::SUCCESS;
    }
}
