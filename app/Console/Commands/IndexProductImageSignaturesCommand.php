<?php

namespace App\Console\Commands;

use App\Services\ProductImageSearchService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class IndexProductImageSignaturesCommand extends Command
{
    protected $signature = 'products:index-image-signatures
        {--all-tenants : Index published products across all configured tenant databases}
        {--tenant= : Specific tenant database name (e.g. mustgyyu_zsi_ecommerce_db)}
        {--fresh : Re-index all published products from scratch}';

    protected $description = 'Build perceptual dHash & color histogram visual search signatures for all published products';

    public function handle(ProductImageSearchService $service): int
    {
        $fresh = (bool) $this->option('fresh');
        $allTenants = (bool) $this->option('all-tenants');
        $targetTenant = $this->option('tenant');

        if (!$allTenants && empty($targetTenant)) {
            $this->info($fresh ? 'Re-indexing all published product images...' : 'Indexing new/updated published product images...');
            $stats = $service->indexProductSignatures($fresh, 20);
            $this->info("Done! Total: {$stats['total']}, Indexed: {$stats['indexed']}, Skipped: {$stats['skipped']}, Failed: {$stats['failed']}");

            return self::SUCCESS;
        }

        $originalDb = Config::get('database.connections.mysql.database');

        $candidates = array_values(array_unique(array_filter(array_merge(
            array_values(config('tenants.domains', [])),
            [config('tenants.fallback'), $originalDb]
        ))));

        $existingDatabases = null;
        try {
            $existingDatabases = collect(DB::select('SHOW DATABASES'))
                ->map(fn ($row) => array_values((array) $row)[0] ?? null)
                ->filter()
                ->flip()
                ->all();
        } catch (Throwable $e) {
            $this->line('<comment>Note:</comment> SHOW DATABASES unavailable (' . $e->getMessage() . '); attempting direct connection per tenant.');
        }

        if (!empty($targetTenant)) {
            $filtered = array_values(array_filter(
                $candidates,
                fn ($db) => $db === $targetTenant || Str::contains($db, $targetTenant)
            ));
            if (empty($filtered) && ($existingDatabases === null || isset($existingDatabases[$targetTenant]))) {
                $filtered = [$targetTenant];
            }
            $candidates = $filtered;
        }

        if (empty($candidates)) {
            $this->error("No matching tenant database found" . (!empty($targetTenant) ? " for: {$targetTenant}" : '.'));
            return self::FAILURE;
        }

        $processed = [];
        $skippedDbs = [];
        $failedDbs = [];

        try {
            foreach ($candidates as $dbName) {
                if ($existingDatabases !== null && !isset($existingDatabases[$dbName])) {
                    $skippedDbs[] = $dbName;
                    $this->line("<comment>Skipping</comment> {$dbName} (database does not exist on this server)");
                    continue;
                }

                $this->info("Indexing tenant DB: {$dbName}");

                try {
                    Config::set('database.connections.mysql.database', $dbName);
                    DB::purge('mysql');
                    DB::reconnect('mysql');

                    if (!Schema::hasTable('product_image_signatures')) {
                        $skippedDbs[] = "{$dbName} (not migrated)";
                        $this->warn("Skipping {$dbName} (product_image_signatures table not migrated yet)");
                        continue;
                    }

                    $stats = $service->indexProductSignatures($fresh, 20);
                    $this->info("  Done! Total: {$stats['total']}, Indexed: {$stats['indexed']}, Skipped: {$stats['skipped']}, Failed: {$stats['failed']}");
                    $processed[] = $dbName;
                } catch (Throwable $e) {
                    $failedDbs[] = $dbName;
                    $this->error("Failed indexing {$dbName}: " . $e->getMessage());
                }
            }
        } finally {
            Config::set('database.connections.mysql.database', $originalDb);
            DB::purge('mysql');
            DB::reconnect('mysql');
        }

        $this->newLine();
        $this->info('Tenant indexing summary:');
        $this->line('  Processed: ' . (empty($processed) ? 'None' : implode(', ', $processed)));
        if (!empty($skippedDbs)) {
            $this->line('  Skipped:   ' . implode(', ', $skippedDbs));
        }
        if (!empty($failedDbs)) {
            $this->error('  Failed:    ' . implode(', ', $failedDbs));
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
