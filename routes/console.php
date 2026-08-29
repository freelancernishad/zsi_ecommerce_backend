<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('tenants:migrate {--tenant= : Specific tenant database name (e.g. goldenmarkstore_db)} {--seed : Seed the database with records}', function () {
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


