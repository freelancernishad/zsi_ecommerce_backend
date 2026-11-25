<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('tenants:migrate {--tenant= : Specific tenant database name (e.g. goldenmarkstore_db)} {--path= : Specific migration path} {--seed : Seed the database with records}', function () {
    $tenantDomains = config('tenants.domains', []);
    $dbNames = array_unique(array_values($tenantDomains));

    $targetTenant = $this->option('tenant');
    if ($targetTenant) {
        $dbNames = array_filter($dbNames, fn($db) => $db === $targetTenant || \Illuminate\Support\Str::contains($db, $targetTenant));
        if (empty($dbNames)) {
            $this->error("✗ No matching tenant database found for: {$targetTenant}");
            return;
        }
    }

    foreach ($dbNames as $dbName) {
        $this->info("--------------------------------------------------");
        $this->info("Running migrations for tenant DB: {$dbName}");
        $this->info("--------------------------------------------------");

        try {
            config(['database.connections.mysql.database' => $dbName]);
            \Illuminate\Support\Facades\DB::purge('mysql');
            \Illuminate\Support\Facades\DB::reconnect('mysql');

            $options = ['--database' => 'mysql', '--force' => true];
            if ($path = $this->option('path')) {
                $options['--path'] = $path;
            }
            if ($this->option('seed')) {
                $options['--seed'] = true;
            }

            $this->call('migrate', $options);
            $this->info("✓ Successfully migrated tenant DB: {$dbName}\n");
        } catch (\Exception $e) {
            $this->error("✗ Failed to migrate {$dbName}: " . $e->getMessage() . "\n");
        }
    }
})->purpose('Run database migrations across all or specific configured tenant databases');

// Schedule automatic processing of pending CJ & Amazon bulk imports
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

Schedule::call(function () {
    $shouldProcess = function (string $file, ?array $data): bool {
        if (!$data || empty($data['batch_id'])) {
            return false;
        }
        $status = $data['status'] ?? '';
        if ($status === 'pending') {
            return true;
        }
        // Recover stale in_progress batches stuck for > 3 minutes with no recent update
        if ($status === 'in_progress' && empty($data['completed_at'])) {
            $lastMod = Storage::disk('local')->lastModified($file);
            if ($lastMod && (time() - $lastMod) > 180) {
                return true;
            }
        }
        return false;
    };

    // Process pending/stale CJ imports
    $cjFiles = Storage::disk('local')->files('cj_imports');
    foreach ($cjFiles as $file) {
        if (str_ends_with($file, '.json')) {
            $data = json_decode(Storage::disk('local')->get($file), true);
            if ($shouldProcess($file, $data)) {
                Artisan::call('cj:process-bulk-import', ['batchId' => $data['batch_id']]);
                break;
            }
        }
    }

    // Process pending/stale Amazon imports
    $amzFiles = Storage::disk('local')->files('amazon_imports');
    foreach ($amzFiles as $file) {
        if (str_ends_with($file, '.json')) {
            $data = json_decode(Storage::disk('local')->get($file), true);
            if ($shouldProcess($file, $data)) {
                Artisan::call('amazon:process-bulk-import', ['batchId' => $data['batch_id']]);
                break;
            }
        }
    }

    // Process pending/stale AliExpress imports
    $aliFiles = Storage::disk('local')->files('aliexpress_imports');
    foreach ($aliFiles as $file) {
        if (str_ends_with($file, '.json')) {
            $data = json_decode(Storage::disk('local')->get($file), true);
            if ($shouldProcess($file, $data)) {
                Artisan::call('aliexpress:process-bulk-import', ['batchId' => $data['batch_id']]);
                break;
            }
        }
    }
})->everyMinute()->name('process-pending-bulk-imports')->withoutOverlapping();

// Automatic Supplier Inventory, Stock & Variant Sync (Checks hourly, runs according to configured frequency)
Schedule::command('inventory:sync')
    ->hourly()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/inventory_sync.log'))
    ->name('auto-sync-supplier-inventory');

// Dedicated Cost-Optimized Amazon Smart Sync (Checks hourly, respects its own frequency, tiers & daily cap)
Schedule::command('amazon:smart-sync')
    ->hourly()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/amazon_smart_sync.log'))
    ->name('auto-amazon-smart-sync');

// Automatically index visual image signatures for newly imported/published products across all tenant DBs
Schedule::command('products:index-image-signatures --all-tenants')
    ->everyThirtyMinutes()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/image_signature_index.log'))
    ->name('auto-index-product-image-signatures');



