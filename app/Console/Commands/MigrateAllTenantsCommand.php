<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Throwable;

class MigrateAllTenantsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'migrate:all
        {--path= : The path to the migrations files to be executed}
        {--step : Force the migrations to be run so they can be rolled back individually}
        {--pretend : Dump the SQL queries that would be run}';

    /**
     * The console command description.
     */
    protected $description = 'Run database migrations across all configured tenant databases';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $originalDb = Config::get('database.connections.mysql.database');

        $candidates = array_values(array_unique(array_filter(array_merge(
            array_values(config('tenants.domains', [])),
            [config('tenants.fallback'), $originalDb]
        ))));

        if (empty($candidates)) {
            $this->warn('No tenant or default databases found in configuration.');
            return self::SUCCESS;
        }

        // Fetch existing schemas so missing local/test DB names are skipped cleanly
        $existingDatabases = collect(DB::select('SHOW DATABASES'))
            ->map(fn($row) => array_values((array) $row)[0] ?? null)
            ->filter()
            ->flip()
            ->all();

        $migrated = [];
        $skipped = [];
        $failed = [];

        try {
            foreach ($candidates as $dbName) {
                if (!isset($existingDatabases[$dbName])) {
                    $skipped[] = $dbName;
                    $this->line("<comment>Skipping</comment> {$dbName} (database does not exist on this server)");
                    continue;
                }

                $this->info("Migrating database: {$dbName}");

                try {
                    Config::set('database.connections.mysql.database', $dbName);
                    DB::purge('mysql');
                    DB::reconnect('mysql');

                    $options = ['--force' => true];
                    if ($this->option('path')) {
                        $options['--path'] = $this->option('path');
                    }
                    if ($this->option('step')) {
                        $options['--step'] = true;
                    }
                    if ($this->option('pretend')) {
                        $options['--pretend'] = true;
                    }

                    $exitCode = $this->call('migrate', $options);
                    if ($exitCode === 0) {
                        $migrated[] = $dbName;
                    } else {
                        $failed[] = $dbName;
                    }
                } catch (Throwable $e) {
                    $failed[] = $dbName;
                    $this->error("Failed migrating {$dbName}: " . $e->getMessage());
                }
            }
        } finally {
            Config::set('database.connections.mysql.database', $originalDb);
            DB::purge('mysql');
            DB::reconnect('mysql');
        }

        $this->newLine();
        $this->info('Migration summary:');
        $this->line('  Migrated: ' . (empty($migrated) ? 'None' : implode(', ', $migrated)));
        if (!empty($skipped)) {
            $this->line('  Skipped:  ' . implode(', ', $skipped));
        }
        if (!empty($failed)) {
            $this->error('  Failed:   ' . implode(', ', $failed));
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
